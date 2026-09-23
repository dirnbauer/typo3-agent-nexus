<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

/**
 * Reads AG-UI 1.0 values against the shapes the schema gives them.
 *
 * Two modes, because the specification's processing model is asymmetric:
 *
 * - strict (what this installation *sends*): a producer emits only what the
 *   schema describes, so an undeclared member, an unknown message role or
 *   patch operation, or a whole optional field sent as `null` is an error;
 * - lenient (what a client *sends us*): unrecognised material is stripped and
 *   remembered ({@see self::stripped()}), the optional `null`s older clients
 *   were allowed to send are read as absent, and only a malformed known value
 *   is an error.
 *
 * Every reader returns the value as plain PHP data ({@see Json::toPhp()}) and
 * throws {@see ShapeError} with a JSON pointer when the value is malformed.
 */
final class ShapeReader
{
    /** RFC 6902 operations and the members each needs besides `op` and `path`. */
    private const array PATCH_OPERATIONS = [
        'add' => ['value'],
        'remove' => [],
        'replace' => ['value'],
        'move' => ['from'],
        'copy' => ['from'],
        'test' => ['value'],
    ];

    private const string POINTER_PATTERN = '#^(/([^/~]|~[01])*)*$#u';

    private const array TEXT_ROLES = ['developer', 'system', 'assistant', 'user'];

    private const array MEDIA_PARTS = ['image', 'audio', 'video', 'document'];

    private const array TOKEN_COUNTS = ['inputTokens', 'outputTokens', 'totalTokens', 'reasoningTokens', 'cachedInputTokens', 'cacheWriteInputTokens'];

    /** @var list<string> */
    private array $stripped = [];

    /** Returned for an unrecognised union member in lenient mode: its container drops it. */
    private readonly \stdClass $drop;

    public function __construct(
        public readonly bool $strict,
    ) {
        $this->drop = new \stdClass();
    }

    /**
     * Pointers to what lenient reading removed, each with the reason.
     *
     * @return list<string>
     */
    public function stripped(): array
    {
        return $this->stripped;
    }

    /** One event field of the given type. */
    public function field(FieldType $type, mixed $value, string $pointer): mixed
    {
        return match ($type) {
            FieldType::String => $this->string($value, $pointer),
            FieldType::Boolean => is_bool($value) ? $value : throw new ShapeError($pointer, 'expected a boolean'),
            FieldType::Integer => $this->integer($value, $pointer),
            FieldType::Json => Json::toPhp($value),
            FieldType::JsonValue => $this->notNull($value, $pointer),
            FieldType::Object => $this->openObject($value, $pointer),
            FieldType::JsonPatch => $this->patch($value, $pointer),
            FieldType::TokenUsageList => $this->listOf($value, $pointer, $this->tokenUsage(...)),
            FieldType::RunOutcome => $this->runOutcome($value, $pointer),
            FieldType::SubagentOutcome => $this->subagentOutcome($value, $pointer),
            FieldType::RunInput => $this->runInput($value, $pointer),
            FieldType::MessageList => $this->listOf($value, $pointer, $this->message(...)),
            FieldType::ToolContent => $this->content($value, $pointer),
            FieldType::TextRole => $this->oneOf($value, $pointer, self::TEXT_ROLES),
            FieldType::ReasoningRole => $this->oneOf($value, $pointer, ['reasoning']),
            FieldType::ToolRole => $this->oneOf($value, $pointer, ['tool']),
            FieldType::EncryptedSubtype => $this->oneOf($value, $pointer, ['tool-call', 'message']),
        };
    }

    /**
     * RunAgentInput. `state` and `forwardedProps` sent as `null` are read as
     * absent in lenient mode, as the 1.0 client does for older peers.
     *
     * @return array<string, mixed>
     */
    public function runInput(mixed $value, string $pointer = ''): array
    {
        return $this->closedOrFail($value, $pointer, [
            'threadId' => $this->string(...),
            'runId' => $this->string(...),
            'messages' => fn(mixed $v, string $p): array => $this->listOf($v, $p, $this->message(...)),
        ], [
            'protocolVersion' => $this->string(...),
            'parentRunId' => $this->string(...),
            'state' => $this->notNull(...),
            'tools' => fn(mixed $v, string $p): array => $this->listOf($v, $p, $this->tool(...)),
            'context' => fn(mixed $v, string $p): array => $this->listOf($v, $p, $this->context(...)),
            'forwardedProps' => $this->notNull(...),
            'resume' => fn(mixed $v, string $p): array => $this->listOf($v, $p, $this->resumeEntry(...)),
        ], ['state', 'forwardedProps']);
    }

    /**
     * An Interrupt, as RUN_FINISHED carries it.
     *
     * @return array<string, mixed>
     */
    public function interrupt(mixed $value, string $pointer): array
    {
        return $this->closedOrFail($value, $pointer, [
            'id' => $this->string(...),
            'reason' => $this->string(...),
        ], [
            'message' => $this->string(...),
            'toolCallId' => $this->string(...),
            'responseSchema' => $this->openObject(...),
            'expiresAt' => $this->string(...),
            'metadata' => $this->openObject(...),
            'subagentRunId' => $this->string(...),
        ]);
    }

    /**
     * RFC 6902 JSON Patch. Operations are open objects (RFC 6902 §4 ignores
     * members it does not define), so extra members are kept; an operation
     * the RFC does not define is unrecognised material.
     *
     * @return list<mixed>
     */
    public function patch(mixed $value, string $pointer): array
    {
        return $this->listOf($value, $pointer, function (mixed $operation, string $at): mixed {
            if (!Json::isObject($operation)) {
                throw new ShapeError($at, 'expected a JSON Patch operation object');
            }
            $members = Json::members($operation);
            $op = $members['op'] ?? null;
            if (!is_string($op)) {
                throw new ShapeError(Json::pointer($at, 'op'), 'is required and must be a string');
            }
            if (!isset(self::PATCH_OPERATIONS[$op])) {
                return $this->unrecognised(Json::pointer($at, 'op'), sprintf('"%s" is not an RFC 6902 operation', $op));
            }
            foreach (['path', ...self::PATCH_OPERATIONS[$op]] as $name) {
                if (!array_key_exists($name, $members)) {
                    throw new ShapeError(Json::pointer($at, $name), sprintf('is required for "%s"', $op));
                }
            }
            foreach (['path', 'from'] as $name) {
                if (array_key_exists($name, $members)
                    && (!is_string($members[$name]) || preg_match(self::POINTER_PATTERN, $members[$name]) !== 1)
                ) {
                    throw new ShapeError(Json::pointer($at, $name), 'must be a JSON Pointer');
                }
            }
            return Json::toPhp($operation);
        });
    }

    // ---- messages -------------------------------------------------------

    /**
     * One Message, by role. An unknown role is unrecognised material: the
     * message is dropped in lenient mode.
     */
    public function message(mixed $value, string $pointer): mixed
    {
        if (!Json::isObject($value)) {
            throw new ShapeError($pointer, 'expected a message object');
        }
        $role = Json::members($value)['role'] ?? null;
        if (!is_string($role)) {
            throw new ShapeError(Json::pointer($pointer, 'role'), 'is required and must be a string');
        }
        $id = ['id' => $this->string(...), 'role' => static fn(mixed $v, string $p): string => $role];
        $owned = ['metadata' => $this->openObject(...), 'subagentRunId' => $this->string(...)];
        $named = ['name' => $this->string(...), 'encryptedValue' => $this->string(...)];

        return match ($role) {
            'developer', 'system' => $this->closed($value, $pointer, $id + ['content' => $this->string(...)], $named + $owned),
            'assistant' => $this->closed($value, $pointer, $id, [
                'content' => $this->string(...),
                'toolCalls' => fn(mixed $v, string $p): array => $this->listOf($v, $p, $this->toolCall(...)),
            ] + $named + $owned),
            'user' => $this->closed($value, $pointer, $id + ['content' => $this->content(...)], $named + $owned),
            'tool' => $this->closed($value, $pointer, $id + [
                'content' => $this->content(...),
                'toolCallId' => $this->string(...),
            ], ['error' => $this->string(...), 'encryptedValue' => $this->string(...)] + $owned),
            'activity' => $this->closed($value, $pointer, $id + [
                'activityType' => $this->string(...),
                'content' => $this->openObject(...),
            ], $owned),
            'reasoning' => $this->closed($value, $pointer, $id + ['content' => $this->string(...)], ['encryptedValue' => $this->string(...)] + $owned),
            default => $this->unrecognised(Json::pointer($pointer, 'role'), sprintf('"%s" is not an AG-UI 1.0 message role', $role)),
        };
    }

    /**
     * Message or tool-result content: a string or a list of content parts.
     *
     * @return string|list<mixed>
     */
    public function content(mixed $value, string $pointer): string|array
    {
        if (is_string($value)) {
            return $value;
        }
        if (!Json::isList($value)) {
            throw new ShapeError($pointer, 'expected a string or a list of content parts');
        }
        return $this->listOf($value, $pointer, $this->contentPart(...));
    }

    private function contentPart(mixed $value, string $pointer): mixed
    {
        if (!Json::isObject($value)) {
            throw new ShapeError($pointer, 'expected a content part object');
        }
        $type = Json::members($value)['type'] ?? null;
        if (!is_string($type)) {
            throw new ShapeError(Json::pointer($pointer, 'type'), 'is required and must be a string');
        }
        if ($type === 'text') {
            return $this->closed($value, $pointer, [
                'type' => $this->string(...),
                'text' => $this->string(...),
            ], ['id' => $this->string(...), 'metadata' => $this->notNull(...)]);
        }
        if (in_array($type, self::MEDIA_PARTS, true)) {
            return $this->closed($value, $pointer, [
                'type' => $this->string(...),
                'source' => $this->partSource(...),
            ], ['id' => $this->string(...), 'metadata' => $this->notNull(...)], ['metadata']);
        }
        return $this->unrecognised(Json::pointer($pointer, 'type'), sprintf('"%s" is not an AG-UI 1.0 content part', $type));
    }

    private function partSource(mixed $value, string $pointer): mixed
    {
        if (!Json::isObject($value)) {
            throw new ShapeError($pointer, 'expected a source object');
        }
        $type = Json::members($value)['type'] ?? null;
        return match ($type) {
            'data' => $this->closed($value, $pointer, ['type' => $this->string(...), 'value' => $this->string(...), 'mimeType' => $this->string(...)]),
            'url' => $this->closed($value, $pointer, ['type' => $this->string(...), 'value' => $this->string(...)], ['mimeType' => $this->string(...)]),
            'file' => $this->closed($value, $pointer, ['type' => $this->string(...), 'value' => $this->string(...)], [
                'provider' => $this->string(...),
                'mimeType' => $this->string(...),
            ]),
            default => is_string($type)
                ? $this->unrecognised(Json::pointer($pointer, 'type'), sprintf('"%s" is not an AG-UI 1.0 part source', $type))
                : throw new ShapeError(Json::pointer($pointer, 'type'), 'is required and must be a string'),
        };
    }

    private function toolCall(mixed $value, string $pointer): mixed
    {
        return $this->closed($value, $pointer, [
            'id' => $this->string(...),
            'type' => fn(mixed $v, string $p): string => $this->oneOf($v, $p, ['function']),
            'function' => fn(mixed $v, string $p): array => $this->closedOrFail($v, $p, [
                'name' => $this->string(...),
                'arguments' => $this->string(...),
            ]),
        ], ['encryptedValue' => $this->string(...), 'metadata' => $this->openObject(...)]);
    }

    // ---- input members --------------------------------------------------

    private function tool(mixed $value, string $pointer): mixed
    {
        return $this->closed($value, $pointer, [
            'name' => $this->string(...),
            'description' => $this->string(...),
        ], ['parameters' => $this->notNull(...), 'metadata' => $this->openObject(...)], ['parameters']);
    }

    private function context(mixed $value, string $pointer): mixed
    {
        return $this->closed($value, $pointer, [
            'description' => $this->string(...),
            'value' => $this->string(...),
        ]);
    }

    private function resumeEntry(mixed $value, string $pointer): mixed
    {
        return $this->closed($value, $pointer, [
            'interruptId' => $this->string(...),
            'status' => fn(mixed $v, string $p): string => $this->oneOf($v, $p, ['resolved', 'cancelled']),
        ], ['payload' => $this->notNull(...), 'metadata' => $this->openObject(...)], ['payload']);
    }

    // ---- outcomes and usage ---------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function runOutcome(mixed $value, string $pointer): array
    {
        if (!Json::isObject($value)) {
            throw new ShapeError($pointer, 'expected an outcome object');
        }
        $type = Json::members($value)['type'] ?? null;
        return match ($type) {
            'success' => $this->closedOrFail($value, $pointer, ['type' => $this->string(...)], [
                'pendingToolCallIds' => fn(mixed $v, string $p): array => $this->listOf($v, $p, $this->string(...)),
            ]),
            'interrupt' => $this->closedOrFail($value, $pointer, [
                'type' => $this->string(...),
                'interrupts' => function (mixed $v, string $p): array {
                    $interrupts = $this->listOf($v, $p, $this->interrupt(...));
                    return $interrupts !== [] ? $interrupts : throw new ShapeError($p, 'needs at least one interrupt');
                },
            ]),
            'cancelled' => $this->closedOrFail($value, $pointer, ['type' => $this->string(...)]),
            default => throw new ShapeError(Json::pointer($pointer, 'type'), 'must be "success", "interrupt" or "cancelled"'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function subagentOutcome(mixed $value, string $pointer): array
    {
        if (!Json::isObject($value)) {
            throw new ShapeError($pointer, 'expected an outcome object');
        }
        return match (Json::members($value)['type'] ?? null) {
            'success' => $this->closedOrFail($value, $pointer, ['type' => $this->string(...)]),
            'suspended' => $this->closedOrFail($value, $pointer, ['type' => $this->string(...)], [
                'interruptIds' => fn(mixed $v, string $p): array => $this->listOf($v, $p, $this->string(...)),
            ]),
            default => throw new ShapeError(Json::pointer($pointer, 'type'), 'must be "success" or "suspended"'),
        };
    }

    private function tokenUsage(mixed $value, string $pointer): mixed
    {
        $counts = [];
        foreach (self::TOKEN_COUNTS as $name) {
            $counts[$name] = function (mixed $v, string $p): int {
                $count = $this->integer($v, $p);
                return $count >= 0 ? $count : throw new ShapeError($p, 'must not be negative');
            };
        }
        return $this->closed($value, $pointer, [], ['provider' => $this->string(...), 'model' => $this->string(...)] + $counts);
    }

    // ---- primitives -----------------------------------------------------

    /**
     * A closed object: every member is declared, required members are
     * present, optional members are omitted rather than null.
     *
     * @param array<string, \Closure(mixed, string): mixed> $required
     * @param array<string, \Closure(mixed, string): mixed> $optional
     * @param list<string> $nullMeansAbsent optional members whose `null` lenient mode reads as absent
     * @return array<string, mixed>|\stdClass the object, or the drop marker when a required member was unrecognised
     */
    private function closed(mixed $value, string $pointer, array $required, array $optional = [], array $nullMeansAbsent = []): array|\stdClass
    {
        if (!Json::isObject($value)) {
            throw new ShapeError($pointer, 'expected an object');
        }
        $members = Json::members($value);
        $object = [];
        foreach ($required as $name => $read) {
            if (!array_key_exists($name, $members)) {
                throw new ShapeError(Json::pointer($pointer, $name), 'is required');
            }
            $member = $read($members[$name], Json::pointer($pointer, $name));
            if ($member === $this->drop) {
                return $this->drop;
            }
            $object[$name] = $member;
        }
        foreach ($optional as $name => $read) {
            if (!array_key_exists($name, $members)) {
                continue;
            }
            if ($members[$name] === null) {
                if (!$this->strict && in_array($name, $nullMeansAbsent, true)) {
                    $this->stripped[] = Json::pointer($pointer, $name) . ': null read as absent';
                    continue;
                }
                throw new ShapeError(Json::pointer($pointer, $name), 'is optional: omit it rather than sending null');
            }
            $member = $read($members[$name], Json::pointer($pointer, $name));
            if ($member !== $this->drop) {
                $object[$name] = $member;
            }
        }
        foreach (array_keys(array_diff_key($members, $required, $optional)) as $name) {
            $this->unrecognised(Json::pointer($pointer, $name), 'is not declared by AG-UI 1.0');
        }
        return $object;
    }

    /**
     * A closed object whose unrecognised required member cannot be dropped.
     *
     * @param array<string, \Closure(mixed, string): mixed> $required
     * @param array<string, \Closure(mixed, string): mixed> $optional
     * @param list<string> $nullMeansAbsent
     * @return array<string, mixed>
     */
    private function closedOrFail(mixed $value, string $pointer, array $required, array $optional = [], array $nullMeansAbsent = []): array
    {
        $object = $this->closed($value, $pointer, $required, $optional, $nullMeansAbsent);
        if (!is_array($object)) {
            throw new ShapeError($pointer, 'carries a value this version does not describe');
        }
        return $object;
    }

    /**
     * @param \Closure(mixed, string): mixed $read
     * @return list<mixed>
     */
    private function listOf(mixed $value, string $pointer, \Closure $read): array
    {
        if (!Json::isList($value)) {
            throw new ShapeError($pointer, 'expected a list');
        }
        $items = [];
        foreach ($value as $index => $item) {
            $parsed = $read($item, Json::pointer($pointer, $index));
            if ($parsed !== $this->drop) {
                $items[] = $parsed;
            }
        }
        return $items;
    }

    /**
     * Material this version does not describe: an error in strict mode, a
     * recorded removal in lenient mode.
     */
    private function unrecognised(string $pointer, string $reason): \stdClass
    {
        if ($this->strict) {
            throw new ShapeError($pointer, $reason);
        }
        $this->stripped[] = $pointer . ': ' . $reason;
        return $this->drop;
    }

    private function string(mixed $value, string $pointer): string
    {
        return is_string($value) ? $value : throw new ShapeError($pointer, 'expected a string');
    }

    private function integer(mixed $value, string $pointer): int
    {
        return is_int($value) ? $value : throw new ShapeError($pointer, 'expected an integer');
    }

    private function notNull(mixed $value, string $pointer): mixed
    {
        return $value !== null ? Json::toPhp($value) : throw new ShapeError($pointer, 'must not be null');
    }

    private function openObject(mixed $value, string $pointer): mixed
    {
        return Json::isObject($value) ? Json::toPhp($value) : throw new ShapeError($pointer, 'expected an object');
    }

    /**
     * @param list<string> $allowed
     */
    private function oneOf(mixed $value, string $pointer, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ShapeError($pointer, 'must be ' . implode(' or ', array_map(static fn(string $v): string => '"' . $v . '"', $allowed)));
        }
        return $value;
    }
}
