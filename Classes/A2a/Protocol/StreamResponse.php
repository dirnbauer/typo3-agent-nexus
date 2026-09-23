<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * The frames of an A2A 1.0 stream.
 *
 * A StreamResponse is a wrapper with exactly one member — `task`, `message`,
 * `statusUpdate` or `artifactUpdate` — which replaced the `kind` discriminator
 * of 0.3. A task stream starts with the Task, continues with updates and
 * closes after a terminal or interrupted state; there is no `final` flag any
 * more, the close is the signal.
 *
 * The JSON-RPC binding wraps each frame as the `result` of a response; the
 * HTTP+JSON binding sends it bare.
 */
final class StreamResponse
{
    /** The four members of the StreamResponse oneof. */
    public const array MEMBERS = ['task', 'message', 'statusUpdate', 'artifactUpdate'];

    /**
     * @return array{task: array<string, mixed>}
     */
    public static function task(Task $task, ?int $historyLength = null): array
    {
        return ['task' => $task->toArray($historyLength)];
    }

    /**
     * @return array{message: array<string, mixed>}
     */
    public static function message(Message $message): array
    {
        return ['message' => $message->toArray()];
    }

    /**
     * The task's current status as an update event.
     *
     * @param array<string, mixed> $metadata
     * @return array{statusUpdate: array<string, mixed>}
     */
    public static function statusUpdate(Task $task, array $metadata = []): array
    {
        $event = [
            'taskId' => $task->id,
            'contextId' => $task->contextId,
            'status' => $task->status->toArray(),
        ];
        if ($metadata !== []) {
            $event['metadata'] = $metadata;
        }
        return ['statusUpdate' => $event];
    }

    /**
     * One chunk of an artifact. The first chunk opens the artifact
     * (`append: false`), every later one is appended to it, and the last one
     * says so (`lastChunk: true`). Every chunk carries content: an artifact
     * without parts is invalid, so there is no empty "opening" frame.
     *
     * @return array{artifactUpdate: array<string, mixed>}
     */
    public static function artifactUpdate(Task $task, Artifact $chunk, bool $append, bool $lastChunk): array
    {
        return ['artifactUpdate' => [
            'taskId' => $task->id,
            'contextId' => $task->contextId,
            'artifact' => $chunk->toArray(),
            'append' => $append,
            'lastChunk' => $lastChunk,
        ]];
    }

    /**
     * Which member a frame carries, or '' for something that is not a frame.
     *
     * @param array<array-key, mixed> $frame
     */
    public static function memberOf(array $frame): string
    {
        foreach (self::MEMBERS as $member) {
            if (isset($frame[$member])) {
                return $member;
            }
        }
        return '';
    }
}
