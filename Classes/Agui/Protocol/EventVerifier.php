<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

/**
 * Checks an AG-UI event stream against the 1.0 specification, one event at a
 * time: the shape of every event (closed objects, required fields, no `null`
 * for an optional field) and the ordering rules a producer must keep
 * ({@see OrderingRule}).
 *
 * It holds what the 1.0 client enforces — a stream that passes here is one a
 * 1.0 client accepts without failing the run — and it is stricter than a
 * client in one direction on purpose: a client strips material it does not
 * recognise, a producer must never send it, so here it is a violation.
 *
 * The first rule broken ends verification with a {@see ProtocolViolation}.
 * Every stream Agent Nexus produces runs through it in the tests, and in
 * Development context also at runtime.
 */
final class EventVerifier
{
    /** Lane of the parent agent; a subagent's lane is its subagentRunId. */
    private const string ROOT = '';

    private const string INITIAL = 'initial';
    private const string ACTIVE = 'active';
    private const string CLOSED = 'closed';
    private const string FAILED = 'failed';

    private readonly ShapeReader $shapes;

    private int $position = -1;
    private string $runState = self::INITIAL;
    private ?string $threadId = null;
    private ?string $runId = null;
    private ?string $lastRunId = null;

    /** @var array<string, true> */
    private array $runIds = [];

    /** @var array<string, string> open text messages: id => owner */
    private array $openText = [];
    /** @var array<string, string> open tool calls: id => owner */
    private array $openTools = [];
    /** @var array<string, string> open reasoning messages: id => owner */
    private array $openReasoning = [];
    /** @var array<string, string> open reasoning spans: id => owner */
    private array $openSpans = [];
    /** @var array<string, string> open steps: owner + NUL + name => name */
    private array $openSteps = [];
    /** @var array<string, true> */
    private array $activeSubagents = [];
    /** @var array<string, true> */
    private array $finishedSubagents = [];
    /** @var array<string, true> subagents that had work attributed to them before any announcement */
    private array $unannounced = [];
    /** @var list<string> tool calls started in the current run, in order */
    private array $startedToolCalls = [];
    /** @var array<string, true> tool calls answered in the current run */
    private array $answeredToolCalls = [];
    /** @var array<string, array{kind: string, id: string, role: string, name: string, toolCallName: string, parentMessageId: string}> open chunk streams per lane */
    private array $chunks = [];

    /** @var array<string, string> every messageId seen in the thread: id => kind (text, reasoning, tool, activity) */
    private array $messageKinds = [];
    /** @var array<string, string> text messages ever opened: id => owner */
    private array $textOwners = [];
    /** @var array<string, string> activity messages: id => owner */
    private array $activities = [];

    /**
     * @param string|null $expectedThreadId the input's threadId every run must carry
     * @param string|null $expectedRunId    the input's runId the requested run must echo
     * @param bool $requireProtocolVersion whether RUN_STARTED must declare "1.0",
     *                                     as a 1.0 producer must; off for streams from older producers
     */
    public function __construct(
        private readonly ?string $expectedThreadId = null,
        private readonly ?string $expectedRunId = null,
        private readonly bool $requireProtocolVersion = true,
    ) {
        $this->shapes = new ShapeReader(strict: true);
    }

    /**
     * Verify a whole stream.
     *
     * @param iterable<array<array-key, mixed>|\stdClass> $events
     * @throws ProtocolViolation
     */
    public static function verify(iterable $events, ?string $threadId = null, ?string $runId = null, bool $requireProtocolVersion = true): void
    {
        $verifier = new self($threadId, $runId, $requireProtocolVersion);
        foreach ($events as $event) {
            $verifier->accept($event);
        }
        $verifier->finish();
    }

    /**
     * Pass a stream through, verifying each event before it is handed on.
     *
     * @template T of array<string, mixed>
     * @param iterable<T> $events
     * @return \Generator<int, T>
     * @throws ProtocolViolation
     */
    public static function guard(iterable $events, ?string $threadId = null, ?string $runId = null): \Generator
    {
        $verifier = new self($threadId, $runId);
        foreach ($events as $event) {
            $verifier->accept($event);
            yield $event;
        }
        $verifier->finish();
    }

    /**
     * @param array<array-key, mixed>|\stdClass $event anything a stream may carry; what is not an event is a violation
     * @throws ProtocolViolation
     */
    public function accept(array|\stdClass $event): void
    {
        $this->position++;
        if (!Json::isObject($event)) {
            $this->fail(OrderingRule::ClosedObjects, 'an event is a JSON object');
        }
        $fields = Json::members($event);
        $typeName = $fields['type'] ?? null;
        if (!is_string($typeName)) {
            $this->fail(OrderingRule::ClosedObjects, 'the event has no "type"');
        }
        $type = EventType::tryFrom($typeName) ?? $this->fail(
            OrderingRule::ClosedObjects,
            sprintf('"%s" is not an AG-UI 1.0 event type', $typeName),
        );

        $this->checkShape($type, $fields);
        $tag = $type->attributable() && is_string($fields['subagentRunId'] ?? null) ? $fields['subagentRunId'] : null;

        $this->gate($type);
        $this->closeChunks($type, $fields, $tag);
        if ($tag !== null) {
            $this->attribute($tag);
        }

        match ($type) {
            EventType::RunStarted => $this->runStarted($fields),
            EventType::RunFinished => $this->runFinished($fields),
            EventType::RunError => $this->runState = self::FAILED,
            EventType::StepStarted, EventType::StepFinished => $this->step($type, $this->string($fields, 'stepName'), $tag ?? self::ROOT),
            EventType::TextMessageStart => $this->openText($this->string($fields, 'messageId'), $tag ?? self::ROOT),
            EventType::TextMessageContent, EventType::TextMessageEnd => $this->continueItem($type, $this->openText, $this->string($fields, 'messageId'), $tag, 'text message'),
            EventType::ToolCallStart => $this->openTool($fields, $tag),
            EventType::ToolCallArgs, EventType::ToolCallEnd => $this->continueItem($type, $this->openTools, $this->string($fields, 'toolCallId'), $tag, 'tool call'),
            EventType::ToolCallResult => $this->toolResult($fields),
            EventType::ReasoningStart => $this->openSpan($this->string($fields, 'messageId'), $tag ?? self::ROOT),
            EventType::ReasoningEnd => $this->closeSpan($this->string($fields, 'messageId'), $tag),
            EventType::ReasoningMessageStart => $this->openReasoning($this->string($fields, 'messageId'), $tag ?? self::ROOT),
            EventType::ReasoningMessageContent, EventType::ReasoningMessageEnd => $this->continueReasoning($type, $this->string($fields, 'messageId'), $tag),
            EventType::TextMessageChunk, EventType::ToolCallChunk, EventType::ReasoningMessageChunk => $this->chunk($type, $fields, $tag),
            EventType::MessagesSnapshot => $this->messagesSnapshot($fields),
            EventType::ActivitySnapshot => $this->activitySnapshot($fields, $tag),
            EventType::ActivityDelta => $this->activityDelta($fields, $tag),
            EventType::SubagentStarted => $this->subagentStarted($fields),
            EventType::SubagentFinished, EventType::SubagentError => $this->subagentEnded($type, $this->string($fields, 'subagentRunId')),
            EventType::StateSnapshot, EventType::StateDelta, EventType::ReasoningEncryptedValue, EventType::Raw, EventType::Custom => null,
        };
    }

    /**
     * The stream is over: it must have ended with a terminal event.
     *
     * @throws ProtocolViolation
     */
    public function finish(): void
    {
        if ($this->runState === self::INITIAL) {
            $this->fail(OrderingRule::TerminalEvent, 'the stream ended without a single event');
        }
        if ($this->runState === self::ACTIVE) {
            $this->fail(OrderingRule::TerminalEvent, sprintf('the stream ended while run "%s" was still active; end it with RUN_FINISHED or RUN_ERROR', $this->runId));
        }
        if ($this->expectedRunId !== null && $this->lastRunId !== null && $this->lastRunId !== $this->expectedRunId) {
            $this->fail(OrderingRule::RunIdentity, sprintf('the requested run "%s" was not the stream\'s last run ("%s")', $this->expectedRunId, $this->lastRunId));
        }
    }

    // ---- shape ----------------------------------------------------------

    /**
     * @param array<string, mixed> $fields
     */
    private function checkShape(EventType $type, array $fields): void
    {
        $declared = array_flip($type->fieldNames());
        foreach (array_keys($fields) as $name) {
            if (!isset($declared[$name])) {
                $this->fail(OrderingRule::ClosedObjects, sprintf('%s has no field "%s"; AG-UI 1.0 objects are closed', $type->value, $name));
            }
        }
        foreach ($type->required() as $name => $fieldType) {
            if (!array_key_exists($name, $fields)) {
                $this->fail(OrderingRule::ClosedObjects, sprintf('%s is missing its required field "%s"', $type->value, $name));
            }
            $this->read($type, $fieldType, $name, $fields[$name]);
        }
        $optional = $type->optional() + ($type->attributable() ? ['subagentRunId' => FieldType::String] : []) + EventType::ENVELOPE;
        foreach ($optional as $name => $fieldType) {
            if (!array_key_exists($name, $fields)) {
                continue;
            }
            if ($fields[$name] === null) {
                $this->fail(OrderingRule::ClosedObjects, sprintf('%s.%s is null; an optional field without a value is omitted', $type->value, $name));
            }
            $this->read($type, $fieldType, $name, $fields[$name]);
        }
    }

    private function read(EventType $type, FieldType $fieldType, string $name, mixed $value): void
    {
        try {
            $this->shapes->field($fieldType, $value, '/' . $name);
        } catch (ShapeError $e) {
            $this->fail(OrderingRule::ClosedObjects, $type->value . $e->getMessage());
        }
    }

    // ---- run lifecycle --------------------------------------------------

    private function gate(EventType $type): void
    {
        $allowed = match ($this->runState) {
            self::INITIAL => $type === EventType::RunStarted || $type === EventType::RunError,
            self::ACTIVE => $type !== EventType::RunStarted,
            self::CLOSED => $type === EventType::RunStarted || $type === EventType::RunError,
            default => $type === EventType::RunStarted,
        };
        if ($allowed) {
            return;
        }
        match ($this->runState) {
            self::INITIAL => $this->fail(OrderingRule::FirstEvent, sprintf('the stream starts with %s; it must start with RUN_STARTED or RUN_ERROR', $type->value)),
            self::ACTIVE => $this->fail(OrderingRule::OneActiveRun, sprintf('RUN_STARTED while run "%s" is still active', $this->runId)),
            self::CLOSED => $this->fail(OrderingRule::ClosedRun, sprintf('%s after RUN_FINISHED; only RUN_STARTED or RUN_ERROR may follow', $type->value)),
            default => $this->fail(OrderingRule::FailedRun, sprintf('%s after RUN_ERROR; only RUN_STARTED may follow', $type->value)),
        };
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function runStarted(array $fields): void
    {
        $threadId = $this->string($fields, 'threadId');
        $runId = $this->string($fields, 'runId');
        if ($this->expectedThreadId !== null && $threadId !== $this->expectedThreadId) {
            $this->fail(OrderingRule::RunIdentity, sprintf('RUN_STARTED carries thread "%s", the input asked for "%s"', $threadId, $this->expectedThreadId));
        }
        if ($this->threadId !== null && $threadId !== $this->threadId) {
            $this->fail(OrderingRule::RunIdentity, sprintf('RUN_STARTED switches the thread from "%s" to "%s"', $this->threadId, $threadId));
        }
        if (isset($this->runIds[$runId])) {
            $this->fail(OrderingRule::RunIdentity, sprintf('run id "%s" is used a second time', $runId));
        }
        if ($this->requireProtocolVersion && ($fields['protocolVersion'] ?? null) !== EventType::PROTOCOL_VERSION) {
            $this->fail(OrderingRule::ProtocolVersion, sprintf('RUN_STARTED must declare protocolVersion "%s"', EventType::PROTOCOL_VERSION));
        }

        $this->threadId = $threadId;
        $this->runId = $runId;
        $this->lastRunId = $runId;
        $this->runIds[$runId] = true;
        $this->runState = self::ACTIVE;
        $this->openText = $this->openTools = $this->openReasoning = $this->openSpans = $this->openSteps = [];
        $this->activeSubagents = $this->finishedSubagents = $this->unannounced = $this->answeredToolCalls = [];
        $this->startedToolCalls = [];
        $this->chunks = [];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function runFinished(array $fields): void
    {
        if ($this->string($fields, 'threadId') !== $this->threadId || $this->string($fields, 'runId') !== $this->runId) {
            $this->fail(OrderingRule::RunIdentity, sprintf(
                'RUN_FINISHED for thread "%s", run "%s" closes run "%s" of thread "%s"',
                $this->string($fields, 'threadId'),
                $this->string($fields, 'runId'),
                $this->runId,
                $this->threadId,
            ));
        }
        $open = array_filter([
            'text messages' => array_keys($this->openText),
            'tool calls' => array_keys($this->openTools),
            'reasoning messages' => array_keys($this->openReasoning),
            'reasoning spans' => array_keys($this->openSpans),
            'steps' => array_values($this->openSteps),
            'subagents' => array_keys($this->activeSubagents),
        ]);
        foreach ($open as $what => $ids) {
            $this->fail(OrderingRule::ClosedBeforeFinish, sprintf('RUN_FINISHED while %s are still open: %s', $what, implode(', ', $ids)));
        }

        $outcome = Json::members($fields['outcome'] ?? []);
        if (($outcome['type'] ?? null) === 'interrupt') {
            $ids = array_map(static fn(mixed $interrupt): mixed => Json::members($interrupt)['id'] ?? null, is_array($outcome['interrupts'] ?? null) ? $outcome['interrupts'] : []);
            if (count($ids) !== count(array_unique($ids, SORT_REGULAR))) {
                $this->fail(OrderingRule::Outcome, 'interrupt ids must be unique within the run');
            }
        }
        $pending = is_array($outcome['pendingToolCallIds'] ?? null) ? $outcome['pendingToolCallIds'] : [];
        if ($pending !== []) {
            $unanswered = array_values(array_filter($this->startedToolCalls, fn(string $id): bool => !isset($this->answeredToolCalls[$id])));
            if ($pending !== $unanswered) {
                $this->fail(OrderingRule::Outcome, sprintf(
                    'pendingToolCallIds [%s] must name exactly the unanswered tool calls [%s]',
                    implode(', ', array_map(strval(...), array_filter($pending, is_string(...)))),
                    implode(', ', $unanswered),
                ));
            }
        }
        $this->runState = self::CLOSED;
    }

    private function step(EventType $type, string $name, string $owner): void
    {
        $key = $owner . "\0" . $name;
        if ($type === EventType::StepStarted) {
            if (isset($this->openSteps[$key])) {
                $this->fail(OrderingRule::StepsBalanced, sprintf('step "%s" is already open', $name));
            }
            $this->openSteps[$key] = $name;
            return;
        }
        if (!isset($this->openSteps[$key])) {
            $this->fail(OrderingRule::StepsBalanced, sprintf('STEP_FINISHED for step "%s" that was not started', $name));
        }
        unset($this->openSteps[$key]);
    }

    // ---- streamed items -------------------------------------------------

    private function openText(string $id, string $owner): void
    {
        if (isset($this->openText[$id])) {
            $this->fail(OrderingRule::NoDoubleOpen, sprintf('text message "%s" is already open', $id));
        }
        $this->claimMessageId($id, 'text');
        $this->openText[$id] = $owner;
        $this->textOwners[$id] = $owner;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function openTool(array $fields, ?string $tag): void
    {
        $id = $this->string($fields, 'toolCallId');
        if (isset($this->openTools[$id])) {
            $this->fail(OrderingRule::NoDoubleOpen, sprintf('tool call "%s" is already open', $id));
        }
        $parent = is_string($fields['parentMessageId'] ?? null) ? $fields['parentMessageId'] : null;
        $parentOwner = $parent !== null ? ($this->textOwners[$parent] ?? null) : null;
        if ($tag !== null && $parentOwner !== null && $parentOwner !== $tag) {
            $this->fail(OrderingRule::Attribution, sprintf('tool call "%s" is attributed to "%s", its message "%s" to "%s"', $id, $tag, $parent, $parentOwner));
        }
        $this->openTools[$id] = $tag ?? $parentOwner ?? self::ROOT;
        $this->startedToolCalls[] = $id;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function toolResult(array $fields): void
    {
        $this->claimMessageId($this->string($fields, 'messageId'), 'tool');
        $this->answeredToolCalls[$this->string($fields, 'toolCallId')] = true;
    }

    private function openSpan(string $id, string $owner): void
    {
        if (isset($this->openSpans[$id])) {
            $this->fail(OrderingRule::NoDoubleOpen, sprintf('reasoning span "%s" is already open', $id));
        }
        $this->openSpans[$id] = $owner;
    }

    private function closeSpan(string $id, ?string $tag): void
    {
        if (!isset($this->openSpans[$id])) {
            isset($this->openReasoning[$id])
                ? $this->fail(OrderingRule::ReasoningNamespaces, sprintf('REASONING_END names "%s", which is a reasoning message, not a span', $id))
                : $this->fail(OrderingRule::OpenBeforeContinue, sprintf('REASONING_END for span "%s" that is not open', $id));
        }
        $this->checkOwner('reasoning span', $id, $this->openSpans[$id], $tag);
        unset($this->openSpans[$id]);
    }

    private function openReasoning(string $id, string $owner): void
    {
        if (isset($this->openReasoning[$id])) {
            $this->fail(OrderingRule::NoDoubleOpen, sprintf('reasoning message "%s" is already open', $id));
        }
        $this->claimMessageId($id, 'reasoning');
        $this->openReasoning[$id] = $owner;
    }

    private function continueReasoning(EventType $type, string $id, ?string $tag): void
    {
        if (!isset($this->openReasoning[$id]) && isset($this->openSpans[$id])) {
            $this->fail(OrderingRule::ReasoningNamespaces, sprintf('%s names "%s", which is a reasoning span, not a reasoning message', $type->value, $id));
        }
        $this->continueItem($type, $this->openReasoning, $id, $tag, 'reasoning message');
    }

    /**
     * CONTENT/ARGS/END: the item must be open, a tag must agree with its
     * owner, and END closes it.
     *
     * @param array<string, string> $open
     */
    private function continueItem(EventType $type, array &$open, string $id, ?string $tag, string $what): void
    {
        if (!isset($open[$id])) {
            $this->fail(OrderingRule::OpenBeforeContinue, sprintf('%s for %s "%s", which is not open', $type->value, $what, $id));
        }
        $this->checkOwner($what, $id, $open[$id], $tag);
        if (str_ends_with($type->value, '_END')) {
            unset($open[$id]);
        }
    }

    private function checkOwner(string $what, string $id, string $owner, ?string $tag): void
    {
        if ($tag !== null && $tag !== $owner) {
            $this->fail(OrderingRule::Attribution, sprintf(
                '%s "%s" was opened by %s; this event is attributed to "%s", which does not match',
                $what,
                $id,
                $owner === self::ROOT ? 'the parent agent' : '"' . $owner . '"',
                $tag,
            ));
        }
    }

    private function claimMessageId(string $id, string $kind): void
    {
        $known = $this->messageKinds[$id] ?? null;
        if ($known !== null && ($known !== $kind || $kind === 'tool')) {
            $this->fail(OrderingRule::UniqueMessageIds, sprintf('messageId "%s" already names a %s message in this thread', $id, $known));
        }
        $this->messageKinds[$id] = $kind;
    }

    // ---- chunks ---------------------------------------------------------

    /**
     * @param array<string, mixed> $fields
     */
    private function chunk(EventType $type, array $fields, ?string $tag): void
    {
        $kind = match ($type) {
            EventType::ToolCallChunk => 'tool',
            EventType::ReasoningMessageChunk => 'reasoning',
            default => 'text',
        };
        $idField = $kind === 'tool' ? 'toolCallId' : 'messageId';
        $id = is_string($fields[$idField] ?? null) ? $fields[$idField] : null;
        $lane = $tag ?? $this->chunkLane($type, $kind, $id);
        $open = $this->chunks[$lane] ?? null;

        if ($open !== null && ($open['kind'] !== $kind || ($id !== null && $id !== $open['id']))) {
            $this->closeChunk($lane);
            $open = null;
        }

        if ($open === null) {
            if ($id === null) {
                $this->fail(OrderingRule::ChunkForm, sprintf('the first %s of an item must carry "%s"', $type->value, $idField));
            }
            $role = is_string($fields['role'] ?? null) ? $fields['role'] : 'assistant';
            $toolCallName = is_string($fields['toolCallName'] ?? null) ? $fields['toolCallName'] : '';
            $parentMessageId = is_string($fields['parentMessageId'] ?? null) ? $fields['parentMessageId'] : '';
            match ($kind) {
                'tool' => $toolCallName === ''
                    ? $this->fail(OrderingRule::ChunkForm, 'the first TOOL_CALL_CHUNK of a call must carry "toolCallName"')
                    : $this->openTool(['toolCallId' => $id, 'parentMessageId' => $parentMessageId === '' ? null : $parentMessageId], $tag),
                'reasoning' => $this->openReasoning($id, $lane),
                default => $this->openText($id, $lane),
            };
            $this->chunks[$lane] = [
                'kind' => $kind,
                'id' => $id,
                'role' => $role,
                'name' => is_string($fields['name'] ?? null) ? $fields['name'] : '',
                'toolCallName' => $toolCallName,
                'parentMessageId' => $parentMessageId,
            ];
            return;
        }

        foreach (['role', 'name', 'toolCallName', 'parentMessageId'] as $field) {
            if (is_string($fields[$field] ?? null) && $open[$field] !== '' && $fields[$field] !== $open[$field]) {
                $this->fail(OrderingRule::ChunkForm, sprintf(
                    '%s "%s" does not match the open stream\'s %s "%s"',
                    $field,
                    $fields[$field],
                    $field,
                    $open[$field],
                ));
            }
        }
    }

    /**
     * Which lane an untagged chunk continues: the one assembling its id, the
     * parent agent's, or the only lane with a stream of its kind.
     */
    private function chunkLane(EventType $type, string $kind, ?string $id): string
    {
        $lanes = array_keys(array_filter($this->chunks, static fn(array $open): bool => $open['kind'] === $kind));
        if ($id !== null) {
            foreach ($lanes as $lane) {
                if ($this->chunks[$lane]['id'] === $id) {
                    return (string)$lane;
                }
            }
            return self::ROOT;
        }
        if (in_array(self::ROOT, $lanes, true) || $lanes === []) {
            return self::ROOT;
        }
        if (count($lanes) > 1) {
            $this->fail(OrderingRule::ChunkForm, sprintf('an untagged %s is ambiguous: several subagents have a stream of its kind open', $type->value));
        }
        return (string)$lanes[0];
    }

    /**
     * An open chunk stream ends when anything but an aside arrives in its
     * lane; run-level events end every lane, a subagent's terminal event
     * ends that subagent's lane.
     *
     * @param array<string, mixed> $fields
     */
    private function closeChunks(EventType $type, array $fields, ?string $tag): void
    {
        match ($type) {
            EventType::RunStarted, EventType::RunFinished, EventType::RunError, EventType::MessagesSnapshot => array_map($this->closeChunk(...), array_map(strval(...), array_keys($this->chunks))),
            EventType::SubagentFinished, EventType::SubagentError => $this->closeChunk($this->string($fields, 'subagentRunId')),
            EventType::Raw, EventType::ActivitySnapshot, EventType::ActivityDelta, EventType::ReasoningEncryptedValue, EventType::SubagentStarted,
            EventType::TextMessageChunk, EventType::ToolCallChunk, EventType::ReasoningMessageChunk => null,
            default => $this->closeChunk($tag ?? self::ROOT),
        };
    }

    private function closeChunk(string $lane): void
    {
        $open = $this->chunks[$lane] ?? null;
        if ($open === null) {
            return;
        }
        match ($open['kind']) {
            'tool' => $this->closeOpen($this->openTools, $open['id']),
            'reasoning' => $this->closeOpen($this->openReasoning, $open['id']),
            default => $this->closeOpen($this->openText, $open['id']),
        };
        unset($this->chunks[$lane]);
    }

    /**
     * @param array<string, string> $open
     */
    private function closeOpen(array &$open, string $id): void
    {
        unset($open[$id]);
    }

    // ---- snapshots, activity, subagents ---------------------------------

    /**
     * @param array<string, mixed> $fields
     */
    private function messagesSnapshot(array $fields): void
    {
        $messages = is_array($fields['messages'] ?? null) ? $fields['messages'] : [];
        foreach ($messages as $message) {
            $members = Json::members($message);
            $id = is_string($members['id'] ?? null) ? $members['id'] : '';
            $kind = match ($members['role'] ?? null) {
                'tool' => 'restated-tool',
                'reasoning' => 'reasoning',
                'activity' => 'activity',
                default => 'text',
            };
            $known = $this->messageKinds[$id] ?? null;
            if ($known !== null && $known !== $kind && !($known === 'tool' && $kind === 'restated-tool')) {
                $this->fail(OrderingRule::UniqueMessageIds, sprintf('MESSAGES_SNAPSHOT restates "%s" as a different kind of message', $id));
            }
            $this->messageKinds[$id] ??= $kind === 'restated-tool' ? 'tool' : $kind;
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function activitySnapshot(array $fields, ?string $tag): void
    {
        $id = $this->string($fields, 'messageId');
        $known = $this->messageKinds[$id] ?? null;
        if ($known !== null && $known !== 'activity') {
            $this->fail(OrderingRule::UniqueMessageIds, sprintf('messageId "%s" already names a %s message in this thread', $id, $known));
        }
        $this->messageKinds[$id] = 'activity';
        if (!isset($this->activities[$id]) || ($fields['replace'] ?? true) !== false) {
            $this->activities[$id] = $tag ?? self::ROOT;
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function activityDelta(array $fields, ?string $tag): void
    {
        $id = $this->string($fields, 'messageId');
        if (!isset($this->activities[$id])) {
            $this->fail(OrderingRule::ActivityBaseline, sprintf('ACTIVITY_DELTA for "%s" before any ACTIVITY_SNAPSHOT created it', $id));
        }
        $this->checkOwner('activity', $id, $this->activities[$id], $tag);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function subagentStarted(array $fields): void
    {
        $id = $this->string($fields, 'subagentRunId');
        if (isset($this->activeSubagents[$id])) {
            $this->fail(OrderingRule::SubagentLifecycle, sprintf('subagent "%s" is already active', $id));
        }
        if (isset($this->finishedSubagents[$id])) {
            $this->fail(OrderingRule::SubagentLifecycle, sprintf('subagent id "%s" already finished in this run; ids are per invocation', $id));
        }
        if (isset($this->unannounced[$id])) {
            $this->fail(OrderingRule::SubagentLifecycle, sprintf('subagent "%s" is announced after work was attributed to it', $id));
        }
        $parent = is_string($fields['parentSubagentRunId'] ?? null) ? $fields['parentSubagentRunId'] : null;
        if ($parent !== null && !isset($this->activeSubagents[$parent]) && !isset($this->finishedSubagents[$parent])) {
            $this->fail(OrderingRule::SubagentLifecycle, sprintf('parentSubagentRunId "%s" has not been started in this run', $parent));
        }
        $this->activeSubagents[$id] = true;
    }

    private function subagentEnded(EventType $type, string $id): void
    {
        if (!isset($this->activeSubagents[$id])) {
            $this->fail(OrderingRule::SubagentLifecycle, sprintf('%s: no active subagent "%s"; SUBAGENT_STARTED comes first', $type->value, $id));
        }
        unset($this->activeSubagents[$id]);
        $this->finishedSubagents[$id] = true;
    }

    private function attribute(string $subagentRunId): void
    {
        if (isset($this->finishedSubagents[$subagentRunId])) {
            $this->fail(OrderingRule::SubagentLifecycle, sprintf('work attributed to subagent "%s" after it finished', $subagentRunId));
        }
        if (!isset($this->activeSubagents[$subagentRunId])) {
            $this->unannounced[$subagentRunId] = true;
        }
    }

    // ---- helpers --------------------------------------------------------

    /**
     * @param array<string, mixed> $fields
     */
    private function string(array $fields, string $name): string
    {
        return is_string($fields[$name] ?? null) ? $fields[$name] : '';
    }

    private function fail(OrderingRule $rule, string $message): never
    {
        throw new ProtocolViolation($rule, $message, $this->position);
    }
}
