<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Shared\Http\EventStream;

/**
 * Frames A2A wire frames as Server-Sent Events.
 *
 * A2A\'s `message/stream` method answers with an SSE stream whose records are
 * JSON-RPC responses carrying a Task, a status-update or an artifact-update.
 * This encoder frames them for the backend AJAX route, the public JSON-RPC eID
 * and the frontend Concierge; the streaming itself belongs to the response body
 * ({@see EventStream}).
 */
final class SseEncoder implements SingletonInterface
{
    /**
     * One SSE record: `data: {json}\n\n`.
     *
     * @param array<string, mixed> $frame
     */
    public function sse(array $frame): string
    {
        return EventStream::frame($frame);
    }

    /**
     * Hand a task's frames back as an SSE response. Emission — flush per record, with
     * pacing so the stream is visible — is the response body's own job; see
     * {@see EventStream}.
     *
     * @param iterable<array<string, mixed>> $frames
     * @param int $delayMs per-record delay so streaming is visible
     */
    public function stream(iterable $frames, int $delayMs = 70): ResponseInterface
    {
        return EventStream::response($frames, 'a2a stream open', $delayMs);
    }
}
