<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Frames an AG-UI event as a single Server-Sent-Events `data:` record, and owns
 * the raw streaming loop (headers off, flush per event) shared by the backend
 * route and the frontend eID endpoint.
 */
final class EventEncoder implements SingletonInterface
{
    /** One SSE frame: `data: {json}\n\n`. */
    public function sse(array $event): string
    {
        return 'data: ' . json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    /**
     * Stream a sequence of events to the client as SSE, flushing each frame so the
     * UI sees them arrive one by one. Terminates the request when done — SSE
     * cannot share the normal PSR-7 response emission.
     *
     * @param iterable<array<string, mixed>> $events
     * @param int $delayMs per-event delay so streaming is visible
     */
    public function stream(iterable $events, int $delayMs = 80): never
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
        echo ": agui stream open\n\n";
        @ob_flush();
        flush();

        foreach ($events as $event) {
            echo $this->sse($event);
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
