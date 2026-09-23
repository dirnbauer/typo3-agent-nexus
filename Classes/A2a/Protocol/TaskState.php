<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * The lifecycle state of an A2A task, spelt as ProtoJSON spells the enum
 * (`TASK_STATE_WORKING`). A2A 0.3 used lower-case names with hyphens
 * (`working`, `input-required`); {@see legacyValue()} gives those for the 0.3
 * translation.
 *
 * `TASK_STATE_UNSPECIFIED` is deliberately not a case: a server never reports
 * it, and a client that sends it as a filter asked for nothing.
 */
enum TaskState: string
{
    case Submitted = 'TASK_STATE_SUBMITTED';
    case Working = 'TASK_STATE_WORKING';
    case Completed = 'TASK_STATE_COMPLETED';
    case Failed = 'TASK_STATE_FAILED';
    case Canceled = 'TASK_STATE_CANCELED';
    case InputRequired = 'TASK_STATE_INPUT_REQUIRED';
    case Rejected = 'TASK_STATE_REJECTED';
    case AuthRequired = 'TASK_STATE_AUTH_REQUIRED';

    /** The task is over; it accepts no more messages and cannot be canceled. */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Canceled, self::Rejected => true,
            default => false,
        };
    }

    /** The task waits for the client: a stream closes here, the task stays open. */
    public function isInterrupted(): bool
    {
        return $this === self::InputRequired || $this === self::AuthRequired;
    }

    /** A stream that reports this state closes after it. */
    public function endsStream(): bool
    {
        return $this->isTerminal() || $this->isInterrupted();
    }

    /** The A2A 0.3 name: `input-required`, `completed` … */
    public function legacyValue(): string
    {
        return str_replace('_', '-', strtolower(substr($this->value, strlen('TASK_STATE_'))));
    }

    public static function fromLegacy(string $value): ?self
    {
        return self::tryFrom('TASK_STATE_' . strtoupper(str_replace('-', '_', $value)));
    }
}
