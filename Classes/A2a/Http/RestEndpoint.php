<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Webconsulting\AgentNexus\A2a\Protocol\A2aError;
use Webconsulting\AgentNexus\A2a\Protocol\A2aException;
use Webconsulting\AgentNexus\A2a\Protocol\Json;
use Webconsulting\AgentNexus\A2a\Protocol\ListTasksParams;
use Webconsulting\AgentNexus\A2a\Protocol\Operation;
use Webconsulting\AgentNexus\A2a\Protocol\ProtocolVersion;
use Webconsulting\AgentNexus\A2a\Protocol\SendMessageParams;
use Webconsulting\AgentNexus\A2a\Protocol\TaskIdParams;
use Webconsulting\AgentNexus\A2a\Server\A2aServer;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteMatch;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;

/**
 * The HTTP+JSON binding, below {apiBase}/a2a/rest (specification section 11).
 *
 * It speaks A2A 1.0 only: a request without `A2A-Version: 1.0` (header or
 * query parameter) is read as 0.3 and answered with VersionNotSupportedError.
 * Bodies are the 1.0 JSON objects, answered as `application/a2a+json`; errors
 * are a google.rpc.Status under `error` with the HTTP status of section 5.4
 * and a google.rpc.ErrorInfo for every A2A error. Streams send bare
 * StreamResponse objects, without a JSON-RPC envelope.
 */
#[Autoconfigure(public: true)]
final readonly class RestEndpoint
{
    public const string CONTENT_TYPE = 'application/a2a+json';

    private const int MAX_BODY_BYTES = 262144;

    public function __construct(
        private A2aServer $server,
        private CallContextFactory $contexts,
        private RateLimiter $rateLimiter,
        private LoggerInterface $logger,
    ) {}

    /** POST /message:send */
    public function send(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, function () use ($request): array {
            $body = $this->body($request);
            return $this->server->sendMessage(SendMessageParams::fromArray($body), $this->contexts->create($request, $this->messageMetadata($body)));
        });
    }

    /** POST /message:stream */
    public function stream(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respondWithStream($request, function () use ($request): \Generator {
            $body = $this->body($request);
            return $this->server->sendStreamingMessage(SendMessageParams::fromArray($body), $this->contexts->create($request, $this->messageMetadata($body)));
        });
    }

    /** GET /tasks */
    public function list(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, fn(): array => $this->server->listTasks(ListTasksParams::fromArray($this->query($request))));
    }

    /** GET /tasks/{id} */
    public function get(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, fn(): array => $this->server->getTask(
            TaskIdParams::fromArray(['id' => $this->taskId($request)] + $this->query($request)),
            $this->contexts->create($request),
        ));
    }

    /** POST /tasks/{id}:cancel */
    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, fn(): array => $this->server->cancelTask(
            TaskIdParams::fromArray(['id' => $this->taskId($request)] + $this->body($request)),
            $this->contexts->create($request),
        ));
    }

    /** GET|POST /tasks/{id}:subscribe */
    public function subscribe(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respondWithStream($request, function () use ($request): \Generator {
            return $this->server->subscribeToTask(
                TaskIdParams::fromArray(['id' => $this->taskId($request)]),
                $this->contexts->create($request),
            );
        });
    }

    /** POST|GET /tasks/{id}/pushNotificationConfigs */
    public function pushConfigs(ServerRequestInterface $request): ResponseInterface
    {
        $this->describe($request, strtoupper($request->getMethod()) === 'POST'
            ? Operation::CreateTaskPushNotificationConfig
            : Operation::ListTaskPushNotificationConfigs);
        return $this->respond($request, fn(): never => $this->server->pushNotificationConfig());
    }

    /** GET|DELETE /tasks/{id}/pushNotificationConfigs/{configId} */
    public function pushConfig(ServerRequestInterface $request): ResponseInterface
    {
        $this->describe($request, strtoupper($request->getMethod()) === 'DELETE'
            ? Operation::DeleteTaskPushNotificationConfig
            : Operation::GetTaskPushNotificationConfig);
        return $this->respond($request, fn(): never => $this->server->pushNotificationConfig());
    }

    /** GET /extendedAgentCard */
    public function extendedCard(ServerRequestInterface $request): ResponseInterface
    {
        return $this->respond($request, fn(): never => $this->server->extendedAgentCard());
    }

    /**
     * @param \Closure(): array<string, mixed> $operation
     */
    private function respond(ServerRequestInterface $request, \Closure $operation): ResponseInterface
    {
        try {
            $this->admit($request);
            return $this->json($operation());
        } catch (A2aException $exception) {
            return $this->error($exception);
        } catch (\Throwable $exception) {
            return $this->failure($request, $exception);
        }
    }

    /**
     * @param \Closure(): \Generator<int, array<string, mixed>, mixed, void> $operation
     */
    private function respondWithStream(ServerRequestInterface $request, \Closure $operation): ResponseInterface
    {
        try {
            $this->admit($request);
            $frames = $operation();
            // Run up to the first frame, so a refused request is answered
            // with an error body rather than a stream.
            $frames->current();
        } catch (A2aException $exception) {
            return $this->error($exception);
        } catch (\Throwable $exception) {
            return $this->failure($request, $exception);
        }

        $events = (function () use ($frames): \Generator {
            try {
                while ($frames->valid()) {
                    yield $frames->current();
                    $frames->next();
                }
            } catch (A2aException $exception) {
                yield $exception->toRestError();
            } catch (\Throwable $exception) {
                $this->logger->error('An A2A stream failed.', ['exception' => $exception]);
                yield (new A2aException(A2aError::Internal, 'The agent failed. The error has been logged.'))->toRestError();
            }
        })();

        return Responses::stream($events)->withHeader(ProtocolVersion::HEADER, ProtocolVersion::V1_0->value);
    }

    /** A 1.0 client within its rate limit — or an error saying which it is not. */
    private function admit(ServerRequestInterface $request): void
    {
        $requested = Responses::requestedVersion($request);
        if (ProtocolVersion::negotiate($requested) !== ProtocolVersion::V1_0) {
            throw ProtocolVersion::unsupported($requested, ProtocolVersion::V1_0);
        }
        if (!$this->rateLimiter->passes($request, 'a2a', JsonRpcEndpoint::REQUEST_LIMIT, JsonRpcEndpoint::REQUEST_WINDOW)) {
            throw new A2aException(
                A2aError::RateLimited,
                sprintf('Too many requests from this address. Try again in %d minutes.', intdiv(JsonRpcEndpoint::REQUEST_WINDOW, 60)),
                ['limit' => (string)JsonRpcEndpoint::REQUEST_LIMIT, 'windowSeconds' => (string)JsonRpcEndpoint::REQUEST_WINDOW],
            );
        }
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function json(array $payload): ResponseInterface
    {
        return Responses::json($payload, 200, self::CONTENT_TYPE)
            ->withHeader(ProtocolVersion::HEADER, ProtocolVersion::V1_0->value);
    }

    private function error(A2aException $exception): ResponseInterface
    {
        $headers = $exception->error === A2aError::RateLimited ? ['Retry-After' => (string)JsonRpcEndpoint::REQUEST_WINDOW] : [];
        return Responses::json($exception->toRestError(), $exception->error->httpStatus(), self::CONTENT_TYPE, $headers)
            ->withHeader(ProtocolVersion::HEADER, ProtocolVersion::V1_0->value);
    }

    private function failure(ServerRequestInterface $request, \Throwable $exception): ResponseInterface
    {
        $this->logger->error('A2A HTTP+JSON call {path} failed.', ['path' => $request->getUri()->getPath(), 'exception' => $exception]);
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        if ($capture instanceof TrafficCapture) {
            $capture->fail($exception->getMessage());
        }
        return $this->error(new A2aException(A2aError::Internal, 'The agent failed. The error has been logged.'));
    }

    /**
     * The JSON body; an empty body is an empty object.
     *
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $raw = (string)$request->getBody();
        if (trim($raw) === '') {
            return [];
        }
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new A2aException(A2aError::InvalidRequest, 'The request is larger than 256 KiB.');
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new A2aException(A2aError::ParseError, 'The body is not valid JSON.');
        }
        return Json::object($decoded, 'body');
    }

    /**
     * @return array<string, mixed>
     */
    private function query(ServerRequestInterface $request): array
    {
        $query = [];
        foreach ($request->getQueryParams() as $name => $value) {
            if (is_string($value) && $name !== ProtocolVersion::HEADER) {
                $query[(string)$name] = $value;
            }
        }
        return $query;
    }

    private function taskId(ServerRequestInterface $request): string
    {
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        return $match instanceof RouteMatch ? $match->parameter('id') : '';
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function messageMetadata(array $body): array
    {
        $message = $body['message'] ?? null;
        $metadata = is_array($message) ? ($message['metadata'] ?? null) : null;
        if (!is_array($metadata)) {
            return [];
        }
        $map = [];
        foreach ($metadata as $key => $value) {
            $map[(string)$key] = $value;
        }
        return $map;
    }

    private function describe(ServerRequestInterface $request, Operation $operation): void
    {
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        if ($capture instanceof TrafficCapture) {
            $capture->describe($operation->value);
        }
    }
}
