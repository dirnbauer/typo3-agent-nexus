<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use Webconsulting\AgentNexus\A2a\Protocol\Artifact;
use Webconsulting\AgentNexus\A2a\Protocol\TaskState;

/**
 * One thing the agent does in a turn: move the task to a state and say why,
 * or produce an artifact.
 *
 * An artifact is produced lazily — when the task reaches that step — so a
 * slow model call happens after the client has already seen the task start
 * working, not before.
 */
final readonly class PlanStep
{
    /**
     * @param array<string, mixed> $eventMetadata metadata of the status update event
     * @param (\Closure(): Artifact)|null $artifact
     */
    private function __construct(
        public ?TaskState $state,
        public string $text,
        public array $eventMetadata,
        private ?\Closure $artifact,
    ) {}

    /**
     * @param array<string, mixed> $eventMetadata
     */
    public static function status(TaskState $state, string $text, array $eventMetadata = []): self
    {
        return new self($state, $text, $eventMetadata, null);
    }

    /**
     * @param \Closure(): Artifact $produce
     */
    public static function artifact(\Closure $produce): self
    {
        return new self(null, '', [], $produce);
    }

    public function isArtifact(): bool
    {
        return $this->artifact !== null;
    }

    public function produceArtifact(): Artifact
    {
        if ($this->artifact === null) {
            throw new \LogicException('This step moves the task to a state; it produces no artifact.', 1758700001);
        }
        return ($this->artifact)();
    }
}
