<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Agui\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Agui\Event\EventFactory as E;
use Webconsulting\AgentNexus\Agui\Protocol\EventVerifier;
use Webconsulting\AgentNexus\Agui\Protocol\OrderingRule;
use Webconsulting\AgentNexus\Agui\Protocol\ProtocolViolation;

/**
 * The verifier holds every stream Agent Nexus produces to AG-UI 1.0. Each
 * negative case is a mistake a producer can make — most of them ones the 3.x
 * implementation actually made — and must name the rule it breaks.
 */
final class EventVerifierTest extends UnitTestCase
{
    #[Test]
    public function aConformingRunPasses(): void
    {
        EventVerifier::verify([
            E::runStarted('t', 'r'),
            E::stepStarted('analyse'),
            E::reasoningStart('span'),
            E::reasoningMessageStart('m-1'),
            E::reasoningMessageContent('m-1', 'thinking '),
            E::reasoningMessageEnd('m-1'),
            E::reasoningEnd('span'),
            E::stateSnapshot(['selection' => null]),
            E::stateDelta([['op' => 'replace', 'path' => '/selection', 'value' => 'Team']]),
            E::stepFinished('analyse'),
            E::textMessageStart('m-2'),
            E::textMessageContent('m-2', 'Hello'),
            E::textMessageEnd('m-2'),
            E::activitySnapshot('a-1', 'x.plans', ['plans' => []]),
            E::activityDelta('a-1', 'x.plans', [['op' => 'add', 'path' => '/plans/-', 'value' => 'Team']]),
            E::toolCallStart('c-1', 'confirm_booking', 'm-2'),
            E::toolCallArgs('c-1', '{}'),
            E::toolCallEnd('c-1'),
            E::runFinished('t', 'r', E::interrupted([E::interrupt('i-1', 'confirmation', toolCallId: 'c-1')])),
        ], 't', 'r');
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function acceptedStreams(): iterable
    {
        yield 'RUN_ERROR as the first event' => [[E::runError('The provider is down.')]];
        yield 'a late RUN_ERROR after RUN_FINISHED' => [[E::runStarted('t', 'r'), E::runFinished('t', 'r'), E::runError('flush failed')]];
        yield 'a new run after RUN_ERROR' => [[E::runStarted('t', 'r'), E::runError('x'), E::runStarted('t', 'r2'), E::runFinished('t', 'r2')]];
        yield 'two runs in sequence' => [[E::runStarted('t', 'r'), E::runFinished('t', 'r'), E::runStarted('t', 'r2'), E::runFinished('t', 'r2')]];
        yield 'a closed message reopened' => [[
            E::runStarted('t', 'r'), E::textMessageStart('m'), E::textMessageEnd('m'), E::textMessageStart('m'), E::textMessageEnd('m'), E::runFinished('t', 'r'),
        ]];
        yield 'RUN_ERROR with items still open' => [[E::runStarted('t', 'r'), E::textMessageStart('m'), E::runError('crashed')]];
        yield 'chunks that continue their item, with RAW aside' => [[
            E::runStarted('t', 'r'), E::textMessageChunk('m', 'Half '), E::raw(['x' => 1]), E::textMessageChunk(null, 'and half.'), E::runFinished('t', 'r'),
        ]];
        yield 'a tool call answered in the same run' => [[
            E::runStarted('t', 'r'), E::toolCallStart('c', 'search'), E::toolCallEnd('c'), E::toolCallResult('m-r', 'c', 'found'), E::runFinished('t', 'r'),
        ]];
        yield 'pending tool calls that match the stream' => [[
            E::runStarted('t', 'r'), E::toolCallStart('c', 'render_chart'), E::toolCallEnd('c'), E::runFinished('t', 'r', E::success(['c'])),
        ]];
        yield 'a subagent announced and closed' => [[
            E::runStarted('t', 'r'), E::subagentStarted('s', 'pricing'), E::attributedTo(E::textMessageStart('m'), 's'),
            E::textMessageEnd('m'), E::subagentFinished('s'), E::runFinished('t', 'r'),
        ]];
        yield 'the null a CUSTOM value may be' => [[E::runStarted('t', 'r'), E::custom('x.y', null), E::runFinished('t', 'r')]];
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    #[Test]
    #[DataProvider('acceptedStreams')]
    public function streamsTheSpecificationAllowsPass(array $events): void
    {
        EventVerifier::verify($events);
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{list<array<array-key, mixed>|\stdClass>, OrderingRule}>
     */
    public static function brokenStreams(): iterable
    {
        yield 'an event before RUN_STARTED' => [[E::textMessageStart('m')], OrderingRule::FirstEvent];
        yield 'a nested RUN_STARTED' => [[E::runStarted('t', 'r'), E::runStarted('t', 'r2')], OrderingRule::OneActiveRun];
        yield 'an event after RUN_FINISHED' => [[E::runStarted('t', 'r'), E::runFinished('t', 'r'), E::textMessageStart('m')], OrderingRule::ClosedRun];
        yield 'anything but RUN_STARTED after RUN_ERROR' => [[E::runStarted('t', 'r'), E::runError('x'), E::runError('y')], OrderingRule::FailedRun];
        yield 'a stream that stops mid-run' => [[E::runStarted('t', 'r'), E::textMessageStart('m')], OrderingRule::TerminalEvent];
        yield 'RUN_FINISHED for another run' => [[E::runStarted('t', 'r'), E::runFinished('t', 'r2')], OrderingRule::RunIdentity];
        yield 'a run id used twice' => [[E::runStarted('t', 'r'), E::runFinished('t', 'r'), E::runStarted('t', 'r'), E::runFinished('t', 'r')], OrderingRule::RunIdentity];
        yield 'a RUN_STARTED without protocolVersion' => [[['type' => 'RUN_STARTED', 'threadId' => 't', 'runId' => 'r'], E::runFinished('t', 'r')], OrderingRule::ProtocolVersion];
        yield 'content for a message nobody opened' => [[E::runStarted('t', 'r'), E::textMessageContent('m', 'x')], OrderingRule::OpenBeforeContinue];
        yield 'a message opened twice' => [[E::runStarted('t', 'r'), E::textMessageStart('m'), E::textMessageStart('m')], OrderingRule::NoDoubleOpen];
        yield 'arguments for a closed tool call' => [[E::runStarted('t', 'r'), E::toolCallStart('c', 'x'), E::toolCallEnd('c'), E::toolCallArgs('c', '{}')], OrderingRule::OpenBeforeContinue];
        yield 'a step finished that never started' => [[E::runStarted('t', 'r'), E::stepFinished('apply')], OrderingRule::StepsBalanced];
        yield 'RUN_FINISHED with an open step' => [[E::runStarted('t', 'r'), E::stepStarted('apply'), E::runFinished('t', 'r')], OrderingRule::ClosedBeforeFinish];
        yield 'RUN_FINISHED with an open text message' => [[E::runStarted('t', 'r'), E::textMessageStart('m'), E::runFinished('t', 'r')], OrderingRule::ClosedBeforeFinish];
        yield 'RUN_FINISHED with an open reasoning span' => [[E::runStarted('t', 'r'), E::reasoningStart('s'), E::runFinished('t', 'r')], OrderingRule::ClosedBeforeFinish];
        yield 'RUN_FINISHED with an active subagent' => [[E::runStarted('t', 'r'), E::subagentStarted('s', 'x'), E::runFinished('t', 'r')], OrderingRule::ClosedBeforeFinish];
        yield 'a reasoning fragment naming a span' => [[E::runStarted('t', 'r'), E::reasoningStart('s'), E::reasoningMessageContent('s', 'x')], OrderingRule::ReasoningNamespaces];
        yield 'the 3.x reasoning shape without ids' => [[E::runStarted('t', 'r'), ['type' => 'REASONING_START']], OrderingRule::ClosedObjects];
        yield 'a reasoning message with the wrong role' => [[E::runStarted('t', 'r'), ['type' => 'REASONING_MESSAGE_START', 'messageId' => 'm', 'role' => 'assistant']], OrderingRule::ClosedObjects];
        yield 'a retired THINKING event' => [[E::runStarted('t', 'r'), ['type' => 'THINKING_START']], OrderingRule::ClosedObjects];
        yield 'an undeclared field' => [[E::runStarted('t', 'r') + ['approval' => true]], OrderingRule::ClosedObjects];
        yield 'an optional field sent as null' => [[E::runStarted('t', 'r'), ['type' => 'RUN_FINISHED', 'threadId' => 't', 'runId' => 'r', 'result' => null]], OrderingRule::ClosedObjects];
        yield 'a float timestamp' => [[['type' => 'RUN_STARTED', 'threadId' => 't', 'runId' => 'r', 'protocolVersion' => '1.0', 'timestamp' => 1.5]], OrderingRule::ClosedObjects];
        yield 'metadata sent as a JSON array' => [[E::runStarted('t', 'r'), ['type' => 'CUSTOM', 'name' => 'x.y', 'value' => 1, 'metadata' => []]], OrderingRule::ClosedObjects];
        yield 'an outcome sent as a list' => [[E::runStarted('t', 'r'), ['type' => 'RUN_FINISHED', 'threadId' => 't', 'runId' => 'r', 'outcome' => ['interrupt']]], OrderingRule::ClosedObjects];
        yield 'an interrupt outcome without interrupts' => [[E::runStarted('t', 'r'), ['type' => 'RUN_FINISHED', 'threadId' => 't', 'runId' => 'r', 'outcome' => ['type' => 'interrupt', 'interrupts' => []]]], OrderingRule::ClosedObjects];
        yield 'two interrupts with one id' => [[
            E::runStarted('t', 'r'), E::runFinished('t', 'r', E::interrupted([E::interrupt('i', 'confirmation'), E::interrupt('i', 'confirmation')])),
        ], OrderingRule::Outcome];
        yield 'pending tool calls that do not match the stream' => [[
            E::runStarted('t', 'r'), E::toolCallStart('c', 'x'), E::toolCallEnd('c'), E::runFinished('t', 'r', E::success(['other'])),
        ], OrderingRule::Outcome];
        yield 'an activity delta without a snapshot' => [[E::runStarted('t', 'r'), E::activityDelta('a', 'x', [])], OrderingRule::ActivityBaseline];
        yield 'a patch operation RFC 6902 does not define' => [[E::runStarted('t', 'r'), E::stateDelta([['op' => 'merge', 'path' => '/a']])], OrderingRule::ClosedObjects];
        yield 'one message id for two messages' => [[
            E::runStarted('t', 'r'), E::toolCallStart('c', 'x'), E::toolCallEnd('c'), E::toolCallResult('m', 'c', 'ok'), E::textMessageStart('m'),
        ], OrderingRule::UniqueMessageIds];
        yield 'a first chunk without its id' => [[E::runStarted('t', 'r'), E::textMessageChunk(null, 'x')], OrderingRule::ChunkForm];
        yield 'a chunk continuing after CUSTOM ended its stream' => [[
            E::runStarted('t', 'r'), E::textMessageChunk('m', 'Half '), E::custom('x.y', true), E::textMessageChunk(null, 'half.'),
        ], OrderingRule::ChunkForm];
        yield 'a chunk that contradicts its role' => [[E::runStarted('t', 'r'), E::textMessageChunk('m', 'a', 'user'), E::textMessageChunk('m', 'b', 'assistant')], OrderingRule::ChunkForm];
        yield 'a subagent finished that never started' => [[E::runStarted('t', 'r'), E::subagentFinished('s')], OrderingRule::SubagentLifecycle];
        yield 'a continuation attributed to another owner' => [[
            E::runStarted('t', 'r'), E::attributedTo(E::toolCallStart('c', 'x'), 's-1'), E::attributedTo(E::toolCallEnd('c'), 's-2'),
        ], OrderingRule::Attribution];
        yield 'an event that is a list' => [[E::runStarted('t', 'r'), ['RUN_FINISHED']], OrderingRule::ClosedObjects];
    }

    /**
     * @param list<array<array-key, mixed>|\stdClass> $events
     */
    #[Test]
    #[DataProvider('brokenStreams')]
    public function aBrokenRuleIsNamed(array $events, OrderingRule $rule): void
    {
        try {
            EventVerifier::verify($events);
        } catch (ProtocolViolation $violation) {
            self::assertSame($rule, $violation->rule, $violation->getMessage());
            return;
        }
        self::fail('The stream was accepted, but it breaks ' . $rule->value . '.');
    }

    #[Test]
    public function theRequestedRunMustEchoTheInputIds(): void
    {
        $this->expectException(ProtocolViolation::class);
        $this->expectExceptionMessage('the input asked for "thread-1"');
        EventVerifier::verify([E::runStarted('thread-2', 'run-1'), E::runFinished('thread-2', 'run-1')], 'thread-1', 'run-1');
    }

    #[Test]
    public function aStreamFromAnOlderProducerMayOmitTheVersionWhenAsked(): void
    {
        EventVerifier::verify([['type' => 'RUN_STARTED', 'threadId' => 't', 'runId' => 'r'], E::runFinished('t', 'r')], requireProtocolVersion: false);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function theGuardHandsEveryEventOnAndStopsAtTheFirstViolation(): void
    {
        $guarded = EventVerifier::guard([E::runStarted('t', 'r'), E::textMessageContent('m', 'x'), E::runFinished('t', 'r')]);

        self::assertSame('RUN_STARTED', $guarded->current()['type']);
        $this->expectException(ProtocolViolation::class);
        $this->expectExceptionMessage('event #1');
        $guarded->next();
    }

    #[Test]
    public function decodedJsonWithObjectsAsStdClassIsReadTheSameWay(): void
    {
        $stream = json_decode('[{"type":"RUN_STARTED","threadId":"t","runId":"r","protocolVersion":"1.0"},'
            . '{"type":"ACTIVITY_SNAPSHOT","messageId":"a","activityType":"x","content":{}},'
            . '{"type":"RUN_FINISHED","threadId":"t","runId":"r","outcome":{"type":"cancelled"}}]', false, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($stream);
        EventVerifier::verify(array_filter($stream, static fn(mixed $event): bool => $event instanceof \stdClass));
        $this->addToAssertionCount(1);
    }
}
