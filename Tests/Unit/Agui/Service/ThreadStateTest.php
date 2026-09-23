<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Agui\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Agui\Service\RunConflict;
use Webconsulting\AgentNexus\Agui\Service\RunTranscript;
use Webconsulting\AgentNexus\Agui\Service\ThreadState;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Tests\Unit\Agui\RunsAgents;

/**
 * What the producer refuses although the specification would let it trust
 * the client: acting on an interrupt it never raised, acting twice, or moving
 * on while an interrupt waits.
 */
final class ThreadStateTest extends UnitTestCase
{
    use RunsAgents;

    #[Test]
    public function anInterruptedRunIsOpenUntilALaterRunAnswersIt(): void
    {
        [$interrupted, $interruptId] = $this->interruptedRun();

        self::assertSame('run-1', (new ThreadState([$interrupted]))->openInterruptRun()?->objectId);
        self::assertNull((new ThreadState([$this->resumeRun($interruptId, RunTranscript::FINISHED), $interrupted]))->openInterruptRun());
        self::assertNull(
            (new ThreadState([$this->resumeRun($interruptId, RunTranscript::RUNNING), $interrupted]))->openInterruptRun(),
            'An answer still streaming counts, so a double submit cannot act twice.',
        );
    }

    #[Test]
    public function aResumeThatFailedLeavesTheInterruptOpen(): void
    {
        [$interrupted, $interruptId] = $this->interruptedRun();

        self::assertSame('run-1', (new ThreadState([$this->resumeRun($interruptId, RunTranscript::ERROR), $interrupted]))->openInterruptRun()?->objectId);
    }

    #[Test]
    public function aResumeIsMatchedToTheInterruptAndTheProposalItConcerns(): void
    {
        [$interrupted, $interruptId] = $this->interruptedRun();

        $resumption = (new ThreadState([$interrupted]))->resume([['interruptId' => $interruptId, 'status' => 'cancelled']]);

        self::assertSame('plan', $resumption->preset);
        self::assertSame('Team', $resumption->proposal['plan']);
        self::assertSame($resumption->interrupt['toolCallId'], $resumption->toolCallId);
    }

    #[Test]
    public function anUnknownInterruptIsRefused(): void
    {
        [$interrupted] = $this->interruptedRun();

        self::assertConflict('unknown_interrupt', static fn() => (new ThreadState([$interrupted]))
            ->resume([['interruptId' => 'int_forged', 'status' => 'resolved', 'payload' => ['approved' => true]]]));
    }

    #[Test]
    public function anAnsweredInterruptCannotBeAnsweredAgain(): void
    {
        [$interrupted, $interruptId] = $this->interruptedRun();

        $answered = $this->resumeRun($interruptId, RunTranscript::FINISHED);
        self::assertConflict('no_open_interrupt', static fn() => (new ThreadState([$answered, $interrupted]))
            ->resume([['interruptId' => $interruptId, 'status' => 'resolved', 'payload' => ['approved' => true]]]));
    }

    #[Test]
    public function aThreadWithoutRunsHasNothingToResume(): void
    {
        self::assertConflict('no_open_interrupt', static fn() => (new ThreadState([]))->resume([['interruptId' => 'int_1', 'status' => 'cancelled']]));
    }

    #[Test]
    public function oneInterruptAnsweredTwiceIsRefused(): void
    {
        [$interrupted, $interruptId] = $this->interruptedRun();

        self::assertConflict('duplicate_answer', static fn() => (new ThreadState([$interrupted]))->resume([
            ['interruptId' => $interruptId, 'status' => 'cancelled'],
            ['interruptId' => $interruptId, 'status' => 'cancelled'],
        ]));
    }

    #[Test]
    public function everyInterruptOfTheRunMustBeAnswered(): void
    {
        [$interrupted, $interruptId] = $this->interruptedRun();
        $payload = $interrupted->payload;
        self::assertIsArray($payload['interrupts'] ?? null);
        $payload['interrupts'][] = ['id' => 'int_second', 'reason' => 'confirmation'];

        self::assertConflict('uncovered_interrupt', static fn() => (new ThreadState([$interrupted->withPayload($payload)]))
            ->resume([['interruptId' => $interruptId, 'status' => 'cancelled']]));
    }

    /**
     * @return array{ProtocolObject, string}
     */
    private function interruptedRun(): array
    {
        $events = $this->propose('plan');
        $interrupted = self::record($events, self::input('thread-1', 'run-1'));
        self::assertSame(RunTranscript::INTERRUPTED, $interrupted->state);
        $interruptId = self::interruptOf($events)['id'];
        self::assertIsString($interruptId);
        return [$interrupted, $interruptId];
    }

    private function resumeRun(string $interruptId, string $state): ProtocolObject
    {
        $input = self::input('thread-1', 'run-' . $state, ['resume' => [['interruptId' => $interruptId, 'status' => 'cancelled']]], '');
        return (new ProtocolObject(ObjectKind::Run, $input->runId, 'thread-1', payload: ['input' => $input->toArray()]))->withState($state);
    }

    private static function assertConflict(string $reason, \Closure $resume): void
    {
        try {
            $resume();
        } catch (RunConflict $conflict) {
            self::assertSame($reason, $conflict->reason, $conflict->getMessage());
            return;
        }
        self::fail('The resume was accepted; expected the conflict ' . $reason . '.');
    }
}
