<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Server;

use Webconsulting\AgentNexus\A2a\Protocol\Task;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;

/**
 * A task together with what the server keeps about it but never sends: who
 * created it (a widget, the API), the page folder it belongs to and the name
 * of the skill that handles it.
 */
final readonly class StoredTask
{
    public function __construct(
        public Task $task,
        public Channel $source = Channel::Api,
        public int $pid = 0,
        public string $label = '',
    ) {}

    public function withTask(Task $task): self
    {
        return new self($task, $this->source, $this->pid, $this->label);
    }

    public function withLabel(string $label): self
    {
        return new self($this->task, $this->source, $this->pid, $label);
    }
}
