<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * How the agent answers a renderer message: the messages it sends back and
 * the state the surface moves to (null: it stays where it is).
 */
final readonly class ActionOutcome
{
    /**
     * @param list<array<string, mixed>> $messages
     */
    public function __construct(
        public array $messages,
        public ?string $state = null,
        public string $note = '',
    ) {}
}
