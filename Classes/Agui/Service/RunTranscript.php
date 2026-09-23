<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use Webconsulting\AgentNexus\Agui\Protocol\EventType;
use Webconsulting\AgentNexus\Agui\Protocol\Json;
use Webconsulting\AgentNexus\Agui\Protocol\JsonPatch;

/**
 * What a run produced, assembled from its events the way a client assembles
 * them: the messages (assistant text with its tool calls, reasoning, tool
 * results, activity), the shared state, and how the run ended.
 *
 * The run record keeps {@see self::payload()}; the inspector reads it.
 */
final class RunTranscript
{
    public const string RUNNING = 'running';
    public const string INTERRUPTED = 'interrupted';
    public const string FINISHED = 'finished';
    public const string ERROR = 'error';
    public const string CANCELLED = 'cancelled';

    /** @var array<string, array<string, mixed>> messages by id, in order of appearance */
    private array $messages = [];
    /** @var array<string, string> tool call id => id of the message that carries it */
    private array $toolCallOwners = [];
    private mixed $state;
    private bool $stateTouched = false;
    /** @var array<string, mixed>|null */
    private ?array $outcome = null;
    private mixed $result = null;
    private int $eventCount = 0;

    public function __construct(mixed $initialState = null)
    {
        $this->state = $initialState ?? new \stdClass();
    }

    /**
     * @param array<string, mixed> $event
     */
    public function apply(array $event): void
    {
        $this->eventCount++;
        $id = fn(string $field): string => is_string($event[$field] ?? null) ? $event[$field] : '';
        $text = fn(string $field): string => is_string($event[$field] ?? null) ? $event[$field] : '';

        switch (EventType::tryFrom(is_string($event['type'] ?? null) ? $event['type'] : '')) {
            case EventType::TextMessageStart:
                $this->messages[$id('messageId')] ??= ['id' => $id('messageId'), 'role' => $text('role') ?: 'assistant', 'content' => ''];
                break;
            case EventType::ReasoningMessageStart:
                $this->messages[$id('messageId')] ??= ['id' => $id('messageId'), 'role' => 'reasoning', 'content' => ''];
                break;
            case EventType::TextMessageContent:
            case EventType::ReasoningMessageContent:
                if (isset($this->messages[$id('messageId')])) {
                    $content = $this->messages[$id('messageId')]['content'] ?? '';
                    $this->messages[$id('messageId')]['content'] = (is_string($content) ? $content : '') . $text('delta');
                }
                break;
            case EventType::ToolCallStart:
                $owner = $id('parentMessageId') !== '' ? $id('parentMessageId') : $id('toolCallId');
                $this->messages[$owner] ??= ['id' => $owner, 'role' => 'assistant'];
                $calls = is_array($this->messages[$owner]['toolCalls'] ?? null) ? $this->messages[$owner]['toolCalls'] : [];
                $calls[] = ['id' => $id('toolCallId'), 'type' => 'function', 'function' => ['name' => $text('toolCallName'), 'arguments' => '']];
                $this->messages[$owner]['toolCalls'] = $calls;
                $this->toolCallOwners[$id('toolCallId')] = $owner;
                break;
            case EventType::ToolCallArgs:
                $this->appendArguments($id('toolCallId'), $text('delta'));
                break;
            case EventType::ToolCallResult:
                $this->messages[$id('messageId')] = [
                    'id' => $id('messageId'),
                    'role' => 'tool',
                    'content' => $event['content'] ?? '',
                    'toolCallId' => $id('toolCallId'),
                ];
                break;
            case EventType::ActivitySnapshot:
                $existing = $this->messages[$id('messageId')] ?? null;
                if ($existing === null || ($event['replace'] ?? true) !== false) {
                    $this->messages[$id('messageId')] = [
                        'id' => $id('messageId'),
                        'role' => 'activity',
                        'activityType' => $text('activityType'),
                        'content' => $event['content'] ?? new \stdClass(),
                    ];
                }
                break;
            case EventType::ActivityDelta:
                $this->patchActivity($id('messageId'), $event['patch'] ?? []);
                break;
            case EventType::StateSnapshot:
                $this->state = $event['snapshot'] ?? null;
                $this->stateTouched = true;
                break;
            case EventType::StateDelta:
                try {
                    $this->state = JsonPatch::apply($this->state, is_array($event['delta'] ?? null) ? array_values($event['delta']) : []);
                    $this->stateTouched = true;
                } catch (\InvalidArgumentException) {
                    // A delta that does not apply leaves the state as it was, as a client keeps it.
                }
                break;
            case EventType::RunFinished:
                $this->outcome = Json::members($event['outcome'] ?? ['type' => 'success']);
                $this->result = $event['result'] ?? null;
                break;
            case EventType::RunError:
                $this->outcome = array_filter(['type' => 'error', 'message' => $text('message'), 'code' => $text('code')], static fn(string $value): bool => $value !== '');
                break;
            default:
                break;
        }
    }

    /** The run state the outcome stands for. */
    public function state(): string
    {
        return match ($this->outcome['type'] ?? null) {
            null => self::RUNNING,
            'interrupt' => self::INTERRUPTED,
            'cancelled' => self::CANCELLED,
            'error' => self::ERROR,
            default => self::FINISHED,
        };
    }

    public function isOver(): bool
    {
        return $this->outcome !== null;
    }

    /** The `status` of the run's result ("sent", "applied", "declined"), or an empty string. */
    public function resultStatus(): string
    {
        return is_array($this->result) && is_string($this->result['status'] ?? null) ? $this->result['status'] : '';
    }

    public function eventCount(): int
    {
        return $this->eventCount;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function interrupts(): array
    {
        $interrupts = [];
        foreach (is_array($this->outcome['interrupts'] ?? null) ? $this->outcome['interrupts'] : [] as $interrupt) {
            $interrupts[] = Json::members($interrupt);
        }
        return $interrupts;
    }

    /** One line for the history: why the run ended. */
    public function note(): string
    {
        return match ($this->state()) {
            self::INTERRUPTED => 'Waiting for ' . implode(', ', array_map(
                static fn(array $interrupt): string => (is_string($interrupt['id'] ?? null) ? $interrupt['id'] : '?') . ' (' . (is_string($interrupt['reason'] ?? null) ? $interrupt['reason'] : '?') . ')',
                $this->interrupts(),
            )),
            self::ERROR => is_string($this->outcome['message'] ?? null) ? $this->outcome['message'] : '',
            self::FINISHED => $this->resultStatus() !== '' ? 'Result: ' . $this->resultStatus() : '',
            default => '',
        };
    }

    /**
     * The run record: the input, the outcome, what the run waited for or
     * returned, the messages it produced, the state it left and how many
     * events it streamed.
     *
     * @param array<string, mixed> $input RunAgentInput without this installation's extras
     * @return array<string, mixed>
     */
    public function payload(array $input): array
    {
        $payload = ['input' => $input];
        if ($this->outcome !== null) {
            $payload['outcome'] = $this->outcome;
        }
        if ($this->interrupts() !== []) {
            $payload['interrupts'] = $this->interrupts();
        }
        if ($this->result !== null) {
            $payload['result'] = $this->result;
        }
        if ($this->messages !== []) {
            $payload['messages'] = array_values($this->messages);
        }
        if ($this->stateTouched) {
            $payload['state'] = $this->state;
        }
        $payload['eventCount'] = $this->eventCount;
        return $payload;
    }

    /**
     * The arguments of a tool call a stored run proposed, decoded; empty when
     * the run has no such call or its arguments are not a JSON object.
     *
     * @param array<string, mixed> $payload a stored run's payload
     * @return array<string, mixed>|null
     */
    public static function proposal(array $payload, string $toolCallId): ?array
    {
        foreach (is_array($payload['messages'] ?? null) ? $payload['messages'] : [] as $message) {
            foreach (is_array($message) && is_array($message['toolCalls'] ?? null) ? $message['toolCalls'] : [] as $call) {
                if (!is_array($call) || ($call['id'] ?? null) !== $toolCallId) {
                    continue;
                }
                $arguments = is_array($call['function'] ?? null) && is_string($call['function']['arguments'] ?? null) ? $call['function']['arguments'] : '';
                $decoded = json_decode($arguments, true);
                return is_array($decoded) ? Json::members($decoded) : [];
            }
        }
        return null;
    }

    private function appendArguments(string $toolCallId, string $delta): void
    {
        $owner = $this->toolCallOwners[$toolCallId] ?? null;
        if ($owner === null || !is_array($this->messages[$owner]['toolCalls'] ?? null)) {
            return;
        }
        $calls = $this->messages[$owner]['toolCalls'];
        foreach ($calls as $index => $call) {
            if (is_array($call) && ($call['id'] ?? null) === $toolCallId && is_array($call['function'] ?? null)) {
                $arguments = $call['function']['arguments'] ?? '';
                $calls[$index]['function']['arguments'] = (is_string($arguments) ? $arguments : '') . $delta;
            }
        }
        $this->messages[$owner]['toolCalls'] = $calls;
    }

    private function patchActivity(string $messageId, mixed $patch): void
    {
        if (($this->messages[$messageId]['role'] ?? null) !== 'activity' || !is_array($patch)) {
            return;
        }
        try {
            $this->messages[$messageId]['content'] = JsonPatch::apply($this->messages[$messageId]['content'] ?? new \stdClass(), array_values($patch));
        } catch (\InvalidArgumentException) {
            // Skipped, as a client skips a delta that does not apply.
        }
    }
}
