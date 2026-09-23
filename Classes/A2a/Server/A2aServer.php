<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Server;

use Psr\Log\LoggerInterface;
use Webconsulting\AgentNexus\A2a\Protocol\A2aError;
use Webconsulting\AgentNexus\A2a\Protocol\A2aException;
use Webconsulting\AgentNexus\A2a\Protocol\Artifact;
use Webconsulting\AgentNexus\A2a\Protocol\Ids;
use Webconsulting\AgentNexus\A2a\Protocol\ListTasksParams;
use Webconsulting\AgentNexus\A2a\Protocol\Message;
use Webconsulting\AgentNexus\A2a\Protocol\Part;
use Webconsulting\AgentNexus\A2a\Protocol\Role;
use Webconsulting\AgentNexus\A2a\Protocol\SendMessageParams;
use Webconsulting\AgentNexus\A2a\Protocol\StreamResponse;
use Webconsulting\AgentNexus\A2a\Protocol\Task;
use Webconsulting\AgentNexus\A2a\Protocol\TaskIdParams;
use Webconsulting\AgentNexus\A2a\Protocol\TaskState;
use Webconsulting\AgentNexus\A2a\Protocol\TaskStatus;
use Webconsulting\AgentNexus\A2a\Service\TaskRunner;
use Webconsulting\AgentNexus\A2a\Service\TurnPlan;

/**
 * The A2A operations, independent of the binding that carries them.
 *
 * The JSON-RPC and the HTTP+JSON endpoints parse their requests into the
 * parameter objects, call these methods and render what comes back; the 0.3
 * translation happens around them. So a task behaves the same whichever way
 * it is reached.
 *
 * How a turn runs: the task is saved as TASK_STATE_SUBMITTED with the client's
 * message, the agent plans the turn ({@see TaskRunner}), and every status the
 * plan reaches is saved before it is announced. A stream is a paced view of
 * that work, not the work itself: when a client drops the connection half way,
 * the rest of the turn still happens, so the task's lifecycle does not depend
 * on any one stream (specification section 3.5.2).
 *
 * A task created with `returnImmediately` is saved as submitted and worked on
 * by the next request that looks at it — GetTask or SubscribeToTask — since a
 * PHP request has no background worker to hand it to.
 */
final class A2aServer
{
    /** Roughly how many characters one streamed artifact chunk carries. */
    private const int CHUNK_CHARACTERS = 32;

    /** A running stream checks for a cancellation after this many chunks. */
    private const int CANCEL_CHECK_EVERY = 8;

    /** How often a subscriber that watches another request's work polls. */
    private const int WATCH_INTERVAL_MS = 250;

    /** A turn that needs nothing more from the finally block. */
    private const int SETTLED = PHP_INT_MAX;

    /** The output modes the skills can answer in. */
    private const array OUTPUT_MODES = ['text/markdown', 'text/plain'];

    public function __construct(
        private readonly TaskStore $store,
        private readonly TaskRunner $runner,
        private readonly TaskLock $lock,
        private readonly LoggerInterface $logger,
        private readonly int $watchSeconds = 60,
    ) {}

    /**
     * SendMessage: blocks until the task finishes or asks for input, unless
     * the client asked to return immediately.
     *
     * @return array{task: array<string, mixed>} a SendMessageResponse
     */
    public function sendMessage(SendMessageParams $params, CallContext $context): array
    {
        $this->checkContent($params);
        [$stored, $resuming, $release] = $this->accept($params->message, $context);
        try {
            if (!$params->returnImmediately) {
                $stored = $this->runToEnd($stored, $resuming, $context);
            }
            return ['task' => $stored->task->toArray($params->historyLength)];
        } finally {
            $release();
        }
    }

    /**
     * SendStreamingMessage: the Task first, then its status and artifact
     * updates until it finishes or asks for input.
     *
     * Nothing runs until the generator is started; the endpoints start it
     * before they open a stream, so that an invalid request is answered with a
     * plain error instead of a stream.
     *
     * @return \Generator<int, array<string, mixed>, mixed, void> StreamResponse frames
     */
    public function sendStreamingMessage(SendMessageParams $params, CallContext $context): \Generator
    {
        $this->checkContent($params);
        [$stored, $resuming, $release] = $this->accept($params->message, $context);
        try {
            yield from $this->run($stored, $resuming, $context, $params->historyLength);
        } finally {
            $release();
        }
    }

    /**
     * GetTask. A task queued with `returnImmediately` is worked on first.
     *
     * @return array<string, mixed> a Task
     */
    public function getTask(TaskIdParams $params, CallContext $context): array
    {
        $stored = $this->find($params->id, $context);
        if ($this->isPending($stored->task)) {
            $stored = $this->drivePending($stored, $context) ?? $stored;
        }
        return $stored->task->toArray($params->historyLength);
    }

    /**
     * ListTasks: newest status first, a page at a time.
     *
     * @return array{tasks: list<array<string, mixed>>, nextPageToken: string, pageSize: int, totalSize: int}
     */
    public function listTasks(ListTasksParams $params): array
    {
        $page = $this->store->list(new TaskListQuery(
            $params->contextId,
            $params->status,
            $params->pageSize,
            $params->pageToken,
            $params->statusTimestampAfter,
        ));

        return [
            'tasks' => array_map(
                static fn(Task $task): array => $task->toArray($params->historyLength, $params->includeArtifacts),
                $page->tasks,
            ),
            'nextPageToken' => $page->nextPageToken,
            'pageSize' => $params->pageSize,
            'totalSize' => $page->totalSize,
        ];
    }

    /**
     * CancelTask. Canceling a canceled task again is not an error — the
     * operation is idempotent — but a task that completed, failed or was
     * rejected cannot be canceled.
     *
     * @return array<string, mixed> a Task
     */
    public function cancelTask(TaskIdParams $params, CallContext $context): array
    {
        $stored = $this->find($params->id, $context);
        $state = $stored->task->state();
        if ($state === TaskState::Canceled) {
            return $stored->task->toArray($params->historyLength);
        }
        if ($state->isTerminal()) {
            throw new A2aException(
                A2aError::TaskNotCancelable,
                sprintf('Task "%s" has already ended (%s) and cannot be canceled.', $stored->task->id, $state->value),
                ['taskId' => $stored->task->id, 'state' => $state->value],
            );
        }
        $stored = $this->applyStatus($stored, TaskState::Canceled, 'Canceled at the client’s request.');
        return $stored->task->toArray($params->historyLength);
    }

    /**
     * SubscribeToTask: the task as it is now, then whatever happens to it
     * until it finishes or asks for input. A task that already asks for input
     * gets just the Task, since the stream closes at an interrupted state.
     *
     * @return \Generator<int, array<string, mixed>, mixed, void> StreamResponse frames
     */
    public function subscribeToTask(TaskIdParams $params, CallContext $context): \Generator
    {
        $stored = $this->find($params->id, $context);
        if ($stored->task->state()->isTerminal()) {
            throw new A2aException(
                A2aError::UnsupportedOperation,
                sprintf('Task "%s" has already ended (%s); there is nothing to subscribe to. Read it with GetTask.', $stored->task->id, $stored->task->state()->value),
                ['taskId' => $stored->task->id, 'state' => $stored->task->state()->value],
            );
        }

        if ($this->isPending($stored->task)) {
            $release = $this->lock->acquire($stored->task->id);
            if ($release !== null) {
                try {
                    $fresh = $this->store->find($stored->task->id) ?? $stored;
                    if ($this->isPending($fresh->task)) {
                        yield from $this->run($fresh, $fresh->task->state() === TaskState::InputRequired, $context, $params->historyLength);
                        return;
                    }
                    $stored = $fresh;
                } finally {
                    $release();
                }
            }
        }

        yield StreamResponse::task($stored->task, $params->historyLength);
        if (!$stored->task->state()->isInterrupted()) {
            yield from $this->watch($stored->task);
        }
    }

    /**
     * The four push notification operations and a push config inside
     * SendMessage: this agent never calls a webhook
     * (capabilities.pushNotifications is false).
     */
    public function pushNotificationConfig(): never
    {
        throw A2aException::pushNotificationsNotSupported();
    }

    /**
     * GetExtendedAgentCard. The card declares capabilities.extendedAgentCard
     * false, and for that case the specification (section 3.3.4) requires
     * UnsupportedOperationError, not ExtendedAgentCardNotConfiguredError.
     */
    public function extendedAgentCard(): never
    {
        throw new A2aException(
            A2aError::UnsupportedOperation,
            'This agent has no extended Agent Card (capabilities.extendedAgentCard is false). The public card is all there is.',
        );
    }

    /**
     * Carry out a turn: announce the task, let the agent plan, then save and
     * announce every step. Returns the task as the turn left it.
     *
     * @return \Generator<int, array<string, mixed>, mixed, StoredTask>
     */
    private function run(StoredTask $stored, bool $resuming, CallContext $context, ?int $historyLength = null): \Generator
    {
        yield StreamResponse::task($stored->task, $historyLength);

        $incoming = $stored->task->lastMessage();
        if ($incoming === null || $incoming->role !== Role::User) {
            return $stored;
        }

        // $next is the first step not yet carried out. When the generator is
        // dropped at a yield — the client closed the stream — the finally
        // block does the rest; a turn that ended some other way (finished,
        // canceled, failed) sets it past the end.
        $plan = null;
        $next = 0;
        $pendingArtifact = null;
        try {
            $plan = $this->runner->plan($stored->task, $incoming, $resuming, $context);
            $stored = $stored
                ->withTask($stored->task->withMetadata($plan->taskMetadata))
                ->withLabel($plan->skillName);

            foreach ($plan->steps as $index => $step) {
                $canceled = $this->canceled($stored);
                if ($canceled !== null) {
                    $next = self::SETTLED;
                    yield StreamResponse::statusUpdate($canceled->task);
                    return $canceled;
                }

                if (!$step->isArtifact()) {
                    // Saved before it is announced: a client that reads the
                    // task back never sees an older state than the stream.
                    $stored = $this->applyStatus($stored, $step->state ?? TaskState::Working, $step->text);
                    $next = $index + 1;
                    yield StreamResponse::statusUpdate($stored->task, $step->eventMetadata);
                    continue;
                }

                $pendingArtifact = $step->produceArtifact();
                foreach ($this->chunks($pendingArtifact) as $position => [$chunk, $last]) {
                    if ($position > 0 && $position % self::CANCEL_CHECK_EVERY === 0) {
                        $canceled = $this->canceled($stored);
                        if ($canceled !== null) {
                            $next = self::SETTLED;
                            yield StreamResponse::statusUpdate($canceled->task);
                            return $canceled;
                        }
                    }
                    yield StreamResponse::artifactUpdate($stored->task, $chunk, $position > 0, $last);
                }
                $stored = $this->store->save(
                    $stored->withTask($stored->task->withArtifact($pendingArtifact)),
                    'Artifact ' . $pendingArtifact->name,
                );
                $pendingArtifact = null;
                $next = $index + 1;
            }
            return $stored;
        } catch (\Throwable $exception) {
            $next = self::SETTLED;
            $this->logger->error('A2A task {task} failed.', ['task' => $stored->task->id, 'exception' => $exception]);
            $stored = $this->applyStatusQuietly($stored, TaskState::Failed, 'The task failed on the server. The error has been logged.', $exception->getMessage());
            yield StreamResponse::statusUpdate($stored->task);
            return $stored;
        } finally {
            if ($plan !== null && $next < count($plan->steps)) {
                $this->finishQuietly($stored, $plan, $next, $pendingArtifact);
            }
        }
    }

    private function runToEnd(StoredTask $stored, bool $resuming, CallContext $context): StoredTask
    {
        $turn = $this->run($stored, $resuming, $context);
        foreach ($turn as $frame) {
            // A blocking call only needs the outcome.
        }
        return $turn->getReturn();
    }

    /**
     * The client went away in the middle of a turn: do the rest of it without
     * announcing anything, unless the task was canceled meanwhile.
     */
    private function finishQuietly(StoredTask $stored, TurnPlan $plan, int $next, ?Artifact $pendingArtifact): void
    {
        try {
            $current = $this->store->find($stored->task->id);
            if ($current === null || $current->task->state()->isTerminal()) {
                return;
            }
            $current = $current->withLabel($stored->label);
            foreach (array_slice($plan->steps, $next) as $step) {
                if ($step->isArtifact()) {
                    $artifact = $pendingArtifact ?? $step->produceArtifact();
                    $pendingArtifact = null;
                    $current = $this->store->save($current->withTask($current->task->withArtifact($artifact)), 'Artifact ' . $artifact->name);
                } else {
                    $current = $this->applyStatus($current, $step->state ?? TaskState::Working, $step->text);
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('A2A task {task} could not be finished after its stream closed.', ['task' => $stored->task->id, 'exception' => $exception]);
        }
    }

    /**
     * Take in a client message: start a new task, or continue the one it
     * names — which must exist, belong to the given context and be waiting for
     * input.
     *
     * @return array{0: StoredTask, 1: bool, 2: \Closure(): void} the task, whether it resumes, the lock release
     */
    private function accept(Message $message, CallContext $context): array
    {
        if ($message->taskId === '') {
            $stored = $this->create($message, $context);
            $context->correlate($stored->task->id);
            return [$stored, false, static function (): void {}];
        }

        $context->correlate($message->taskId);
        $release = $this->lock->acquire($message->taskId) ?? throw $this->busy($message->taskId);
        try {
            $stored = $this->store->find($message->taskId) ?? throw A2aException::taskNotFound($message->taskId);
            $task = $stored->task;
            if ($message->contextId !== '' && $message->contextId !== $task->contextId) {
                throw A2aException::invalidParams('message.contextId', sprintf('does not match the context of task "%s".', $task->id));
            }
            if ($task->state()->isTerminal()) {
                throw new A2aException(
                    A2aError::UnsupportedOperation,
                    sprintf('Task "%s" has already ended (%s) and accepts no more messages. Send a message without a taskId to start a new task.', $task->id, $task->state()->value),
                    ['taskId' => $task->id, 'state' => $task->state()->value],
                );
            }
            if ($task->state() !== TaskState::InputRequired || $this->isPending($task)) {
                throw $this->busy($task->id);
            }
            $stored = $this->store->save(
                $stored->withTask($task->withMessage($message->inTask($task->contextId, $task->id))),
                'Message ' . $message->messageId,
            );
            return [$stored, true, $release];
        } catch (\Throwable $exception) {
            $release();
            throw $exception;
        }
    }

    private function create(Message $message, CallContext $context): StoredTask
    {
        $contextId = $message->contextId;
        if ($contextId !== '' && !Ids::isAcceptable($contextId)) {
            throw A2aException::invalidParams('message.contextId', 'must be 1 to 128 letters, digits, dots, colons, underscores or hyphens.');
        }
        $contextId = $contextId !== '' ? $contextId : Ids::uuid();
        $taskId = Ids::uuid();
        $task = new Task($taskId, $contextId, TaskStatus::now(TaskState::Submitted), [], [$message->inTask($contextId, $taskId)]);

        return $this->store->save(new StoredTask($task, $context->channel, $context->pid), 'Message ' . $message->messageId);
    }

    private function drivePending(StoredTask $stored, CallContext $context): ?StoredTask
    {
        $release = $this->lock->acquire($stored->task->id);
        if ($release === null) {
            return null;
        }
        try {
            $fresh = $this->store->find($stored->task->id);
            if ($fresh === null || !$this->isPending($fresh->task)) {
                return $fresh;
            }
            return $this->runToEnd($fresh, $fresh->task->state() === TaskState::InputRequired, $context);
        } finally {
            $release();
        }
    }

    /**
     * Follow a task another request is working on, by reading it back from the
     * store until it finishes, asks for input or the time budget runs out.
     *
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    private function watch(Task $seen): \Generator
    {
        $deadline = microtime(true) + $this->watchSeconds;
        while (microtime(true) < $deadline) {
            usleep(self::WATCH_INTERVAL_MS * 1000);
            $current = $this->store->find($seen->id)?->task;
            if ($current === null) {
                return;
            }
            $known = array_map(static fn(Artifact $artifact): string => $artifact->artifactId, $seen->artifacts);
            foreach ($current->artifacts as $artifact) {
                if (!in_array($artifact->artifactId, $known, true)) {
                    yield StreamResponse::artifactUpdate($current, $artifact, false, true);
                }
            }
            if ($current->state() !== $seen->state() || $current->status->timestamp !== $seen->status->timestamp) {
                yield StreamResponse::statusUpdate($current);
            }
            $seen = $current;
            if ($current->state()->endsStream()) {
                return;
            }
        }
    }

    /**
     * Move a task to a state with an agent message, and save it. The message
     * also goes into the history, so the task keeps the whole conversation.
     */
    private function applyStatus(StoredTask $stored, TaskState $state, string $text): StoredTask
    {
        $message = Message::fromAgent($text, $stored->task->contextId, $stored->task->id);
        $task = $stored->task->withStatus(TaskStatus::now($state, $message))->withMessage($message);
        return $this->store->save($stored->withTask($task), $text);
    }

    /** {@see applyStatus()} for the error path: never throws. */
    private function applyStatusQuietly(StoredTask $stored, TaskState $state, string $text, string $note): StoredTask
    {
        $message = Message::fromAgent($text, $stored->task->contextId, $stored->task->id);
        $failed = $stored->withTask($stored->task->withStatus(TaskStatus::now($state, $message))->withMessage($message));
        try {
            return $this->store->save($failed, mb_substr($note, 0, 200));
        } catch (\Throwable) {
            return $failed;
        }
    }

    /** The task as stored, when a client canceled it meanwhile. */
    private function canceled(StoredTask $stored): ?StoredTask
    {
        $current = $this->store->find($stored->task->id);
        return $current !== null && $current->task->state() === TaskState::Canceled ? $current : null;
    }

    /**
     * The artifact cut into chunks of a few words each, whitespace kept, so a
     * client that appends them gets the text back exactly. The first chunk
     * carries the name, description and metadata.
     *
     * @return list<array{0: Artifact, 1: bool}> chunk and whether it is the last one
     */
    private function chunks(Artifact $artifact): array
    {
        $pieces = [];
        $buffer = '';
        foreach (preg_split('/(?<=\s)(?=\S)/u', $artifact->text()) ?: [] as $word) {
            $buffer .= $word;
            if (mb_strlen($buffer) >= self::CHUNK_CHARACTERS) {
                $pieces[] = $buffer;
                $buffer = '';
            }
        }
        if ($buffer !== '' || $pieces === []) {
            $pieces[] = $buffer !== '' ? $buffer : ' ';
        }

        $mediaType = $artifact->parts[0]->mediaType;
        $chunks = [];
        $count = count($pieces);
        foreach ($pieces as $index => $piece) {
            $chunk = $index === 0
                ? new Artifact($artifact->artifactId, [Part::text($piece, $mediaType)], $artifact->name, $artifact->description, $artifact->metadata)
                : new Artifact($artifact->artifactId, [Part::text($piece, $mediaType)]);
            $chunks[] = [$chunk, $index === $count - 1];
        }
        return $chunks;
    }

    /**
     * Refuse what this agent cannot read or write before any task exists:
     * parts other than text, and clients that accept no text output.
     */
    private function checkContent(SendMessageParams $params): void
    {
        foreach ($params->message->parts as $index => $part) {
            $mediaType = strtolower($part->mediaType);
            if (!$part->isText() || ($mediaType !== '' && !str_starts_with($mediaType, 'text/'))) {
                throw new A2aException(
                    A2aError::ContentTypeNotSupported,
                    sprintf('This agent reads text parts only; message.parts[%d] is a %s part%s.', $index, $part->kind, $part->mediaType !== '' ? ' of ' . $part->mediaType : ''),
                    ['part' => (string)$index, 'mediaType' => $part->mediaType, 'supportedInputModes' => 'text/plain'],
                );
            }
        }

        if ($params->acceptedOutputModes === []) {
            return;
        }
        foreach ($params->acceptedOutputModes as $mode) {
            $mode = strtolower(trim(explode(';', $mode)[0]));
            if (in_array($mode, [...self::OUTPUT_MODES, 'text/*', '*/*'], true)) {
                return;
            }
        }
        throw new A2aException(
            A2aError::ContentTypeNotSupported,
            'This agent answers in text/markdown or text/plain, and the request accepts neither.',
            ['acceptedOutputModes' => implode(', ', $params->acceptedOutputModes), 'supportedOutputModes' => implode(', ', self::OUTPUT_MODES)],
        );
    }

    /**
     * A task with a client message nobody has worked on yet: queued by
     * `returnImmediately`, or left behind by a stream that closed before the
     * turn began.
     */
    private function isPending(Task $task): bool
    {
        $last = $task->lastMessage();
        return $last !== null
            && $last->role === Role::User
            && ($task->state() === TaskState::Submitted || $task->state() === TaskState::InputRequired);
    }

    private function find(string $taskId, CallContext $context): StoredTask
    {
        $context->correlate($taskId);
        return $this->store->find($taskId) ?? throw A2aException::taskNotFound($taskId);
    }

    private function busy(string $taskId): A2aException
    {
        return new A2aException(
            A2aError::UnsupportedOperation,
            sprintf('Task "%s" is still working on an earlier message. Send your message when it asks for input.', $taskId),
            ['taskId' => $taskId],
        );
    }
}
