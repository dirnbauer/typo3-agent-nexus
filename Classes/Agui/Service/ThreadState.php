<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use Webconsulting\AgentNexus\Agui\Protocol\Json;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;

/**
 * Where a thread stands with its interrupts, read from its stored runs.
 *
 * The specification lets a producer keep nothing between runs and trust the
 * client's resume list. This one keeps its runs anyway and uses them to refuse
 * what it must never do: perform an action whose interrupt nobody answered,
 * perform it twice, or accept an answer to an interrupt it never raised.
 *
 * An interrupt is answered once a later run on the thread carried it in
 * `resume` and did not fail; a resume that failed (a malformed answer) leaves
 * it open for another try.
 */
final readonly class ThreadState
{
    /**
     * @param list<ProtocolObject> $runs the thread's runs, newest first
     */
    public function __construct(
        private array $runs,
    ) {}

    /** The most recent interrupted run, while one of its interrupts is unanswered. */
    public function openInterruptRun(): ?ProtocolObject
    {
        $answered = [];
        foreach ($this->runs as $run) {
            if ($run->state === RunTranscript::INTERRUPTED) {
                foreach ($this->interrupts($run) as $id => $_) {
                    if (!isset($answered[$id])) {
                        return $run;
                    }
                }
                return null;
            }
            if ($run->state !== RunTranscript::ERROR) {
                foreach ($this->resumedIds($run) as $id) {
                    $answered[$id] = true;
                }
            }
        }
        return null;
    }

    /**
     * Match a resume list to the open interrupt it must answer — every
     * interrupt of the interrupted run exactly once, nothing else.
     *
     * @param list<array{interruptId: string, status: string, payload?: mixed, metadata?: mixed}> $resume
     * @throws RunConflict
     */
    public function resume(array $resume): Resumption
    {
        $run = $this->openInterruptRun();
        if ($run === null) {
            throw new RunConflict('no_open_interrupt', sprintf(
                'Nothing on this thread waits for an answer. Interrupt %s is unknown or was answered already.',
                implode(', ', array_map(static fn(array $entry): string => '"' . $entry['interruptId'] . '"', $resume)),
            ));
        }
        $interrupts = $this->interrupts($run);
        $answers = [];
        foreach ($resume as $entry) {
            $id = $entry['interruptId'];
            if (!isset($interrupts[$id])) {
                throw new RunConflict('unknown_interrupt', sprintf('Interrupt "%s" is not open on this thread.', $id));
            }
            if (isset($answers[$id])) {
                throw new RunConflict('duplicate_answer', sprintf('Interrupt "%s" is answered twice.', $id));
            }
            $answers[$id] = $entry;
        }
        foreach (array_keys($interrupts) as $id) {
            if (!isset($answers[$id])) {
                throw new RunConflict('uncovered_interrupt', sprintf('Interrupt "%s" has no answer. Answer or cancel every interrupt of the run you continue.', $id));
            }
        }

        // Agent Nexus raises one interrupt per run.
        $id = (string)array_key_first($interrupts);
        $interrupt = $interrupts[$id];
        $toolCallId = is_string($interrupt['toolCallId'] ?? null) ? $interrupt['toolCallId'] : '';
        $vendor = Json::members(Json::members($interrupt['metadata'] ?? [])[AgentRunner::VENDOR_KEY] ?? []);
        $proposal = RunTranscript::proposal($run->payload, $toolCallId);
        if ($toolCallId === '' || $proposal === null) {
            throw new RunConflict('unknown_interrupt', sprintf('Interrupt "%s" concerns no proposal this agent made.', $id));
        }

        return new Resumption(
            run: $run,
            interrupt: $interrupt,
            entry: $answers[$id],
            preset: is_string($vendor['preset'] ?? null) ? $vendor['preset'] : '',
            toolCallId: $toolCallId,
            proposal: $proposal,
        );
    }

    /**
     * @return array<string, array<string, mixed>> interrupts by id
     */
    private function interrupts(ProtocolObject $run): array
    {
        $interrupts = [];
        foreach (is_array($run->payload['interrupts'] ?? null) ? $run->payload['interrupts'] : [] as $interrupt) {
            $interrupt = Json::members($interrupt);
            if (is_string($interrupt['id'] ?? null)) {
                $interrupts[$interrupt['id']] = $interrupt;
            }
        }
        return $interrupts;
    }

    /**
     * @return list<string>
     */
    private function resumedIds(ProtocolObject $run): array
    {
        $input = Json::members($run->payload['input'] ?? []);
        $ids = [];
        foreach (is_array($input['resume'] ?? null) ? $input['resume'] : [] as $entry) {
            $id = Json::members($entry)['interruptId'] ?? null;
            if (is_string($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
