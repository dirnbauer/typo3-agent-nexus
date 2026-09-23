<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRedactor;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutRecord;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutService;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutStorage;
use Webconsulting\AgentNexus\Ucp\Checkout\Money;
use Webconsulting\AgentNexus\Ucp\Http\JsonResponses;
use Webconsulting\AgentNexus\Ucp\Http\UcpAgentHeader;
use Webconsulting\AgentNexus\Ucp\Payment\SandboxPaymentHandler;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * The shopping agent: a UCP platform that shops on a visitor's behalf and
 * reports to the visitor over AG-UI.
 *
 * First run — propose:
 *
 *     RUN_STARTED
 *     ucp.discover          GET  /.well-known/ucp               the business profile
 *     ucp.create_checkout   POST {endpoint}/checkout-sessions    priced by the store
 *     reasoning             why these products (scripted, or a model within the guards)
 *     ucp.complete_checkout proposed, not sent: arguments only
 *     STATE_SNAPSHOT        the priced checkout
 *     RUN_FINISHED          outcome interrupt — "Approve this order"
 *
 * Second run — the answer arrives in RunAgentInput.resume:
 *
 *     approved   (email given? ucp.update_checkout first) → the proposed complete
 *                request is sent exactly as shown, and its result closes the
 *                proposed tool call → RUN_FINISHED success
 *     declined   the proposed call is closed as not sent → ucp.cancel_checkout
 *                → RUN_FINISHED success
 *
 * Every UCP call is a tool call in the stream (arguments = the request,
 * result = the response) and goes to the business in-process through
 * {@see UcpClient}. The approval is verified against what this agent stored
 * on the checkout: an answer that names no open approval of this thread, or
 * answers one a second time differently, is refused before anything happens.
 * Nothing is completed without an explicit `approved: true`.
 */
final readonly class ShoppingAgent
{
    public const string TOOL_DISCOVER = 'ucp.discover';
    public const string TOOL_CREATE = 'ucp.create_checkout';
    public const string TOOL_UPDATE = 'ucp.update_checkout';
    public const string TOOL_COMPLETE = 'ucp.complete_checkout';
    public const string TOOL_CANCEL = 'ucp.cancel_checkout';

    /** A CUSTOM event saying who wrote the explanation: "Live model" or "Scripted demo". */
    public const string PROVENANCE_EVENT = 'agentnexus.provenance';

    /** Where the pending approval sits on the checkout's private member. */
    public const string META_APPROVAL = 'approval';

    private const string KEY_NAMESPACE = '6ba7b811-9dad-11d1-80b4-00c04fd430c8';

    public function __construct(
        private UcpClient $client,
        private CheckoutStorage $storage,
        private Merchant $merchant,
        private Rationale $rationale,
        private SandboxPaymentHandler $payments,
        private RouteRegistry $routes,
        private TrafficRedactor $redactor,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function run(AgentInput $input, AgentSession $session): \Generator
    {
        try {
            if ($input->resume !== []) {
                [$object, $approval, $entry] = $this->approvalFor($input, new \DateTimeImmutable());
                $session->capture?->correlate($approval->checkoutId);
                yield AgUi::runStarted($input->threadId, $input->runId, $input->parentRunId);
                yield from $entry->approves()
                    ? $this->approve($input, $session, $object, $approval, $entry)
                    : $this->decline($input, $session, $object, $approval);
                return;
            }
            $this->refuseWhileWaiting($input);
            yield AgUi::runStarted($input->threadId, $input->runId, $input->parentRunId);
            yield from $this->propose($input, $session);
        } catch (RunRefused $e) {
            yield AgUi::runError($e->getMessage(), $e->errorCode);
        } catch (\Throwable $e) {
            $this->logger->error('The UCP shopping agent failed.', ['exception' => $e]);
            $session->capture?->fail($e->getMessage());
            yield AgUi::runError('The shopping agent stopped because of an error. The error has been logged.', 'agent_failed');
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function propose(AgentInput $input, AgentSession $session): \Generator
    {
        yield AgUi::stepStarted('discover');
        $discovery = yield from $this->tool($session, self::TOOL_DISCOVER, new UcpCall($this->profileRoute(), 'discovery'));
        yield AgUi::stepFinished('discover');
        $endpoint = $this->restEndpoint($discovery->response);
        if ($discovery->status !== 200 || $endpoint === '') {
            throw new RunRefused('The store profile could not be read.', 'discovery_failed');
        }
        yield from AgUi::textMessage('I read the store\'s UCP profile. It offers checkout over REST and a sandbox payment handler.');

        $lineItems = array_map(
            static fn(string $productId): array => ['item' => ['id' => $productId], 'quantity' => 1],
            $input->intent->productIds(),
        );
        $body = ['line_items' => $lineItems];
        if ($input->email !== '') {
            $body['buyer'] = ['email' => $input->email];
        }
        yield AgUi::stepStarted('create_checkout');
        $created = yield from $this->tool($session, self::TOOL_CREATE, new UcpCall(
            'ucp.checkout.create',
            'create_checkout',
            [],
            $this->headers($session, Uuid::v4()->toRfc4122()),
            $body,
        ));
        yield AgUi::stepFinished('create_checkout');
        $checkout = $created->checkout();
        if ($created->status !== 201 || $checkout === []) {
            throw new RunRefused('The store did not open a checkout: ' . $created->firstError(), 'checkout_failed');
        }
        $checkoutId = $this->string($checkout['id'] ?? '');
        $session->capture?->correlate($checkoutId);
        $total = $this->total($checkout);
        yield from AgUi::textMessage(sprintf('I opened a checkout for %s. The total is %s.', $this->titles($checkout), $total));

        $explanation = $this->rationale->explain(
            $input->intent,
            $this->facts($checkout),
            $total,
            $input->lastUserText(),
            $session->modelAllowed,
            $session->request,
        );
        yield from AgUi::reasoning($explanation['text']);
        yield AgUi::custom(
            self::PROVENANCE_EVENT,
            ['mode' => $explanation['mode'], 'label' => $explanation['label']] + (isset($explanation['reason']) ? ['reason' => $explanation['reason']] : []),
        );

        $approval = new PendingApproval(
            AgUi::id('int'),
            AgUi::id('tc'),
            $input->threadId,
            $input->runId,
            $checkoutId,
            [
                'method' => 'POST',
                'path' => $this->path('ucp.checkout.complete', $checkoutId),
                'headers' => $this->headers($session, Uuid::v4()->toRfc4122()),
                'body' => ['payment' => ['instruments' => [$this->payments->instrument(AgUi::id('instr'), $input->declinePayment)]]],
            ],
            CheckoutService::total($checkout),
            $this->string($checkout['currency'] ?? Merchant::CURRENCY),
            $this->string($checkout['expires_at'] ?? ''),
            Uuid::v4()->toRfc4122(),
        );
        // Stored before it is announced: the answer can only be checked
        // against what this agent kept.
        $this->saveApproval($approval);

        yield AgUi::stepStarted('approval');
        yield AgUi::toolCallStart($approval->toolCallId, self::TOOL_COMPLETE);
        yield AgUi::toolCallArgs($approval->toolCallId, $this->json($approval->request, $session));
        yield AgUi::toolCallEnd($approval->toolCallId);
        yield AgUi::stepFinished('approval');
        yield from AgUi::textMessage($this->status($checkout) === Spec::STATUS_READY
            ? 'Approve the order and I will complete the checkout. This is a sandbox, so nothing is charged.'
            : 'The store needs an email address for the licence. Add yours and approve the order, and I will complete the checkout.');
        yield AgUi::stateSnapshot(['checkout' => $checkout]);
        yield AgUi::runFinished(
            $input->threadId,
            $input->runId,
            AgUi::interrupt([$this->interrupt($approval, $checkout)]),
            null,
            $explanation['usage'],
        );
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function approve(AgentInput $input, AgentSession $session, ProtocolObject $object, PendingApproval $approval, ResumeEntry $entry): \Generator
    {
        $checkout = CheckoutRecord::checkout($object);
        yield AgUi::stepStarted('complete_checkout');

        if ($approval->isOpen()) {
            $email = $entry->email();
            if ($email !== '' && $email !== $this->buyerEmail($checkout) && !Spec::isTerminal($this->status($checkout))) {
                $updated = yield from $this->tool($session, self::TOOL_UPDATE, new UcpCall(
                    'ucp.checkout.update',
                    'update_checkout',
                    ['id' => $approval->checkoutId],
                    // One key per address: answering again with the same address replays.
                    $this->headers($session, Uuid::v5(Uuid::fromString(self::KEY_NAMESPACE), $approval->interruptId . '|' . $email)->toRfc4122()),
                    $this->replacement($checkout, $email),
                ));
                if ($updated->checkout() !== []) {
                    $checkout = $updated->checkout();
                }
            }
            if ($this->status($checkout) !== Spec::STATUS_READY) {
                yield AgUi::stepFinished('complete_checkout');
                yield from AgUi::textMessage('I still need a valid email address before I can complete the checkout.');
                yield AgUi::stateSnapshot(['checkout' => $checkout]);
                yield AgUi::runFinished($input->threadId, $input->runId, AgUi::interrupt([$this->interrupt($approval, $checkout)]));
                return;
            }
            if (CheckoutService::total($checkout) !== $approval->amount) {
                yield AgUi::stepFinished('complete_checkout');
                throw new RunRefused('The price changed after you approved it, so no order was placed. Start the agent again.', 'price_changed');
            }
        }

        // Exactly the request the visitor approved, Idempotency-Key included.
        $completed = $this->client->call($session, new UcpCall(
            'ucp.checkout.complete',
            'complete_checkout',
            ['id' => $approval->checkoutId],
            $approval->request['headers'],
            $approval->request['body'],
        ));
        yield AgUi::toolCallResult($approval->toolCallId, $this->json($completed->result(), $session));
        $this->resolve($approval, PendingApproval::APPROVED);
        yield AgUi::stepFinished('complete_checkout');

        $final = $completed->checkout() !== [] ? $completed->checkout() : $checkout;
        $order = is_array($final['order'] ?? null) ? $final['order'] : [];
        if ($this->status($final) === Spec::STATUS_COMPLETED) {
            yield from AgUi::textMessage(sprintf(
                'Done. %s is confirmed. No payment was taken, because this is a sandbox store.',
                $this->string($order['label'] ?? 'Your order'),
            ));
        } else {
            yield from AgUi::textMessage('The store did not place the order: ' . $completed->firstError());
        }
        yield AgUi::stateSnapshot(['checkout' => $final]);
        yield AgUi::runFinished($input->threadId, $input->runId, AgUi::success(), array_filter([
            'checkoutId' => $approval->checkoutId,
            'status' => $this->status($final),
            'orderId' => $this->string($order['id'] ?? ''),
        ]));
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function decline(AgentInput $input, AgentSession $session, ProtocolObject $object, PendingApproval $approval): \Generator
    {
        $checkout = CheckoutRecord::checkout($object);
        yield AgUi::stepStarted('cancel_checkout');
        // The proposed call is answered, so no tool call is left dangling.
        yield AgUi::toolCallResult($approval->toolCallId, $this->json([
            'sent' => false,
            'reason' => 'The visitor did not approve the order, so the request was not sent.',
        ], $session));

        if (!Spec::isTerminal($this->status($checkout)) || $approval->resolution === PendingApproval::DECLINED) {
            $canceled = yield from $this->tool($session, self::TOOL_CANCEL, new UcpCall(
                'ucp.checkout.cancel',
                'cancel_checkout',
                ['id' => $approval->checkoutId],
                $this->headers($session, $approval->cancelKey),
            ));
            if ($canceled->checkout() !== []) {
                $checkout = $canceled->checkout();
            }
        }
        $this->resolve($approval, PendingApproval::DECLINED);
        yield AgUi::stepFinished('cancel_checkout');
        yield from AgUi::textMessage('I canceled the checkout. Nothing was ordered.');
        yield AgUi::stateSnapshot(['checkout' => $checkout]);
        yield AgUi::runFinished($input->threadId, $input->runId, AgUi::success(), [
            'checkoutId' => $approval->checkoutId,
            'status' => $this->status($checkout),
        ]);
    }

    /**
     * One UCP call as an AG-UI tool call: start, arguments, end, result.
     *
     * @return \Generator<int, array<string, mixed>, mixed, UcpExchange>
     */
    private function tool(AgentSession $session, string $name, UcpCall $call): \Generator
    {
        $toolCallId = AgUi::id('tc');
        yield AgUi::toolCallStart($toolCallId, $name);
        $request = [
            'method' => $this->routes->get($call->routeId)?->methods[0] ?? 'GET',
            'path' => $this->path($call->routeId, $call->parameters['id'] ?? ''),
            'headers' => $call->headers === [] ? new \stdClass() : $call->headers,
        ];
        if ($call->body !== null) {
            $request['body'] = $call->body;
        }
        yield AgUi::toolCallArgs($toolCallId, $this->json($request, $session));
        yield AgUi::toolCallEnd($toolCallId);
        $exchange = $this->client->call($session, $call);
        yield AgUi::toolCallResult($toolCallId, $this->json($exchange->result(), $session));
        return $exchange;
    }

    /**
     * The approval this resume answers, verified against what was stored.
     *
     * @return array{0: ProtocolObject, 1: PendingApproval, 2: ResumeEntry}
     * @throws RunRefused
     */
    private function approvalFor(AgentInput $input, \DateTimeImmutable $now): array
    {
        foreach ($this->storage->forContext($input->threadId) as $object) {
            $approval = $this->approvalOf($object);
            if ($approval === null || $approval->threadId !== $input->threadId) {
                continue;
            }
            foreach ($input->resume as $entry) {
                if (!hash_equals($approval->interruptId, $entry->interruptId)) {
                    continue;
                }
                if (!$approval->isOpen() && $approval->resolution !== $entry->decision()) {
                    throw new RunRefused('This order was already answered. Start the agent again for a new one.', 'interrupt_answered');
                }
                if ($approval->isOpen() && $approval->isExpired($now)) {
                    throw new RunRefused('This approval has expired. Start the agent again.', 'interrupt_expired');
                }
                return [$object, $approval, $entry];
            }
        }
        throw new RunRefused('No approval of this conversation matches the answer, so nothing was done.', 'unknown_interrupt');
    }

    /**
     * AG-UI: while a thread has an open interrupt, every new input must answer it.
     *
     * @throws RunRefused
     */
    private function refuseWhileWaiting(AgentInput $input): void
    {
        $now = new \DateTimeImmutable();
        foreach ($this->storage->forContext($input->threadId) as $object) {
            $approval = $this->approvalOf($object);
            if ($approval !== null && $approval->isOpen() && !$approval->isExpired($now)
                && !Spec::isTerminal($this->status(CheckoutRecord::checkout($object)))) {
                throw new RunRefused('This conversation is waiting for your answer to the order. Answer it, or start a new conversation.', 'interrupt_pending');
            }
        }
    }

    /**
     * The interrupt that stops the run for the visitor's answer.
     *
     * @param array<string, mixed> $checkout
     * @return array<string, mixed>
     */
    private function interrupt(PendingApproval $approval, array $checkout): array
    {
        $needsEmail = $this->status($checkout) !== Spec::STATUS_READY;
        $schema = [
            'type' => 'object',
            'properties' => [
                'approved' => ['type' => 'boolean', 'description' => 'true completes the checkout, false cancels it.'],
                'email' => ['type' => 'string', 'format' => 'email', 'description' => 'Where the licence is sent.'],
            ],
            'required' => ['approved'],
        ];
        if ($needsEmail) {
            $schema['if'] = ['properties' => ['approved' => ['const' => true]]];
            $schema['then'] = ['required' => ['email']];
        }
        $interrupt = [
            'id' => $approval->interruptId,
            'reason' => 'confirmation',
            'message' => sprintf('Approve this order: %s for %s. This is a sandbox, so nothing is charged.', $this->titles($checkout), $this->total($checkout)),
            'toolCallId' => $approval->toolCallId,
            'responseSchema' => $schema,
        ];
        if ($approval->expiresAt !== '') {
            $interrupt['expiresAt'] = $approval->expiresAt;
        }
        $interrupt['metadata'] = [
            'checkoutId' => $approval->checkoutId,
            'amount' => $approval->amount,
            'currency' => $approval->currency,
            'needsEmail' => $needsEmail,
        ];
        return $interrupt;
    }

    private function saveApproval(PendingApproval $approval): void
    {
        $object = $this->storage->find($approval->checkoutId)
            ?? throw new \RuntimeException('The checkout to approve is gone.', 1758700802);
        $meta = CheckoutRecord::meta($object);
        $meta[self::META_APPROVAL] = $approval->toArray();
        $this->storage->save($object->withPayload(CheckoutRecord::payload(CheckoutRecord::checkout($object), $meta)));
    }

    /** Record the answer once; a replay of the same answer changes nothing. */
    private function resolve(PendingApproval $approval, string $resolution): void
    {
        if (!$approval->isOpen()) {
            return;
        }
        $this->saveApproval($approval->resolved($resolution, time()));
    }

    private function approvalOf(ProtocolObject $object): ?PendingApproval
    {
        $data = CheckoutRecord::meta($object)[self::META_APPROVAL] ?? null;
        return is_array($data) ? PendingApproval::fromArray($data) : null;
    }

    /**
     * The update that adds the email address: the full session as it is,
     * with the buyer's email set — PUT replaces everything.
     *
     * @param array<string, mixed> $checkout
     * @return array<string, mixed>
     */
    private function replacement(array $checkout, string $email): array
    {
        $lines = [];
        foreach (is_array($checkout['line_items'] ?? null) ? $checkout['line_items'] : [] as $line) {
            if (!is_array($line) || !is_array($line['item'] ?? null)) {
                continue;
            }
            $lines[] = ['id' => $line['id'] ?? '', 'item' => ['id' => $line['item']['id'] ?? ''], 'quantity' => $line['quantity'] ?? 1];
        }
        $buyer = is_array($checkout['buyer'] ?? null) ? $checkout['buyer'] : [];
        $buyer['email'] = $email;
        $update = ['line_items' => $lines, 'buyer' => $buyer];
        foreach (['context', 'attribution'] as $member) {
            if (is_array($checkout[$member] ?? null) && $checkout[$member] !== []) {
                $update[$member] = $checkout[$member];
            }
        }
        return $update;
    }

    /**
     * What a model may be told about the products: all of it already text.
     *
     * @param array<string, mixed> $checkout
     * @return list<array{title: string, price: string, billing: string, description: string}>
     */
    private function facts(array $checkout): array
    {
        $facts = [];
        foreach ($this->lines($checkout) as $line) {
            $product = $this->merchant->product($line['id']);
            if ($product === []) {
                continue;
            }
            $facts[] = [
                'title' => $product['name'],
                'price' => Money::format($product['price'], Merchant::CURRENCY),
                'billing' => $product['unit'] === '/mo' ? 'per month' : 'one-off payment',
                'description' => $product['description'],
            ];
        }
        return $facts;
    }

    /**
     * @param array<string, mixed> $checkout
     * @return list<array{id: string, title: string}>
     */
    private function lines(array $checkout): array
    {
        $lines = [];
        foreach (is_array($checkout['line_items'] ?? null) ? $checkout['line_items'] : [] as $line) {
            $item = is_array($line) && is_array($line['item'] ?? null) ? $line['item'] : [];
            $lines[] = ['id' => $this->string($item['id'] ?? ''), 'title' => $this->string($item['title'] ?? '')];
        }
        return $lines;
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function titles(array $checkout): string
    {
        $titles = array_values(array_filter(array_column($this->lines($checkout), 'title')));
        return match (count($titles)) {
            0 => 'your order',
            1 => $titles[0],
            default => implode(', ', array_slice($titles, 0, -1)) . ' and ' . $titles[count($titles) - 1],
        };
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function total(array $checkout): string
    {
        return Money::format(CheckoutService::total($checkout), $this->string($checkout['currency'] ?? Merchant::CURRENCY));
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function status(array $checkout): string
    {
        return $this->string($checkout['status'] ?? '');
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function buyerEmail(array $checkout): string
    {
        return is_array($checkout['buyer'] ?? null) ? $this->string($checkout['buyer']['email'] ?? '') : '';
    }

    /**
     * The REST endpoint a business profile publishes for dev.ucp.shopping.
     *
     * @param array<string, mixed> $profile
     */
    private function restEndpoint(array $profile): string
    {
        $services = is_array($profile['ucp'] ?? null) && is_array($profile['ucp']['services'] ?? null) ? $profile['ucp']['services'] : [];
        foreach (is_array($services[Spec::SERVICE_SHOPPING] ?? null) ? $services[Spec::SERVICE_SHOPPING] : [] as $service) {
            if (is_array($service) && ($service['transport'] ?? null) === 'rest' && is_string($service['endpoint'] ?? null)) {
                return $service['endpoint'];
            }
        }
        return '';
    }

    /** The well-known profile when the installation publishes it, the API copy otherwise. */
    private function profileRoute(): string
    {
        return $this->routes->get('ucp.profile.wellknown') !== null ? 'ucp.profile.wellknown' : 'ucp.profile';
    }

    private function path(string $routeId, string $checkoutId): string
    {
        $route = $this->routes->get($routeId);
        if ($route === null) {
            return '';
        }
        return $route->uri($this->routes->apiBasePath(), $checkoutId !== '' ? ['id' => $checkoutId] : []);
    }

    /**
     * The headers every platform request carries.
     *
     * @return array<string, string>
     */
    private function headers(AgentSession $session, string $idempotencyKey = ''): array
    {
        $headers = [
            'UCP-Agent' => UcpAgentHeader::forProfile($session->platformProfileUrl),
            'Request-Id' => Uuid::v4()->toRfc4122(),
        ];
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        return $headers;
    }

    /**
     * A request or response as tool-call text, with personal data masked the
     * way the traffic log masks it.
     *
     * @param array<array-key, mixed> $data
     */
    private function json(array $data, AgentSession $session): string
    {
        return JsonResponses::encode($this->redactor->data($data, $session->redactPersonalData));
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
