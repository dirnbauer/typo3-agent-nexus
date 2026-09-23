<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Traffic;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Http\EventStream;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * Writes the live traffic log: one row per protocol exchange.
 *
 * {@see start()} opens a capture before an endpoint runs; {@see finish()} closes
 * it with the response. A plain response is written at once. A Server-Sent-Events
 * response is not over when the endpoint returns — its frames are produced while
 * the body is emitted — so the body is wrapped: every frame is captured as it
 * goes out, and the row is written when the stream ends, including when the
 * client hangs up.
 *
 * Recording must never break an exchange. Every failure here is logged and
 * swallowed; the visitor or the calling agent always gets its response.
 */
final class TrafficRecorder implements SingletonInterface
{
    public function __construct(
        private readonly ExtensionSettings $settings,
        private readonly TrafficRedactor $redactor,
        private readonly TrafficRepository $repository,
        private readonly LoggerInterface $logger,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->trafficEnabled();
    }

    public function start(
        ServerRequestInterface $request,
        Protocol $protocol,
        Channel $channel,
        string $endpoint,
        int $beUser = 0,
    ): ?TrafficCapture {
        if (!$this->enabled()) {
            return null;
        }
        $body = '';
        if ($this->settings->trafficCaptureBodies()) {
            $stream = $request->getBody();
            $body = (string)$stream;
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
        }
        $startedAt = $request->getServerParams()['REQUEST_TIME_FLOAT'] ?? null;

        return new TrafficCapture(
            $protocol,
            $channel,
            strtoupper($request->getMethod()),
            mb_substr($endpoint, 0, 255),
            $this->redactor->headers($request->getHeaders()),
            $this->redactor->body($body, $this->settings->trafficRedactPersonalData()),
            $beUser,
            is_float($startedAt) ? $startedAt : microtime(true),
        );
    }

    /**
     * Close a capture with the endpoint's response and return the response the
     * caller must send — for streams, one whose body records while it emits.
     */
    public function finish(TrafficCapture $capture, ResponseInterface $response): ResponseInterface
    {
        try {
            $body = $response->getBody();
            if ($body instanceof EventStream) {
                $observed = $body->observed(
                    $capture->frame(...),
                    fn() => $this->write($capture, $response, ''),
                );
                return $response->withBody($observed);
            }

            $content = '';
            if ($this->settings->trafficCaptureBodies()) {
                $content = (string)$body;
                if ($body->isSeekable()) {
                    $body->rewind();
                }
            }
            $this->write($capture, $response, $content);
        } catch (\Throwable $e) {
            $this->logger->warning('Agent Nexus could not record a traffic entry.', ['exception' => $e]);
        }
        return $response;
    }

    /**
     * Record an exchange that never crossed the network: a demo agent calling a
     * protocol binding in-process on a visitor's behalf (the UCP shopping agent
     * talking to the merchant's checkout API, for example).
     *
     * @param array<string, mixed>|null $requestBody
     * @param array<string, mixed>|null $responseBody
     */
    public function recordInProcess(
        Protocol $protocol,
        string $method,
        string $endpoint,
        string $operation,
        ?array $requestBody,
        int $status,
        ?array $responseBody,
        string $correlationId = '',
        int $durationMs = 0,
    ): void {
        if (!$this->enabled()) {
            return;
        }
        try {
            $redact = $this->settings->trafficRedactPersonalData();
            $bodies = $this->settings->trafficCaptureBodies();
            $this->repository->add([
                'protocol' => $protocol->value,
                'channel' => Channel::Agent->value,
                'method' => strtoupper($method),
                'endpoint' => mb_substr($endpoint, 0, 255),
                'operation' => mb_substr($operation, 0, 64),
                'correlation_id' => mb_substr($correlationId, 0, 128),
                'status_code' => $status,
                'is_error' => $status >= 400 ? 1 : 0,
                'is_stream' => 0,
                'duration_ms' => max(0, $durationMs),
                'event_count' => 0,
                'request_headers' => '{}',
                'request_body' => $bodies && $requestBody !== null ? $this->encode($this->redactor->data($requestBody, $redact)) : '',
                'response_headers' => '{"content-type":"application/json"}',
                'response_body' => $bodies && $responseBody !== null ? $this->encode($this->redactor->data($responseBody, $redact)) : '',
                'events' => '',
                'error' => '',
                'be_user' => 0,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Agent Nexus could not record an in-process exchange.', ['exception' => $e]);
        }
    }

    private function write(TrafficCapture $capture, ResponseInterface $response, string $content): void
    {
        try {
            $redact = $this->settings->trafficRedactPersonalData();
            $isStream = $response->getBody() instanceof EventStream || $capture->frameCount > 0;
            $frames = [];
            if ($this->settings->trafficCaptureBodies()) {
                foreach ($capture->frames as $frame) {
                    $frames[] = ['t' => $frame['t'], 'data' => $this->redactor->data($frame['data'], $redact)];
                }
            }
            $status = $response->getStatusCode();

            $this->repository->add([
                'protocol' => $capture->protocol->value,
                'channel' => $capture->channel->value,
                'method' => $capture->method,
                'endpoint' => $capture->endpoint,
                'operation' => $capture->operation,
                'correlation_id' => $capture->correlationId,
                'status_code' => $status,
                'is_error' => ($status >= 400 || $capture->error !== null) ? 1 : 0,
                'is_stream' => $isStream ? 1 : 0,
                'duration_ms' => $capture->elapsedMs(),
                'event_count' => $capture->frameCount,
                'request_headers' => $this->encode($capture->requestHeaders),
                'request_body' => $capture->requestBody,
                'response_headers' => $this->encode($this->redactor->headers($response->getHeaders())),
                'response_body' => $this->redactor->body($content, $redact),
                'events' => $frames === [] ? '' : $this->redactor->cap($this->encode($frames), 4 * TrafficRedactor::MAX_BODY_BYTES),
                'error' => $capture->error ?? '',
                'be_user' => $capture->beUser,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Agent Nexus could not record a traffic entry.', ['exception' => $e]);
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function encode(array $data): string
    {
        if ($data === []) {
            return '{}';
        }
        return (string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
