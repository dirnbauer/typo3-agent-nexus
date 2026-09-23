<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

/**
 * What the agent will do with one message: which skill handles it, how it was
 * chosen, and the steps from here to the next pause or the end of the task.
 */
final readonly class TurnPlan
{
    /**
     * @param list<PlanStep> $steps
     * @param array<string, mixed> $taskMetadata merged into the task's metadata
     */
    public function __construct(
        public string $skillId,
        public string $skillName,
        public array $steps,
        public array $taskMetadata = [],
    ) {}
}
