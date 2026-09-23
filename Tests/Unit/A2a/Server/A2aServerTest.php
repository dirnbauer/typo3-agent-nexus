<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2a\Server;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2a\Protocol\A2aError;
use Webconsulting\AgentNexus\A2a\Protocol\A2aException;
use Webconsulting\AgentNexus\A2a\Protocol\Artifact;
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
use Webconsulting\AgentNexus\A2a\Server\A2aServer;
use Webconsulting\AgentNexus\A2a\Server\CallContext;
use Webconsulting\AgentNexus\A2a\Server\StoredTask;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\A2a\Service\TaskRunner;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Tests\Unit\A2a\Fixtures\InMemoryTaskStore;
use Webconsulting\AgentNexus\Tests\Unit\A2a\Fixtures\MemoryTaskLock;

/**
 * The task lifecycle, independent of any binding: what a stream says, what
 * the store keeps, and how a paused task continues.
 */
final class A2aServerTest extends UnitTestCase
{
    private InMemoryTaskStore $store;
    private MemoryTaskLock $lock;
    private A2aServer $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new InMemoryTaskStore();
        $this->lock = new MemoryTaskLock();
        $this->server = new A2aServer(
            $this->store,
            new TaskRunner(new SkillCatalog(), self::createStub(LanguageModel::class), self::createStub(UsageLedger::class)),
            $this->lock,
            new NullLogger(),
            0,
        );
    }

    #[Test]
    public function aNewTaskStreamsTheTaskThenWorkingThenItsArtifactThenCompleted(): void
    {
        $frames = $this->stream('Summarise the pricing page');

        self::assertSame('task', StreamResponse::memberOf($frames[0]), 'A task stream begins with the Task.');
        self::assertSame(TaskState::Submitted->value, $frames[0]['task']['status']['state']);
        self::assertSame(
            [TaskState::Working->value, TaskState::Completed->value],
            $this->states($frames),
        );
        $last = $this->lastOf($frames);
        self::assertSame('statusUpdate', StreamResponse::memberOf($last), 'The stream ends with the terminal status.');
        foreach ($frames as $frame) {
            self::assertCount(1, $frame, 'A StreamResponse carries exactly one member.');
        }
    }

    #[Test]
    public function theArtifactChunksCarryContentAndReassembleExactly(): void
    {
        $frames = $this->stream('Summarise the pricing page');
        $updates = array_values(array_filter($frames, static fn(array $frame): bool => isset($frame['artifactUpdate'])));

        self::assertGreaterThan(2, count($updates), 'The artifact streams in several chunks.');
        $text = '';
        foreach ($updates as $index => $frame) {
            $update = $frame['artifactUpdate'];
            self::assertNotEmpty($update['artifact']['parts'], 'Every chunk carries at least one part.');
            self::assertSame($index > 0, $update['append'], 'Only the first chunk opens the artifact.');
            self::assertSame($index === count($updates) - 1, $update['lastChunk']);
            $text .= $update['artifact']['parts'][0]['text'];
        }
        $skill = (new SkillCatalog())->get('summarize_page');
        self::assertSame($skill['artifactText'], $text);
        self::assertSame($skill['artifactName'], $updates[0]['artifactUpdate']['artifact']['name']);
    }

    #[Test]
    public function theStoreKeepsTheWholeTask(): void
    {
        $frames = $this->stream('Plan the onboarding of a new agency');
        $taskId = $frames[0]['task']['id'];

        $stored = $this->store->find($taskId);
        self::assertNotNull($stored);
        self::assertSame(TaskState::Completed, $stored->task->state());
        self::assertSame('plan_onboarding', $stored->task->metadataString('skillId'));
        self::assertSame('keywords', $stored->task->metadataString('routedBy'));
        self::assertSame('Plan onboarding', $stored->label);
        self::assertCount(1, $stored->task->artifacts);
        self::assertSame(['ROLE_USER', 'ROLE_AGENT', 'ROLE_AGENT'], array_map(
            static fn($message): string => $message->role->value,
            $stored->task->history,
        ), 'The user message and every status message are history.');
        self::assertSame(
            [TaskState::Submitted->value, TaskState::Working->value, TaskState::Working->value, TaskState::Completed->value],
            array_column($this->store->saves, 'state'),
            'Saved on creation, on every status and when the artifact is complete.',
        );
    }

    #[Test]
    public function aSkillThatNeedsInputPausesAndTheAnswerResumesTheSameTask(): void
    {
        $first = $this->stream('Draft an outreach email about our plans');
        $task = $first[0]['task'];
        self::assertSame([TaskState::Working->value, TaskState::InputRequired->value], $this->states($first), 'The stream closes at the question.');
        $question = $this->lastOf($first)['statusUpdate']['status']['message']['parts'][0]['text'];
        self::assertStringContainsString('audience', $question);

        $second = $this->stream('Agencies', ['taskId' => $task['id'], 'contextId' => $task['contextId']]);

        self::assertSame($task['id'], $second[0]['task']['id'], 'The answer continues the same task.');
        self::assertSame(TaskState::InputRequired->value, $second[0]['task']['status']['state'], 'The first frame is the task as the answer found it.');
        self::assertSame([TaskState::Working->value, TaskState::Completed->value], $this->states($second));
        $stored = $this->store->find($task['id']);
        self::assertNotNull($stored);
        self::assertSame(TaskState::Completed, $stored->task->state());
        self::assertSame('Draft an outreach email about our plans', $stored->task->request());
        self::assertSame('Agencies', $stored->task->history[3]->text(), 'The answer joins the history.');
        self::assertStringContainsString('(for: Agencies)', $stored->task->history[4]->text());
    }

    #[Test]
    public function aBlockingSendMessageReturnsTheFinishedTask(): void
    {
        $response = $this->server->sendMessage($this->params('Summarise the getting-started guide'), new CallContext());

        self::assertSame(TaskState::Completed->value, $response['task']['status']['state']);
        self::assertCount(1, $response['task']['artifacts']);
        self::assertCount(3, $response['task']['history']);
    }

    #[Test]
    public function returnImmediatelyQueuesTheTaskAndTheNextGetTaskWorksOnIt(): void
    {
        $response = $this->server->sendMessage(
            $this->params('Summarise the pricing page', [], ['returnImmediately' => true]),
            new CallContext(),
        );
        self::assertSame(TaskState::Submitted->value, $response['task']['status']['state']);

        $task = $this->server->getTask(new TaskIdParams($response['task']['id']), new CallContext());

        self::assertSame(TaskState::Completed->value, $task['status']['state']);
        self::assertArrayHasKey('artifacts', $task);
    }

    #[Test]
    public function aGetTaskWhileTheCreatingStreamRunsDoesNotRunTheTaskTwice(): void
    {
        $turn = $this->server->sendStreamingMessage($this->params('Summarise the pricing page'), new CallContext());
        $taskId = $turn->current()['task']['id'];

        $seen = $this->server->getTask(new TaskIdParams($taskId), new CallContext());
        self::assertSame(TaskState::Submitted->value, $seen['status']['state'], 'The stream that created the task works on it, not the reader.');

        while ($turn->valid()) {
            $turn->next();
        }
        $saves = array_count_values(array_column(array_filter($this->store->saves, static fn(array $save): bool => $save['taskId'] === $taskId), 'state'));
        self::assertSame(['TASK_STATE_SUBMITTED' => 1, 'TASK_STATE_WORKING' => 2, 'TASK_STATE_COMPLETED' => 1], $saves, 'One run: working, the artifact, completed.');
        self::assertSame([], $this->lock->held, 'The lock is released when the stream ends.');
    }

    #[Test]
    public function aMessageForAnUnknownTaskIsTaskNotFound(): void
    {
        $this->expectA2aError(A2aError::TaskNotFound);
        $this->stream('Hello', ['taskId' => 'no-such-task']);
    }

    #[Test]
    public function aFinishedTaskAcceptsNoMoreMessages(): void
    {
        $task = $this->stream('Summarise the pricing page')[0]['task'];

        $this->expectA2aError(A2aError::UnsupportedOperation);
        $this->stream('One more thing', ['taskId' => $task['id']]);
    }

    #[Test]
    public function aContextThatDoesNotMatchTheTaskIsRefused(): void
    {
        $task = $this->stream('Draft an outreach email')[0]['task'];

        $this->expectA2aError(A2aError::InvalidParams);
        $this->stream('Agencies', ['taskId' => $task['id'], 'contextId' => 'another-context']);
    }

    #[Test]
    public function aTaskOneRequestIsWorkingOnRefusesASecondAnswer(): void
    {
        $task = $this->stream('Draft an outreach email')[0]['task'];
        $this->lock->held[$task['id']] = true;

        $this->expectA2aError(A2aError::UnsupportedOperation);
        $this->stream('Agencies', ['taskId' => $task['id']]);
    }

    #[Test]
    public function aClientMayChooseTheContextOfANewTask(): void
    {
        $frames = $this->stream('Summarise the pricing page', ['contextId' => 'ctx-42']);

        self::assertSame('ctx-42', $frames[0]['task']['contextId']);
        foreach (array_slice($frames, 1) as $frame) {
            $event = $frame['statusUpdate'] ?? $frame['artifactUpdate'];
            self::assertSame('ctx-42', $event['contextId']);
        }
    }

    #[Test]
    public function cancelingEndsAPausedTaskAndIsIdempotent(): void
    {
        $task = $this->stream('Draft an outreach email')[0]['task'];

        $canceled = $this->server->cancelTask(new TaskIdParams($task['id']), new CallContext());
        self::assertSame(TaskState::Canceled->value, $canceled['status']['state']);

        $again = $this->server->cancelTask(new TaskIdParams($task['id']), new CallContext());
        self::assertSame(TaskState::Canceled->value, $again['status']['state'], 'A second cancel is not an error.');
    }

    #[Test]
    public function aCompletedTaskCannotBeCanceled(): void
    {
        $task = $this->stream('Summarise the pricing page')[0]['task'];

        $this->expectA2aError(A2aError::TaskNotCancelable);
        $this->server->cancelTask(new TaskIdParams($task['id']), new CallContext());
    }

    #[Test]
    public function aCancelDuringTheStreamEndsTheStream(): void
    {
        $turn = $this->server->sendStreamingMessage($this->params('Summarise the pricing page'), new CallContext());
        $taskId = $turn->current()['task']['id'];
        $turn->next(); // the working status
        $this->server->cancelTask(new TaskIdParams($taskId), new CallContext());

        $rest = [];
        while ($turn->valid()) {
            $rest[] = $turn->current();
            $turn->next();
        }

        $last = $this->lastOf($rest);
        self::assertSame(TaskState::Canceled->value, $last['statusUpdate']['status']['state']);
        $stored = $this->store->find($taskId);
        self::assertNotNull($stored);
        self::assertSame(TaskState::Canceled, $stored->task->state(), 'The turn does not overwrite the cancel.');
    }

    #[Test]
    public function aStreamTheClientDropsStillFinishesTheTask(): void
    {
        $turn = $this->server->sendStreamingMessage($this->params('Summarise the pricing page'), new CallContext());
        $taskId = $turn->current()['task']['id'];
        $turn->next(); // working
        $turn->next(); // first artifact chunk
        unset($turn);  // the client hung up

        $stored = $this->store->find($taskId);
        self::assertNotNull($stored);
        self::assertSame(TaskState::Completed, $stored->task->state());
        self::assertSame((new SkillCatalog())->get('summarize_page')['artifactText'], $stored->task->artifacts[0]->text());
    }

    #[Test]
    public function subscribingToAFinishedTaskIsUnsupported(): void
    {
        $task = $this->stream('Summarise the pricing page')[0]['task'];

        $this->expectA2aError(A2aError::UnsupportedOperation);
        $this->server->subscribeToTask(new TaskIdParams($task['id']), new CallContext())->current();
    }

    #[Test]
    public function subscribingToAPausedTaskGivesTheTaskAndCloses(): void
    {
        $task = $this->stream('Draft an outreach email')[0]['task'];

        $frames = iterator_to_array($this->server->subscribeToTask(new TaskIdParams($task['id']), new CallContext()), false);

        self::assertCount(1, $frames);
        self::assertSame(TaskState::InputRequired->value, $frames[0]['task']['status']['state']);
    }

    #[Test]
    public function subscribingToAQueuedTaskWorksOnIt(): void
    {
        $queued = $this->server->sendMessage($this->params('Summarise the pricing page', [], ['returnImmediately' => true]), new CallContext());

        $frames = iterator_to_array($this->server->subscribeToTask(new TaskIdParams($queued['task']['id']), new CallContext()), false);

        self::assertSame('task', StreamResponse::memberOf($frames[0]));
        self::assertSame([TaskState::Working->value, TaskState::Completed->value], $this->states($frames));
    }

    #[Test]
    public function aSubscriberFollowsATaskAnotherRequestIsWorkingOn(): void
    {
        $user = new Message('m-1', Role::User, [Part::text('Summarise the pricing page')], 'c-w', 't-w');
        $working = Message::fromAgent('Reading the page…', 'c-w', 't-w');
        $this->store->save(new StoredTask(new Task('t-w', 'c-w', TaskStatus::now(TaskState::Working, $working), [], [$user, $working])));
        $server = new A2aServer(
            $this->store,
            new TaskRunner(new SkillCatalog(), self::createStub(LanguageModel::class), self::createStub(UsageLedger::class)),
            $this->lock,
            new NullLogger(),
            2,
        );
        $finds = 0;
        $this->store->beforeFind = function (string $taskId) use (&$finds): void {
            // The third read sees what the other request did meanwhile.
            if (++$finds === 3) {
                $stored = $this->store->tasks[$taskId];
                $done = Message::fromAgent('Summary ready.', 'c-w', 't-w');
                $this->store->save($stored->withTask($stored->task
                    ->withArtifact(new Artifact('a-w', [Part::text('Three plans.')], 'summary.md'))
                    ->withStatus(TaskStatus::now(TaskState::Completed, $done))
                    ->withMessage($done)));
            }
        };

        $frames = iterator_to_array($server->subscribeToTask(new TaskIdParams('t-w'), new CallContext()), false);

        self::assertSame(['task', 'artifactUpdate', 'statusUpdate'], array_map(static fn(array $frame): string => (string)array_key_first($frame), $frames));
        self::assertSame(TaskState::Working->value, $frames[0]['task']['status']['state']);
        self::assertSame('Three plans.', $frames[1]['artifactUpdate']['artifact']['parts'][0]['text']);
        self::assertTrue($frames[1]['artifactUpdate']['lastChunk'], 'A finished artifact arrives whole.');
        self::assertSame(TaskState::Completed->value, $frames[2]['statusUpdate']['status']['state']);
    }

    #[Test]
    public function aSubscriberGivesUpAfterItsTimeBudget(): void
    {
        $user = new Message('m-1', Role::User, [Part::text('Hello')], 'c-s', 't-s');
        $working = Message::fromAgent('Working…', 'c-s', 't-s');
        $this->store->save(new StoredTask(new Task('t-s', 'c-s', TaskStatus::now(TaskState::Working, $working), [], [$user, $working])));

        $frames = iterator_to_array($this->server->subscribeToTask(new TaskIdParams('t-s'), new CallContext()), false);

        self::assertCount(1, $frames, 'With no time budget the subscriber sends the task and closes.');
    }

    #[Test]
    public function historyLengthLimitsTheReturnedHistory(): void
    {
        $task = $this->stream('Summarise the pricing page')[0]['task'];

        $none = $this->server->getTask(new TaskIdParams($task['id'], 0), new CallContext());
        self::assertArrayNotHasKey('history', $none, 'historyLength 0 omits the history.');

        $one = $this->server->getTask(new TaskIdParams($task['id'], 1), new CallContext());
        self::assertCount(1, $one['history']);
        self::assertSame('ROLE_AGENT', $one['history'][0]['role'], 'The most recent message.');

        $all = $this->server->getTask(new TaskIdParams($task['id']), new CallContext());
        self::assertCount(3, $all['history']);
    }

    #[Test]
    public function listTasksPagesNewestFirstWithATotal(): void
    {
        $ids = [];
        foreach (['one', 'two', 'three'] as $text) {
            $ids[] = $this->stream('Summarise ' . $text)[0]['task']['id'];
        }

        $first = $this->server->listTasks(ListTasksParams::fromArray(['pageSize' => 2]));
        self::assertSame([$ids[2], $ids[1]], array_column($first['tasks'], 'id'));
        self::assertSame(3, $first['totalSize']);
        self::assertSame(2, $first['pageSize']);
        self::assertNotSame('', $first['nextPageToken']);
        self::assertArrayNotHasKey('artifacts', $first['tasks'][0], 'Artifacts only when asked for.');

        $second = $this->server->listTasks(ListTasksParams::fromArray(['pageSize' => 2, 'pageToken' => $first['nextPageToken'], 'includeArtifacts' => true]));
        self::assertSame([$ids[0]], array_column($second['tasks'], 'id'));
        self::assertSame('', $second['nextPageToken'], 'The last page has an empty token.');
        self::assertArrayHasKey('artifacts', $second['tasks'][0]);
    }

    #[Test]
    public function widgetTasksAreListedOnlyByTheirContext(): void
    {
        $widget = new CallContext(Channel::Widget, 7);
        $task = iterator_to_array($this->server->sendStreamingMessage($this->params('Summarise the pricing page'), $widget), false)[0]['task'];

        self::assertSame(0, $this->server->listTasks(new ListTasksParams())['totalSize'], 'What a visitor typed is not listed to anyone.');
        self::assertSame(1, $this->server->listTasks(new ListTasksParams($task['contextId']))['totalSize']);
        $stored = $this->store->find($task['id']);
        self::assertNotNull($stored);
        self::assertSame(Channel::Widget, $stored->source);
        self::assertSame(7, $stored->pid);
    }

    #[Test]
    public function listTasksFiltersByState(): void
    {
        $this->stream('Summarise the pricing page');
        $paused = $this->stream('Draft an outreach email')[0]['task'];

        $list = $this->server->listTasks(ListTasksParams::fromArray(['status' => TaskState::InputRequired->value]));

        self::assertSame([$paused['id']], array_column($list['tasks'], 'id'));
    }

    #[Test]
    public function aNonTextPartIsAnUnsupportedContentType(): void
    {
        $this->expectA2aError(A2aError::ContentTypeNotSupported);
        $this->server->sendMessage(SendMessageParams::fromArray(['message' => [
            'messageId' => 'm-1',
            'role' => 'ROLE_USER',
            'parts' => [['url' => 'https://example.org/brief.pdf', 'mediaType' => 'application/pdf']],
        ]]), new CallContext());
    }

    #[Test]
    public function aClientThatAcceptsNoTextOutputIsRefused(): void
    {
        $this->expectA2aError(A2aError::ContentTypeNotSupported);
        $this->server->sendMessage($this->params('Summarise', [], ['acceptedOutputModes' => ['image/png']]), new CallContext());
    }

    #[Test]
    public function textWildcardsAreAcceptableOutputModes(): void
    {
        $response = $this->server->sendMessage($this->params('Summarise', [], ['acceptedOutputModes' => ['text/*']]), new CallContext());

        self::assertSame(TaskState::Completed->value, $response['task']['status']['state']);
    }

    #[Test]
    public function pushNotificationsAreNotSupported(): void
    {
        $this->expectA2aError(A2aError::PushNotificationNotSupported);
        $this->server->pushNotificationConfig();
    }

    #[Test]
    public function thereIsNoExtendedAgentCard(): void
    {
        $this->expectA2aError(A2aError::UnsupportedOperation);
        $this->server->extendedAgentCard();
    }

    /**
     * @param array<string, mixed> $message extra message members
     * @return list<array<string, mixed>>
     */
    private function stream(string $text, array $message = []): array
    {
        return iterator_to_array($this->server->sendStreamingMessage($this->params($text, $message), new CallContext()), false);
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $configuration
     */
    private function params(string $text, array $message = [], array $configuration = []): SendMessageParams
    {
        $params = ['message' => ['messageId' => 'm-' . bin2hex(random_bytes(4)), 'role' => 'ROLE_USER', 'parts' => [['text' => $text]]] + $message];
        if ($configuration !== []) {
            $params['configuration'] = $configuration;
        }
        return SendMessageParams::fromArray($params);
    }

    /**
     * @param list<array<string, mixed>> $frames
     * @return list<string>
     */
    private function states(array $frames): array
    {
        $states = [];
        foreach ($frames as $frame) {
            if (isset($frame['statusUpdate'])) {
                $states[] = $frame['statusUpdate']['status']['state'];
            }
        }
        return $states;
    }

    /**
     * @param list<array<string, mixed>> $frames
     * @return array<string, mixed>
     */
    private function lastOf(array $frames): array
    {
        $last = end($frames);
        self::assertIsArray($last, 'The stream carried no frames.');
        return $last;
    }

    private function expectA2aError(A2aError $error): void
    {
        $this->expectException(A2aException::class);
        $this->expectExceptionCode($error->code());
    }
}
