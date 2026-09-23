<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteMatch;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutRecord;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutResult;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutService;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutStorage;
use Webconsulting\AgentNexus\Ucp\Checkout\IdempotencyRecord;
use Webconsulting\AgentNexus\Ucp\Checkout\IdempotencyStore;
use Webconsulting\AgentNexus\Ucp\Checkout\IdempotencyUnavailable;
use Webconsulting\AgentNexus\Ucp\Checkout\InvalidRequest;
use Webconsulting\AgentNexus\Ucp\Checkout\Messages;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * The REST binding of the checkout capability:
 *
 *     POST   {endpoint}/checkout-sessions                create_checkout   201
 *     GET    {endpoint}/checkout-sessions/{id}           get_checkout      200
 *     PUT    {endpoint}/checkout-sessions/{id}           update_checkout   200
 *     POST   {endpoint}/checkout-sessions/{id}/complete  complete_checkout 200
 *     POST   {endpoint}/checkout-sessions/{id}/cancel    cancel_checkout   200
 *
 * Every request needs `UCP-Agent` and a UUID `Request-Id` (echoed back);
 * every change also needs a UUID `Idempotency-Key`. A key seen before with
 * the same body replays the stored response; with another body it is a 409.
 *
 * Status codes: business outcomes are 200/201 with UCP messages. Protocol
 * errors carry `{code, content}`: 400 (headers, unreadable body), 409 (key
 * reuse), 422 (UCP version), 429 (rate limit), 503 (idempotency storage).
 * An unknown session is a 404 and a change to a completed or canceled one a
 * 409, both with an error_response body.
 *
 * The shopping agent calls the same methods in-process
 * ({@see \Webconsulting\AgentNexus\Ucp\Agent\UcpClient}), so what a visitor's
 * agent does and what any other platform does over HTTP is one code path.
 */
#[Autoconfigure(public: true)]
final readonly class CheckoutEndpoint
{
    public const string RATE_BUCKET = 'ucp';
    public const int RATE_LIMIT = 30;
    public const int RATE_WINDOW = 600;

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const string CHECKOUT_ID = '/^chk_[0-9a-f]{24}$/';

    public function __construct(
        private CheckoutService $checkouts,
        private CheckoutStorage $storage,
        private IdempotencyStore $idempotency,
        private RateLimiter $rateLimiter,
        private RouteRegistry $routes,
        private LoggerInterface $logger,
    ) {}

    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle(Operation::Create, $request);
    }

    public function get(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle(Operation::Get, $request);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle(Operation::Update, $request);
    }

    public function complete(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle(Operation::Complete, $request);
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle(Operation::Cancel, $request);
    }

    private function handle(Operation $operation, ServerRequestInterface $request): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        if (!$match instanceof RouteMatch) {
            throw new \LogicException('The UCP checkout binding is served through the API router only.', 1758700501);
        }
        $caller = $request->getAttribute(InProcessCaller::ATTRIBUTE);
        $caller = $caller instanceof InProcessCaller ? $caller : null;
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        $capture = $capture instanceof TrafficCapture ? $capture : null;
        $capture?->describe($operation->value);

        $requestId = trim($request->getHeaderLine('Request-Id'));
        $headers = preg_match(self::UUID, $requestId) === 1 ? ['Request-Id' => $requestId] : [];
        $headers['Cache-Control'] = 'no-store';

        if ($caller === null && !$this->rateLimiter->passes($request, self::RATE_BUCKET, self::RATE_LIMIT, self::RATE_WINDOW)) {
            return JsonResponses::problem(429, 'rate_limited', 'Too many requests. Try again in a few minutes.', $headers + ['Retry-After' => (string)self::RATE_WINDOW]);
        }
        try {
            $agent = UcpAgentHeader::fromRequest($request);
        } catch (InvalidUcpAgent $e) {
            return JsonResponses::problem(400, 'invalid_profile_url', $e->getMessage(), $headers);
        }
        if ($agent->version !== '' && $agent->version !== Spec::VERSION) {
            return JsonResponses::problem(422, 'version_unsupported', sprintf('This business implements UCP %s only.', Spec::VERSION), $headers);
        }
        if (!isset($headers['Request-Id'])) {
            return JsonResponses::problem(400, 'invalid_request', 'Send a Request-Id header with a UUID.', $headers);
        }
        $idempotencyKey = '';
        if ($operation->isMutation()) {
            $idempotencyKey = trim($request->getHeaderLine('Idempotency-Key'));
            if (preg_match(self::UUID, $idempotencyKey) !== 1) {
                return JsonResponses::problem(400, 'invalid_request', 'Send an Idempotency-Key header with a UUID.', $headers);
            }
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $body = (string)$request->getBody();
        $path = $request->getUri()->getPath();
        $bodyHash = hash('sha256', $body);

        try {
            if ($idempotencyKey !== '') {
                $record = $this->idempotency->find($agent->profile, $idempotencyKey);
                if ($record !== null) {
                    if (!$record->matches($operation->value, $path, $bodyHash)) {
                        return JsonResponses::problem(409, 'idempotency_conflict', 'This Idempotency-Key was already used for a different request.', $headers);
                    }
                    $capture?->correlate($this->checkoutIdOf($record->body, $match->parameter('id')));
                    return JsonResponses::raw($record->status, $record->body, $headers + ($record->location !== '' ? ['Location' => $record->location] : []));
                }
            }

            try {
                [$result, $checkoutId, $location] = $this->execute($operation, $match, $body, $caller);
            } catch (InvalidRequest $e) {
                return JsonResponses::problem(400, 'invalid_request', $e->getMessage(), $headers);
            }
            $capture?->correlate($checkoutId);
            $json = JsonResponses::encode($result->body);

            if ($idempotencyKey !== '') {
                $this->idempotency->remember($agent->profile, $idempotencyKey, new IdempotencyRecord(
                    $operation->value,
                    $path,
                    $bodyHash,
                    $result->status,
                    $json,
                    $location,
                    time(),
                ));
            }
        } catch (IdempotencyUnavailable $e) {
            $this->logger->error('UCP idempotency storage failed.', ['exception' => $e]);
            $capture?->fail($e->getMessage());
            return JsonResponses::problem(503, 'idempotency_unavailable', 'Idempotency records are unavailable, so nothing was changed. Try again shortly.', $headers + ['Retry-After' => '30']);
        } catch (\Throwable $e) {
            $this->logger->error('UCP operation {operation} failed.', ['operation' => $operation->value, 'exception' => $e]);
            $capture?->fail($e->getMessage());
            return JsonResponses::problem(500, 'internal_error', 'The checkout could not be processed. The error has been logged.', $headers);
        }

        return JsonResponses::raw($result->status, $json, $headers + ($location !== '' ? ['Location' => $location] : []));
    }

    /**
     * @return array{0: CheckoutResult, 1: string, 2: string} the result, the checkout id, the Location of a new session
     * @throws InvalidRequest
     */
    private function execute(Operation $operation, RouteMatch $match, string $body, ?InProcessCaller $caller): array
    {
        $now = new \DateTimeImmutable();

        if ($operation === Operation::Create) {
            $checkoutId = 'chk_' . bin2hex(random_bytes(12));
            $result = $this->checkouts->create($this->decode($body), $checkoutId, $now);
            if ($result->checkout === null) {
                return [$result, '', ''];
            }
            $object = new ProtocolObject(
                ObjectKind::Checkout,
                $checkoutId,
                $caller->contextId ?? '',
                '',
                ($caller->channel ?? Channel::Api)->value,
                CheckoutService::label($result->checkout),
                CheckoutRecord::payload($result->checkout, []),
                [],
                $caller->pid ?? 0,
            );
            $this->store($object, $result);
            return [$result, $checkoutId, $this->routes->url(Operation::Get->routeId(), $match->origin, ['id' => $checkoutId])];
        }

        $checkoutId = $match->parameter('id');
        $object = preg_match(self::CHECKOUT_ID, $checkoutId) === 1 ? $this->storage->find($checkoutId) : null;
        if ($object === null) {
            return [CheckoutResult::failure(404, [
                Messages::error('not_found', 'There is no checkout session with this id.', Messages::SEVERITY_UNRECOVERABLE),
            ]), '', ''];
        }
        $checkout = CheckoutRecord::checkout($object);

        $result = match ($operation) {
            Operation::Get => CheckoutResult::unchanged($checkout),
            Operation::Update => $this->checkouts->update($checkout, $this->decode($body), $now),
            Operation::Complete => $this->checkouts->complete(
                $checkout,
                $this->decode($body),
                $now,
                'ord_' . substr($checkoutId, 4),
                $this->routes->url(Operation::Get->routeId(), $match->origin, ['id' => $checkoutId]),
            ),
            Operation::Cancel => $this->checkouts->cancel($checkout),
        };
        $this->store($object, $result);

        return [$result, $checkoutId, ''];
    }

    /**
     * Write a changed session back, keeping its private member and recording
     * every state it passed through.
     */
    private function store(ProtocolObject $object, CheckoutResult $result): void
    {
        if ($result->checkout === null) {
            return;
        }
        foreach ($result->transitions as $transition) {
            $object = $object->withState($transition['state'], $transition['note']);
        }
        $this->storage->save(
            $object
                ->withPayload(CheckoutRecord::payload($result->checkout, CheckoutRecord::meta($object)))
                ->withLabel(CheckoutService::label($result->checkout)),
        );
    }

    /**
     * @return array<string, mixed>
     * @throws InvalidRequest
     */
    private function decode(string $body): array
    {
        try {
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidRequest('The request body must be a JSON object.', 1758700502);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InvalidRequest('The request body must be a JSON object.', 1758700503);
        }
        $object = [];
        foreach ($data as $key => $value) {
            $object[(string)$key] = $value;
        }
        return $object;
    }

    /** The checkout a replayed response was about, for the traffic log. */
    private function checkoutIdOf(string $json, string $fallback): string
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) && is_string($decoded['id'] ?? null) ? $decoded['id'] : $fallback;
    }
}
