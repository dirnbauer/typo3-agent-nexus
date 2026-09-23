<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

/**
 * The rules an AG-UI 1.0 event stream must follow beyond the shape of each
 * event, as the specification states them for producers.
 *
 * {@see EventVerifier} names the rule a stream broke; the backend event
 * reference lists the same rules (label key `rule.<value>` in
 * locallang_agui.xlf), so the two cannot drift apart.
 */
enum OrderingRule: string
{
    /** Every event is one the schema describes: declared fields only, optional fields omitted rather than null, integer timestamps. */
    case ClosedObjects = 'closed-objects';
    /** A stream begins with RUN_STARTED or RUN_ERROR. */
    case FirstEvent = 'first-event';
    /** No RUN_STARTED while a run is active. */
    case OneActiveRun = 'one-active-run';
    /** After RUN_FINISHED only RUN_STARTED or a late RUN_ERROR. */
    case ClosedRun = 'closed-run';
    /** After RUN_ERROR only RUN_STARTED. */
    case FailedRun = 'failed-run';
    /** A stream ends with RUN_FINISHED or RUN_ERROR. */
    case TerminalEvent = 'terminal-event';
    /** RUN_STARTED and RUN_FINISHED carry the input's thread and run id; run ids are never reused. */
    case RunIdentity = 'run-identity';
    /** A 1.0 producer declares its own version, "1.0", on RUN_STARTED. */
    case ProtocolVersion = 'protocol-version';
    /** No START for an id that is already open. */
    case NoDoubleOpen = 'no-double-open';
    /** CONTENT, ARGS and END only for an id that is open. */
    case OpenBeforeContinue = 'open-before-continue';
    /** Steps open and close by name, balanced. */
    case StepsBalanced = 'steps-balanced';
    /** Reasoning spans and reasoning messages are separate namespaces. */
    case ReasoningNamespaces = 'reasoning-namespaces';
    /** Nothing may be open at RUN_FINISHED: no step, message, tool call, reasoning span or subagent. */
    case ClosedBeforeFinish = 'closed-before-finish';
    /** A messageId names one message in the thread. */
    case UniqueMessageIds = 'unique-message-ids';
    /** ACTIVITY_DELTA only after an ACTIVITY_SNAPSHOT for the same messageId. */
    case ActivityBaseline = 'activity-baseline';
    /** A first chunk carries its ids; a continuation agrees with its opener; the two forms do not mix. */
    case ChunkForm = 'chunk-form';
    /** Subagents are announced before use, never reused, and closed before the run finishes. */
    case SubagentLifecycle = 'subagent-lifecycle';
    /** An event continuing an item agrees with the owner that opened it. */
    case Attribution = 'attribution';
    /** RUN_FINISHED.outcome: at least one interrupt with unique ids, or pending tool calls that match the stream. */
    case Outcome = 'outcome';
}
