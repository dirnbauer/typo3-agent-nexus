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
use Webconsulting\AgentNexus\A2a\Protocol\LegacyTranslator;
use Webconsulting\AgentNexus\A2a\Protocol\ListTasksParams;
use Webconsulting\AgentNexus\A2a\Protocol\Operation;
use Webconsulting\AgentNexus\A2a\Protocol\ProtocolVersion;
use Webconsulting\AgentNexus\A2a\Protocol\SendMessageParams;
use Webconsulting\AgentNexus\A2a\Protocol\TaskIdParams;
use Webconsulting\AgentNexus\A2a\Server\A2aServer;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;

/**
 * The JSON-RPC 2.0 binding: POST {apiBase}/a2a/jsonrpc.
 *
 * The `A2A-Version` header picks the dialect. `1.0` gets the 1.0 method names
 * and payloads; a missing header means an A2A 0.3 client, which gets the 0.3
 * names (`message/send`, `tasks/get` …) with 0.3 payloads, translated by
 * {@see LegacyTranslator}. Any other version is a VersionNotSupportedError
 * (-32009) listing the two.
 *
 * Every JSON-RPC error object travels with HTTP 200 — the error is in the
 * body, as JSON-RPC over HTTP does it — with one exception: a client over its
 * rate limit gets HTTP 429 and a Retry-After header, so that plain HTTP
 * tooling backs off too. A streaming call that fails before its first frame
 * gets a plain JSON error instead of a stream; one that fails later ends the
 * stream with an error frame.
 */
#[Autoconfigure(public: true)]
final readonly class JsonRpcEndpoint
{
    /** Requests per client and window, across all A2A methods. */
    public const int REQUEST_LIMIT = 30;
    public const int REQUEST_WINDOW = 600;

    private const int MAX_BODY_BYTES = 262144;

    public function __construct(
        private A2aServer $server,
        private CallContextFactory $contexts,
        private RateLimiter $rateLimiter,
        private LoggerInterface $logger,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        $capture = $capture instanceof TrafficCapture ? $capture : null;

        $body = (string)$request->getBody();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->error(null, new A2aException(A2aError::InvalidRequest, 'The request is larger than 256 KiB.'));
        }
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error(null, new A2aException(A2aError::ParseError));
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return $this->error(null, new A2aException(A2aError::InvalidRequest, 'The request must be one JSON-RPC 2.0 request object; batches are not supported.'));
        }
        $id = $decoded['id'] ?? null;
        $id = is_int($id) || is_string($id) ? $id : null;
        if (($decoded['jsonrpc'] ?? null) !== '2.0') {
            return $this->error($id, new A2aException(A2aError::InvalidRequest, 'The member "jsonrpc" must be exactly "2.0".'));
        }
        $method = $decoded['method'] ?? null;
        if (!is_string($method) || $method === '') {
            return $this->error($id, new A2aException(A2aError::InvalidRequest, 'The member "method" is required.'));
        }
        $capture?->describe($method);

        try {
            $version = ProtocolVersion::negotiate(Responses::requestedVersion($request));
        } catch (A2aException $exception) {
            return $this->error($id, $exception);
        }

        $operation = Operation::fromMethod($method, $version);
        if ($operation === null) {
            return $this->error($id, $this->methodNotFound($method, $version), $version);
        }
        if (!$this->rateLimiter->passes($request, 'a2a', self::REQUEST_LIMIT, self::REQUEST_WINDOW)) {
            return $this->rateLimited($id, $version);
        }

        try {
            $params = Json::object($decoded['params'] ?? [], 'params');
            if ($version === ProtocolVersion::V0_3) {
                $params = LegacyTranslator::paramsToCore($operation, $params);
            }
            return $this->dispatch($operation, $params, $request, $id, $version);
        } catch (A2aException $exception) {
            return $this->error($id, $exception, $version);
        } catch (\Throwable $exception) {
            $this->logger->error('A2A JSON-RPC call {method} failed.', ['method' => $method, 'exception' => $exception]);
            $capture?->fail($exception->getMessage());
            return $this->error($id, new A2aException(A2aError::Internal, 'The agent failed. The error has been logged.'), $version);
        }
    }

    /**
     * @param array<string, mixed> $params 1.0 parameters
     */
    private function dispatch(Operation $operation, array $params, ServerRequestInterface $request, int|string|null $id, ProtocolVersion $version): ResponseInterface
    {
        $context = $this->contexts->create($request, $this->messageMetadata($operation, $params));

        return match ($operation) {
            Operation::SendMessage => $this->result($id, $version, $operation, $this->server->sendMessage(SendMessageParams::fromArray($params), $context)),
            Operation::SendStreamingMessage => $this->stream($id, $version, $this->server->sendStreamingMessage(SendMessageParams::fromArray($params), $context)),
            Operation::GetTask => $this->result($id, $version, $operation, $this->server->getTask(TaskIdParams::fromArray($params), $context)),
            Operation::ListTasks => $this->result($id, $version, $operation, $this->server->listTasks(ListTasksParams::fromArray($params))),
            Operation::CancelTask => $this->result($id, $version, $operation, $this->server->cancelTask(TaskIdParams::fromArray($params), $context)),
            Operation::SubscribeToTask => $this->stream($id, $version, $this->server->subscribeToTask(TaskIdParams::fromArray($params), $context)),
            Operation::GetExtendedAgentCard => $this->server->extendedAgentCard(),
            Operation::CreateTaskPushNotificationConfig, Operation::GetTaskPushNotificationConfig,
            Operation::ListTaskPushNotificationConfigs, Operation::DeleteTaskPushNotificationConfig => $this->server->pushNotificationConfig(),
        };
    }

    /**
     * @param array<string, mixed> $result the 1.0 result
     */
    private function result(int|string|null $id, ProtocolVersion $version, Operation $operation, array $result): ResponseInterface
    {
        if ($version === ProtocolVersion::V0_3) {
            $result = $operation === Operation::SendMessage
                ? LegacyTranslator::sendMessageResponse($result)
                : LegacyTranslator::task($result);
        }
        return $this->withVersion(
            Responses::json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]),
            $version,
        );
    }

    /**
     * @param \Generator<int, array<string, mixed>, mixed, void> $frames 1.0 StreamResponses
     */
    private function stream(int|string|null $id, ProtocolVersion $version, \Generator $frames): ResponseInterface
    {
        // Run up to the first frame: a request the operation refuses is
        // answered here, as a plain JSON-RPC error, before a stream exists.
        $frames->current();

        $envelopes = (function () use ($frames, $id, $version): \Generator {
            try {
                while ($frames->valid()) {
                    $frame = $frames->current();
                    yield [
                        'jsonrpc' => '2.0',
                        'id' => $id,
                        'result' => $version === ProtocolVersion::V0_3 ? LegacyTranslator::streamResponse($frame) : $frame,
                    ];
                    $frames->next();
                }
            } catch (A2aException $exception) {
                yield ['jsonrpc' => '2.0', 'id' => $id, 'error' => $exception->toJsonRpcError()];
            } catch (\Throwable $exception) {
                $this->logger->error('An A2A stream failed.', ['exception' => $exception]);
                yield ['jsonrpc' => '2.0', 'id' => $id, 'error' => (new A2aException(A2aError::Internal, 'The agent failed. The error has been logged.'))->toJsonRpcError()];
            }
        })();

        return $this->withVersion(Responses::stream($envelopes), $version);
    }

    private function error(int|string|null $id, A2aException $exception, ?ProtocolVersion $version = null): ResponseInterface
    {
        $response = Responses::json(['jsonrpc' => '2.0', 'id' => $id, 'error' => $exception->toJsonRpcError()]);
        return $version !== null ? $this->withVersion($response, $version) : $response;
    }

    private function rateLimited(int|string|null $id, ProtocolVersion $version): ResponseInterface
    {
        $exception = new A2aException(
            A2aError::RateLimited,
            sprintf('Too many requests from this address. Try again in %d minutes.', intdiv(self::REQUEST_WINDOW, 60)),
            ['limit' => (string)self::REQUEST_LIMIT, 'windowSeconds' => (string)self::REQUEST_WINDOW],
        );
        return $this->withVersion(
            Responses::json(['jsonrpc' => '2.0', 'id' => $id, 'error' => $exception->toJsonRpcError()], 429, 'application/json', ['Retry-After' => (string)self::REQUEST_WINDOW]),
            $version,
        );
    }

    /**
     * Method not found — with a hint when the method exists in the other
     * version, which is the usual mistake: a 1.0 client that forgot the header.
     */
    private function methodNotFound(string $method, ProtocolVersion $version): A2aException
    {
        $message = sprintf('Method "%s" does not exist.', mb_substr($method, 0, 80));
        if ($version === ProtocolVersion::V0_3 && Operation::tryFrom($method) !== null) {
            $message = sprintf(
                'Method "%s" is an A2A 1.0 method. Send the header %s: 1.0 to call it; without that header this endpoint speaks A2A 0.3.',
                $method,
                ProtocolVersion::HEADER,
            );
        } elseif ($version === ProtocolVersion::V1_0 && ($operation = Operation::fromMethod($method, ProtocolVersion::V0_3)) !== null) {
            $message = sprintf(
                'Method "%s" is the A2A 0.3 name of %s. Call %s, or leave out the %s header to speak A2A 0.3.',
                $method,
                $operation->value,
                $operation->value,
                ProtocolVersion::HEADER,
            );
        }
        return new A2aException(A2aError::MethodNotFound, $message);
    }

    /**
     * The client message's metadata, where the concierge widget identifies
     * itself. Anything malformed is left for the parameter check to report.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function messageMetadata(Operation $operation, array $params): array
    {
        if ($operation !== Operation::SendMessage && $operation !== Operation::SendStreamingMessage) {
            return [];
        }
        $message = $params['message'] ?? null;
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

    private function withVersion(ResponseInterface $response, ProtocolVersion $version): ResponseInterface
    {
        return $response->withHeader(ProtocolVersion::HEADER, $version->value);
    }
}
