<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;
use Webconsulting\AgentNexus\Agui\Agent\Scenario;
use Webconsulting\AgentNexus\Agui\Agent\Scenarios;
use Webconsulting\AgentNexus\Agui\Event\EventFactory;
use Webconsulting\AgentNexus\Agui\Protocol\EventType;
use Webconsulting\AgentNexus\Agui\Protocol\EventVerifier;
use Webconsulting\AgentNexus\Agui\Protocol\ProtocolViolation;
use Webconsulting\AgentNexus\Agui\Protocol\RunInput;
use Webconsulting\AgentNexus\Shared\Http\EventStream;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;

/**
 * Runs one AG-UI run for either endpoint — the public one and the backend
 * console's — the same way.
 *
 * Everything that can refuse a run happens before the stream opens, as the
 * specification requires: a reused run id, an answer to an interrupt that is
 * not open, a new run while an interrupt waits ({@see RunConflict}, 409).
 * Then the run is recorded (kind Run, id = runId, context = threadId), and
 * its events are streamed, recorded as they go and closed with a terminal
 * event whatever happens: an exception becomes RUN_ERROR. In Development and
 * Testing context every stream also passes the protocol verifier.
 */
final readonly class RunService
{
    /** How many of a thread's runs are read to find its open interrupt. */
    private const int THREAD_WINDOW = 25;

    public function __construct(
        private ObjectStore $store,
        private Scenarios $scenarios,
        private AgentRunner $runner,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws RunConflict
     */
    public function start(RunInput $input, RunPlan $plan): ResponseInterface
    {
        if ($this->store->find(ObjectKind::Run, $input->runId) !== null) {
            throw new RunConflict('run_id_reused', sprintf('Run "%s" exists already. Every run needs a new runId.', $input->runId));
        }
        $thread = new ThreadState($this->store->list(new ObjectFilter(ObjectKind::Run, contextId: $input->threadId), self::THREAD_WINDOW));

        if ($input->resume !== []) {
            $resumption = $thread->resume($input->resume);
            $scenario = $this->scenarios->get($resumption->preset);
            if ($scenario === null || $scenario->audience !== $plan->audience) {
                throw new RunConflict('unknown_interrupt', sprintf('Interrupt "%s" cannot be answered through this endpoint.', $resumption->entry['interruptId']));
            }
            $events = $this->runner->resume($input, $scenario, $resumption);
            $note = sprintf('Answers %s of run %s', $resumption->entry['interruptId'], $resumption->run->objectId);
            $detail = $resumption->entry['status'];
        } else {
            $open = $thread->openInterruptRun();
            if ($open !== null) {
                throw new RunConflict('open_interrupt', sprintf(
                    'Run "%s" of this thread waits for an answer. Answer or cancel its interrupt in resume before you start another run.',
                    $open->objectId,
                ));
            }
            $scenario = $this->scenarios->resolve($plan->preset, $plan->audience, $input->lastUserText());
            $events = $this->runner->propose($input, $scenario, $plan->llm, $plan->scriptedReason);
            $note = '';
            $detail = $input->lastUserText() !== '' ? $input->lastUserText() : $scenario->defaultMessage;
        }

        $record = $this->store->save((new ProtocolObject(
            kind: ObjectKind::Run,
            objectId: $input->runId,
            contextId: $input->threadId,
            source: $plan->channel->value,
            label: self::label($scenario, $detail),
            payload: ['input' => $input->toArray(), 'eventCount' => 0],
            pid: $plan->pid,
            beUser: $plan->beUser,
        ))->withState(RunTranscript::RUNNING, $note));

        if (Environment::getContext()->isDevelopment() || Environment::getContext()->isTesting()) {
            $events = EventVerifier::guard($events, $input->threadId, $input->runId);
        }
        return EventStream::response(
            $this->recorded($record, $input, $scenario, $events),
            'AG-UI ' . EventType::PROTOCOL_VERSION,
            $plan->delayMs,
        );
    }

    /**
     * The run's events, recorded as they pass and always ending with a
     * terminal event.
     *
     * @param iterable<array<string, mixed>> $events
     * @return \Generator<int, array<string, mixed>>
     */
    private function recorded(ProtocolObject $record, RunInput $input, Scenario $scenario, iterable $events): \Generator
    {
        $transcript = new RunTranscript($input->state);
        try {
            foreach ($events as $event) {
                $transcript->apply($event);
                if (in_array($event['type'] ?? null, [EventType::RunFinished->value, EventType::RunError->value], true)) {
                    // Record the ending before the client sees it: a resume
                    // may follow the moment RUN_FINISHED arrives.
                    $record = $this->close($record, $transcript, $input, $scenario);
                }
                yield $event;
            }
        } catch (\Throwable $e) {
            $this->logger->error('AG-UI run {run} failed: {message}', ['run' => $input->runId, 'message' => $e->getMessage(), 'exception' => $e]);
            if (!$transcript->isOver()) {
                $error = EventFactory::runError(
                    'The agent failed. The error has been logged.',
                    $e instanceof ProtocolViolation ? 'protocol_violation' : 'internal_error',
                );
                $transcript->apply($error);
                $this->close($record, $transcript, $input, $scenario);
                yield $error;
            }
        } finally {
            if (!$transcript->isOver()) {
                $this->save($record->withPayload($transcript->payload($input->toArray()))->withState(RunTranscript::ERROR, 'The stream ended before the run finished.'));
            }
        }
    }

    private function close(ProtocolObject $record, RunTranscript $transcript, RunInput $input, Scenario $scenario): ProtocolObject
    {
        $closed = $record
            ->withPayload($transcript->payload($input->toArray()))
            ->withState($transcript->state(), $transcript->note());
        if ($transcript->resultStatus() !== '') {
            $closed = $closed->withLabel(self::label($scenario, $transcript->resultStatus()));
        }
        return $this->save($closed);
    }

    /** Recording must never break the stream: a failed write is logged. */
    private function save(ProtocolObject $record): ProtocolObject
    {
        try {
            return $this->store->save($record);
        } catch (\Throwable $e) {
            $this->logger->warning('AG-UI run {run} could not be recorded.', ['run' => $record->objectId, 'exception' => $e]);
            return $record;
        }
    }

    private static function label(Scenario $scenario, string $detail): string
    {
        return mb_substr($scenario->id . ' · ' . preg_replace('/\s+/u', ' ', trim($detail)), 0, 120);
    }
}
