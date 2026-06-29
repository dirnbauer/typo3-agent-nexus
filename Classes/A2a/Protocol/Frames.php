<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * Factory for A2A wire frames.
 *
 * On a `message/stream` call the server streams JSON-RPC 2.0 responses; each
 * `result` is one of the A2A event objects, distinguished by `kind`:
 *
 *   - `task`            — the initial Task (id, contextId, status).
 *   - `status-update`   — a TaskStatusUpdateEvent (state transition, optional message).
 *   - `artifact-update` — a TaskArtifactUpdateEvent (a produced artifact, streamable in chunks).
 *
 * Keeping frames as plain arrays mirrors the deterministic agent's readability;
 * the field names follow the A2A spec (a2a-protocol.org / a2aproject.github.io).
 */
final class Frames
{
    /** Wrap an A2A result object in a JSON-RPC 2.0 response envelope. */
    public static function result(int|string $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** A JSON-RPC error envelope (e.g. method not found / invalid params). */
    public static function error(int|string|null $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** kind:task — the freshly created Task. */
    public static function task(string $taskId, string $contextId, string $state = 'submitted'): array
    {
        return [
            'kind' => 'task',
            'id' => $taskId,
            'contextId' => $contextId,
            'status' => ['state' => $state],
        ];
    }

    /**
     * kind:status-update — a lifecycle transition, optionally with an agent message.
     */
    public static function status(string $taskId, string $contextId, string $state, ?string $text = null, bool $final = false): array
    {
        $status = ['state' => $state];
        if ($text !== null) {
            $status['message'] = self::message('agent', $text);
        }
        return [
            'kind' => 'status-update',
            'taskId' => $taskId,
            'contextId' => $contextId,
            'status' => $status,
            'final' => $final,
        ];
    }

    /** kind:artifact-update — open an artifact (no text yet). */
    public static function artifactStart(string $taskId, string $artifactId, string $name, string $description = ''): array
    {
        return [
            'kind' => 'artifact-update',
            'taskId' => $taskId,
            'artifact' => ['artifactId' => $artifactId, 'name' => $name, 'description' => $description, 'parts' => []],
            'append' => false,
            'lastChunk' => false,
        ];
    }

    /** kind:artifact-update — append a streamed text chunk to the artifact. */
    public static function artifactChunk(string $taskId, string $artifactId, string $delta): array
    {
        return [
            'kind' => 'artifact-update',
            'taskId' => $taskId,
            'artifact' => ['artifactId' => $artifactId, 'parts' => [['kind' => 'text', 'text' => $delta]]],
            'append' => true,
            'lastChunk' => false,
        ];
    }

    /** kind:artifact-update — mark the artifact complete. */
    public static function artifactEnd(string $taskId, string $artifactId): array
    {
        return [
            'kind' => 'artifact-update',
            'taskId' => $taskId,
            'artifact' => ['artifactId' => $artifactId, 'parts' => []],
            'append' => true,
            'lastChunk' => true,
        ];
    }

    /** An A2A Message object (role + text part). */
    public static function message(string $role, string $text): array
    {
        return [
            'kind' => 'message',
            'role' => $role,
            'parts' => [['kind' => 'text', 'text' => $text]],
            'messageId' => 'msg-' . substr(md5($role . $text), 0, 8),
        ];
    }
}
