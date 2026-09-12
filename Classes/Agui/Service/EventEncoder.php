<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Shared\Http\EventStream;

/**
 * Frames AG-UI events as Server-Sent Events for the backend route and the
 * frontend eID endpoint alike. The streaming itself belongs to the response
 * body ({@see EventStream}), so both callers simply return what this hands back.
 */
final class EventEncoder implements SingletonInterface
{
    /**
     * One SSE record: `data: {json}\n\n`.
     *
     * @param array<string, mixed> $event
     */
    public function sse(array $event): string
    {
        return EventStream::frame($event);
    }

    /**
     * Hand a run's events back as an SSE response. Emission — flush per record, with
     * pacing so the stream is visible — is the response body's own job; see
     * {@see EventStream}.
     *
     * @param iterable<array<string, mixed>> $events
     * @param int $delayMs per-record delay so streaming is visible
     */
    public function stream(iterable $events, int $delayMs = 80): ResponseInterface
    {
        return EventStream::response($events, 'agui stream open', $delayMs);
    }
}
