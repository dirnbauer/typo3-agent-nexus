<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

/**
 * The eight event families of AG-UI 1.0, in the order the specification lists
 * them (docs.ag-ui.com/spec/1.0/basic, "The events").
 */
enum EventFamily: string
{
    case Lifecycle = 'lifecycle';
    case TextMessages = 'text';
    case ToolCalls = 'tool';
    case Reasoning = 'reasoning';
    case State = 'state';
    case Activity = 'activity';
    case Subagents = 'subagents';
    case Passthrough = 'passthrough';

    /** The family's name as the specification spells it. */
    public function title(): string
    {
        return match ($this) {
            self::Lifecycle => 'Runs and steps',
            self::TextMessages => 'Text messages',
            self::ToolCalls => 'Tool calls',
            self::Reasoning => 'Reasoning',
            self::State => 'State',
            self::Activity => 'Activity',
            self::Subagents => 'Subagents',
            self::Passthrough => 'Passthrough',
        };
    }

    /**
     * @return list<EventType>
     */
    public function events(): array
    {
        return array_values(array_filter(
            EventType::cases(),
            fn(EventType $type): bool => $type->family() === $this,
        ));
    }
}
