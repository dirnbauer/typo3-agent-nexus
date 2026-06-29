<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Streams A2A messages to the client as Server-Sent Events.
 *
 * A2A's `message/stream` method returns an SSE stream where each event is a
 * JSON-RPC response carrying a Task / status-update / artifact-update. This
 * encoder owns the raw streaming loop (buffering off, flush per frame) shared by
 * the backend AJAX route, the public JSON-RPC eID and the frontend Concierge.
 */
final class SseEncoder implements SingletonInterface
{
    /** One SSE frame: `data: {json}\n\n`. */
    public function sse(array $frame): string
    {
        return 'data: ' . json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    /**
     * @param iterable<array<string, mixed>> $frames
     * @param int $delayMs per-frame delay so streaming is visible
     */
    public function stream(iterable $frames, int $delayMs = 70): never
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // nginx: do not buffer the stream
        }
        ignore_user_abort(false);
        echo ": a2a stream open\n\n";
        @ob_flush();
        flush();

        foreach ($frames as $frame) {
            echo $this->sse($frame);
            @ob_flush();
            flush();
            if (connection_aborted()) {
                break;
            }
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }
        exit;
    }
}
