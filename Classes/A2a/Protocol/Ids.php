<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * Identifiers the server generates: task, context, message and artifact ids.
 *
 * Random UUIDs (version 4). A task id is also what lets a client read the task
 * back — this public agent has no accounts — so it has to be unguessable.
 */
final class Ids
{
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * An id a client may choose, such as a context id: printable, no spaces,
     * short enough for the object store.
     */
    public static function isAcceptable(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\-]{0,127}$/', $id) === 1;
    }
}
