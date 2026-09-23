<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Traffic;

use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * One exchange being recorded: what came in, and — once it is over — what went
 * out.
 *
 * The recorder creates it before the endpoint runs and hands it to the endpoint
 * as a request attribute ({@see self::ATTRIBUTE}), so the endpoint can say what
 * the exchange was about: the protocol operation (`SendStreamingMessage`,
 * `create_checkout`) and the id of the object it touched (a task, a run, a
 * checkout session, a mandate). Everything else is captured from the wire.
 */
final class TrafficCapture
{
    public const string ATTRIBUTE = 'agentnexus.traffic';

    /** Recorded stream frames beyond this are counted but not stored. */
    public const int MAX_FRAMES = 600;

    public private(set) string $operation = '';
    public private(set) string $correlationId = '';
    public private(set) int $frameCount = 0;
    public private(set) ?string $error = null;

    /** @var list<array{t: int, data: array<string, mixed>}> */
    public private(set) array $frames = [];

    /**
     * @param array<string, string> $requestHeaders allow-listed request headers
     */
    public function __construct(
        public readonly Protocol $protocol,
        public private(set) Channel $channel,
        public readonly string $method,
        public readonly string $endpoint,
        public readonly array $requestHeaders,
        public readonly string $requestBody,
        public readonly int $beUser = 0,
        public readonly float $startedAt = 0.0,
    ) {}

    /**
     * Reclassify the caller once the endpoint knows it — a frontend widget
     * talks to the same A2A and AG-UI endpoints as any other agent, and says so
     * in its request.
     */
    public function via(Channel $channel): void
    {
        $this->channel = $channel;
    }

    /** Name the protocol operation, e.g. a JSON-RPC method or a REST operation id. */
    public function describe(string $operation): void
    {
        if ($operation !== '') {
            $this->operation = mb_substr($operation, 0, 64);
        }
    }

    /** Link the exchange to the protocol object it created or touched. */
    public function correlate(string $objectId): void
    {
        if ($objectId !== '' && $this->correlationId === '') {
            $this->correlationId = mb_substr($objectId, 0, 128);
        }
    }

    public function fail(string $message): void
    {
        $this->error = mb_substr($message, 0, 255);
    }

    /**
     * @param array<string, mixed> $frame
     */
    public function frame(array $frame): void
    {
        $this->frameCount++;
        if (count($this->frames) < self::MAX_FRAMES) {
            $this->frames[] = ['t' => $this->elapsedMs(), 'data' => $frame];
        }
    }

    public function elapsedMs(): int
    {
        return (int)max(0, round((microtime(true) - $this->startedAt) * 1000));
    }
}
