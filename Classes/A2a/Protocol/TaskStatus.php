<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * Where a task stands: its state, optionally a message from the agent, and
 * when the status was recorded.
 */
final readonly class TaskStatus
{
    public function __construct(
        public TaskState $state,
        public ?Message $message = null,
        public string $timestamp = '',
    ) {}

    public static function now(TaskState $state, ?Message $message = null): self
    {
        return new self($state, $message, Timestamp::now());
    }

    public static function fromArray(mixed $json, string $path): self
    {
        $status = Json::object($json, $path);
        $stateValue = Json::requiredString($status, 'state', $path);
        $state = TaskState::tryFrom($stateValue)
            ?? throw A2aException::invalidParams($path . '.state', 'is not a task state.');
        $message = isset($status['message']) ? Message::fromArray($status['message'], $path . '.message') : null;

        return new self($state, $message, Json::string($status, 'timestamp', $path));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $json = ['state' => $this->state->value];
        if ($this->message !== null) {
            $json['message'] = $this->message->toArray();
        }
        if ($this->timestamp !== '') {
            $json['timestamp'] = $this->timestamp;
        }
        return $json;
    }
}
