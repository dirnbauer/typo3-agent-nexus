<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * Speaks A2A 0.3 on the outside while everything inside runs on 1.0.
 *
 * A client that sends no `A2A-Version` header is a 0.3 client. Its parameters
 * are translated to 1.0 before they reach the operations, and every result
 * and stream frame is translated back afterwards, so the task logic exists
 * once. What changes between the versions is purely representation:
 *
 *   - `kind` discriminators on tasks, messages, parts and events;
 *   - states `input-required` instead of `TASK_STATE_INPUT_REQUIRED`,
 *     roles `user`/`agent` instead of `ROLE_USER`/`ROLE_AGENT`;
 *   - parts `{kind: "text", text}` / `{kind: "file", file: {bytes|uri,
 *     mimeType, name}}` / `{kind: "data", data}` instead of the 1.0 oneof;
 *   - stream events `status-update`/`artifact-update` with a `final` flag
 *     instead of the StreamResponse wrapper;
 *   - `message/send` answers with the bare Task instead of `{"task": …}`;
 *   - `configuration.blocking` instead of `returnImmediately`.
 *
 * `final` is true on a status update whose state ends the stream (terminal
 * or interrupted), because that is exactly where this server closes it.
 */
final class LegacyTranslator
{
    /**
     * 0.3 request parameters as 1.0 parameters.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function paramsToCore(Operation $operation, array $params): array
    {
        if ($operation !== Operation::SendMessage && $operation !== Operation::SendStreamingMessage) {
            return $params;
        }
        $core = $params;
        if (isset($params['message'])) {
            $core['message'] = self::messageToCore($params['message']);
        }
        if (is_array($params['configuration'] ?? null)) {
            $legacy = $params['configuration'];
            $configuration = [];
            if (array_key_exists('acceptedOutputModes', $legacy)) {
                $configuration['acceptedOutputModes'] = $legacy['acceptedOutputModes'];
            }
            if (array_key_exists('historyLength', $legacy)) {
                $configuration['historyLength'] = $legacy['historyLength'];
            }
            if (is_bool($legacy['blocking'] ?? null)) {
                $configuration['returnImmediately'] = !$legacy['blocking'];
            }
            if (($legacy['pushNotificationConfig'] ?? null) !== null) {
                $configuration['taskPushNotificationConfig'] = $legacy['pushNotificationConfig'];
            }
            $core['configuration'] = $configuration;
        }
        return $core;
    }

    public static function messageToCore(mixed $message): mixed
    {
        if (!is_array($message)) {
            return $message;
        }
        unset($message['kind']);
        if (is_string($message['role'] ?? null)) {
            $message['role'] = Role::fromLegacy($message['role'])->value ?? $message['role'];
        }
        if (is_array($message['parts'] ?? null)) {
            $message['parts'] = array_map(self::partToCore(...), $message['parts']);
        }
        return $message;
    }

    public static function partToCore(mixed $part): mixed
    {
        if (!is_array($part) || !isset($part['kind'])) {
            return $part;
        }
        $file = is_array($part['file'] ?? null) ? $part['file'] : [];
        $core = match ($part['kind']) {
            'text' => ['text' => $part['text'] ?? null],
            'data' => ['data' => $part['data'] ?? null],
            'file' => array_filter([
                'raw' => $file['bytes'] ?? null,
                'url' => isset($file['bytes']) ? null : ($file['uri'] ?? null),
                'mediaType' => $file['mimeType'] ?? null,
                'filename' => $file['name'] ?? null,
            ], static fn(mixed $value): bool => $value !== null),
            default => array_diff_key($part, ['kind' => true]),
        };
        if (is_array($part['metadata'] ?? null) && $part['metadata'] !== []) {
            $core['metadata'] = $part['metadata'];
        }
        return $core;
    }

    /**
     * A 1.0 SendMessageResponse as 0.3 answers message/send: the bare Task or
     * Message.
     *
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public static function sendMessageResponse(array $response): array
    {
        return isset($response['message'])
            ? self::message(self::map($response['message']))
            : self::task(self::map($response['task'] ?? null));
    }

    /**
     * A 1.0 StreamResponse as a 0.3 stream event.
     *
     * @param array<string, mixed> $frame
     * @return array<string, mixed>
     */
    public static function streamResponse(array $frame): array
    {
        if (isset($frame['task'])) {
            return self::task(self::map($frame['task']));
        }
        if (isset($frame['message'])) {
            return self::message(self::map($frame['message']));
        }
        if (isset($frame['statusUpdate'])) {
            $event = self::map($frame['statusUpdate']);
            $status = self::status(self::map($event['status'] ?? null));
            return self::withOptional([
                'kind' => 'status-update',
                'taskId' => $event['taskId'] ?? '',
                'contextId' => $event['contextId'] ?? '',
                'status' => $status,
                'final' => TaskState::fromLegacy((string)$status['state'])?->endsStream() ?? false,
            ], $event, ['metadata']);
        }
        $event = self::map($frame['artifactUpdate'] ?? null);
        return self::withOptional([
            'kind' => 'artifact-update',
            'taskId' => $event['taskId'] ?? '',
            'contextId' => $event['contextId'] ?? '',
            'artifact' => self::artifact(self::map($event['artifact'] ?? null)),
        ], $event, ['append', 'lastChunk', 'metadata']);
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    public static function task(array $task): array
    {
        $legacy = [
            'kind' => 'task',
            'id' => $task['id'] ?? '',
            'contextId' => $task['contextId'] ?? '',
            'status' => self::status(self::map($task['status'] ?? null)),
        ];
        if (is_array($task['artifacts'] ?? null)) {
            $legacy['artifacts'] = array_map(static fn(mixed $artifact): array => self::artifact(self::map($artifact)), $task['artifacts']);
        }
        if (is_array($task['history'] ?? null)) {
            $legacy['history'] = array_map(static fn(mixed $message): array => self::message(self::map($message)), $task['history']);
        }
        return self::withOptional($legacy, $task, ['metadata']);
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    public static function status(array $status): array
    {
        $state = TaskState::tryFrom(is_string($status['state'] ?? null) ? $status['state'] : '');
        $legacy = ['state' => $state?->legacyValue() ?? 'unknown'];
        if (is_array($status['message'] ?? null)) {
            $legacy['message'] = self::message(self::map($status['message']));
        }
        return self::withOptional($legacy, $status, ['timestamp']);
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    public static function message(array $message): array
    {
        $role = Role::tryFrom(is_string($message['role'] ?? null) ? $message['role'] : '');
        return self::withOptional([
            'kind' => 'message',
            'messageId' => $message['messageId'] ?? '',
            'role' => $role?->legacyValue() ?? 'agent',
            'parts' => array_map(static fn(mixed $part): array => self::part(self::map($part)), is_array($message['parts'] ?? null) ? $message['parts'] : []),
        ], $message, ['contextId', 'taskId', 'metadata', 'extensions', 'referenceTaskIds']);
    }

    /**
     * @param array<string, mixed> $part
     * @return array<string, mixed>
     */
    public static function part(array $part): array
    {
        if (array_key_exists('text', $part)) {
            $legacy = ['kind' => 'text', 'text' => $part['text']];
        } elseif (array_key_exists('raw', $part) || array_key_exists('url', $part)) {
            $file = array_key_exists('raw', $part) ? ['bytes' => $part['raw']] : ['uri' => $part['url']];
            if (is_string($part['mediaType'] ?? null) && $part['mediaType'] !== '') {
                $file['mimeType'] = $part['mediaType'];
            }
            if (is_string($part['filename'] ?? null) && $part['filename'] !== '') {
                $file['name'] = $part['filename'];
            }
            $legacy = ['kind' => 'file', 'file' => $file];
        } else {
            // A 0.3 DataPart holds an object; wrap any other JSON value.
            $data = $part['data'] ?? null;
            $legacy = ['kind' => 'data', 'data' => is_array($data) && ($data === [] || !array_is_list($data)) ? $data : ['value' => $data]];
        }
        return self::withOptional($legacy, $part, ['metadata']);
    }

    /**
     * @param array<string, mixed> $artifact
     * @return array<string, mixed>
     */
    public static function artifact(array $artifact): array
    {
        return self::withOptional([
            'artifactId' => $artifact['artifactId'] ?? '',
            'parts' => array_map(static fn(mixed $part): array => self::part(self::map($part)), is_array($artifact['parts'] ?? null) ? $artifact['parts'] : []),
        ], $artifact, ['name', 'description', 'metadata', 'extensions']);
    }

    /**
     * Copy the listed members over when the 1.0 object has them.
     *
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     * @param list<string> $members
     * @return array<string, mixed>
     */
    private static function withOptional(array $target, array $source, array $members): array
    {
        foreach ($members as $member) {
            if (array_key_exists($member, $source)) {
                $target[$member] = $source[$member];
            }
        }
        return $target;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string)$key] = $item;
        }
        return $map;
    }
}
