<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Http;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\SelfEmittableStreamInterface;
use TYPO3\CMS\Core\Http\Stream;

/**
 * A Server-Sent-Events body that a protocol can hand back as a normal response.
 *
 * The three protocol encoders used to write frames with echo/flush and then
 * `exit`, because an SSE stream cannot go through the usual "build the whole
 * body, then emit it" path. That worked in a browser and made the endpoints
 * untestable: any test that called one killed the test runner with it.
 *
 * TYPO3 already solves this. A response body implementing
 * SelfEmittableStreamInterface takes over its own emission, so
 * {@see emit()} keeps the real flush-per-frame behaviour in production while the
 * request still ends normally — no exit, no skipped shutdown handlers. Consumers
 * that do not emit (functional tests, anything inspecting the response) read the
 * same stream through {@see __toString()}, which renders every frame without
 * flushing or pacing.
 *
 * The frames come from a generator, so the body can only be read once — which is
 * exactly what a stream is.
 */
final class EventStream extends Stream implements SelfEmittableStreamInterface
{
    /**
     * @param iterable<array<string, mixed>> $frames
     * @param string $openingComment SSE comment sent first, so proxies and
     *                               clients see the stream open immediately
     * @param int $delayMs per-frame pacing when emitting (0 = as fast as possible)
     */
    public function __construct(
        private readonly iterable $frames,
        private readonly string $openingComment,
        private readonly int $delayMs = 0,
    ) {
        parent::__construct('php://temp', 'rw');
    }

    /**
     * Wrap frames in a ready-to-return SSE response.
     *
     * @param iterable<array<string, mixed>> $frames
     */
    public static function response(iterable $frames, string $openingComment, int $delayMs = 0): ResponseInterface
    {
        return new Response(new self($frames, $openingComment, $delayMs), 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            // nginx: do not buffer the stream
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * One SSE record: `data: {json}\n\n`.
     *
     * @param array<string, mixed> $frame
     */
    public static function frame(array $frame): string
    {
        return 'data: ' . json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    /**
     * The same stream, with an observer that sees every frame as it goes out
     * and is told when the stream is over — normally, on an error, or because
     * the client hung up. The traffic log records streams this way, since their
     * frames only exist while the body is being emitted.
     *
     * @param \Closure(array<string, mixed>): void $onFrame
     * @param \Closure(): void $onEnd
     */
    public function observed(\Closure $onFrame, \Closure $onEnd): self
    {
        $frames = $this->frames;
        $observedFrames = (static function () use ($frames, $onFrame, $onEnd): \Generator {
            try {
                foreach ($frames as $frame) {
                    $onFrame($frame);
                    yield $frame;
                }
            } finally {
                $onEnd();
            }
        })();

        return new self($observedFrames, $this->openingComment, $this->delayMs);
    }

    /**
     * Production path: write each frame as it is produced and flush, so the UI
     * sees the agent work rather than the finished result.
     */
    public function emit(): void
    {
        ignore_user_abort(false);

        echo ': ' . $this->openingComment . "\n\n";
        self::flush();

        foreach ($this->frames as $frame) {
            echo self::frame($frame);
            self::flush();
            if (connection_aborted()) {
                break;
            }
            if ($this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }
        }
    }

    /**
     * Buffered path for consumers that do not emit — the whole stream at once,
     * with no flushing and no pacing.
     */
    public function __toString(): string
    {
        $out = ': ' . $this->openingComment . "\n\n";
        foreach ($this->frames as $frame) {
            $out .= self::frame($frame);
        }
        return $out;
    }

    /** Push whatever has been written straight to the client. */
    private static function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    /**
     * Unknowable up front, and saying so keeps TYPO3 from putting a wrong
     * Content-Length on a stream that has not been produced yet.
     */
    public function getSize(): ?int
    {
        return null;
    }
}
