<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2a\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2a\Protocol\A2aError;
use Webconsulting\AgentNexus\A2a\Protocol\A2aException;
use Webconsulting\AgentNexus\A2a\Protocol\Artifact;
use Webconsulting\AgentNexus\A2a\Protocol\ListTasksParams;
use Webconsulting\AgentNexus\A2a\Protocol\Message;
use Webconsulting\AgentNexus\A2a\Protocol\Part;
use Webconsulting\AgentNexus\A2a\Protocol\Role;
use Webconsulting\AgentNexus\A2a\Protocol\SendMessageParams;
use Webconsulting\AgentNexus\A2a\Protocol\Task;
use Webconsulting\AgentNexus\A2a\Protocol\TaskState;
use Webconsulting\AgentNexus\A2a\Protocol\TaskStatus;
use Webconsulting\AgentNexus\A2a\Protocol\Timestamp;

/**
 * Reading what clients send and writing what the spec expects: required
 * fields, the Part oneof, omitted optionals and the history window.
 */
final class DataModelTest extends UnitTestCase
{
    #[Test]
    public function aMessageRoundTrips(): void
    {
        $json = [
            'messageId' => 'm-1',
            'role' => 'ROLE_USER',
            'parts' => [['text' => 'Hello', 'mediaType' => 'text/plain'], ['data' => ['a' => 1]]],
            'contextId' => 'c-1',
            'taskId' => 't-1',
            'metadata' => ['skill' => 'plan_onboarding'],
            'extensions' => ['https://example.org/ext/v1'],
            'referenceTaskIds' => ['t-0'],
        ];

        self::assertSame($json, Message::fromArray($json)->toArray());
    }

    #[Test]
    #[DataProvider('invalidMessageProvider')]
    public function anInvalidMessageNamesTheField(mixed $message, string $field): void
    {
        try {
            Message::fromArray($message);
            self::fail('The message should have been refused.');
        } catch (A2aException $exception) {
            self::assertSame(A2aError::InvalidParams, $exception->error);
            self::assertSame($field, $exception->fieldViolations[0]['field']);
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function invalidMessageProvider(): array
    {
        return [
            'not an object' => ['hello', 'message'],
            'no message id' => [['role' => 'ROLE_USER', 'parts' => [['text' => 'x']]], 'message.messageId'],
            'no role' => [['messageId' => 'm', 'parts' => [['text' => 'x']]], 'message.role'],
            'a 0.3 role' => [['messageId' => 'm', 'role' => 'user', 'parts' => [['text' => 'x']]], 'message.role'],
            'no parts' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => []], 'message.parts'],
            'a part with two contents' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['text' => 'x', 'url' => 'https://a.b']]], 'message.parts[0]'],
            'a 0.3 part' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['kind' => 'file', 'file' => ['uri' => 'https://a.b']]]], 'message.parts[0]'],
            'raw that is not base64' => [['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['raw' => 'not base64!']]], 'message.parts[0].raw'],
        ];
    }

    #[Test]
    public function aZeroPointThreePartSaysWhatChanged(): void
    {
        $this->expectExceptionMessageMatches('/A2A 0\.3 part/');
        Part::fromArray(['kind' => 'data', 'value' => 1], 'part');
    }

    #[Test]
    public function aClientMessageMustComeFromTheUser(): void
    {
        $this->expectExceptionCode(-32602);
        SendMessageParams::fromArray(['message' => ['messageId' => 'm', 'role' => 'ROLE_AGENT', 'parts' => [['text' => 'x']]]]);
    }

    #[Test]
    public function anInlinePushConfigIsRefusedAsUnsupported(): void
    {
        $this->expectExceptionCode(-32003);
        SendMessageParams::fromArray([
            'message' => ['messageId' => 'm', 'role' => 'ROLE_USER', 'parts' => [['text' => 'x']]],
            'configuration' => ['taskPushNotificationConfig' => ['url' => 'https://example.org/hook']],
        ]);
    }

    #[Test]
    public function aTaskOmitsWhatIsEmpty(): void
    {
        $task = new Task('t-1', 'c-1', new TaskStatus(TaskState::Working));

        self::assertSame(['id' => 't-1', 'contextId' => 'c-1', 'status' => ['state' => 'TASK_STATE_WORKING']], $task->toArray());
        self::assertSame([], $task->toArray(null, true)['artifacts'], 'ListTasks with includeArtifacts shows an empty list.');
    }

    #[Test]
    public function historyLengthKeepsTheNewestMessages(): void
    {
        $task = new Task('t-1', 'c-1', new TaskStatus(TaskState::Working));
        foreach (['one', 'two', 'three'] as $text) {
            $task = $task->withMessage(new Message($text, Role::User, [Part::text($text)]));
        }

        self::assertSame(['two', 'three'], array_column($task->toArray(2)['history'], 'messageId'));
        self::assertCount(3, $task->toArray(10)['history'], 'More than there is means all of it.');
        self::assertArrayNotHasKey('history', $task->toArray(0));
    }

    #[Test]
    public function aStoredTaskReadsBack(): void
    {
        $task = (new Task('t-1', 'c-1', TaskStatus::now(TaskState::Completed, Message::fromAgent('Done.', 'c-1', 't-1'))))
            ->withMessage(new Message('m-1', Role::User, [Part::text('Go')], 'c-1', 't-1'))
            ->withArtifact(new Artifact('a-1', [Part::text('# Result', 'text/markdown')], 'result.md'))
            ->withMetadata(['skillId' => 'summarize_page']);

        self::assertSame($task->toArray(), Task::fromArray($task->toArray())->toArray());
    }

    #[Test]
    public function addingAnArtifactWithAKnownIdReplacesIt(): void
    {
        $task = (new Task('t-1', 'c-1', new TaskStatus(TaskState::Working)))
            ->withArtifact(new Artifact('a-1', [Part::text('draft')]))
            ->withArtifact(new Artifact('a-1', [Part::text('final')]));

        self::assertCount(1, $task->artifacts);
        self::assertSame('final', $task->artifacts[0]->text());
    }

    #[Test]
    public function timestampsHaveMillisecondsAndAZ(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', Timestamp::now());
        self::assertSame('2026-09-23T10:00:02.000Z', Timestamp::format(new \DateTimeImmutable('2026-09-23 12:00:02', new \DateTimeZone('Europe/Vienna'))));
        self::assertNotNull(Timestamp::parse('2026-09-23T10:00:00Z'));
        self::assertNotNull(Timestamp::parse('2026-09-23T10:00:00.123456789+02:00'));
        self::assertNull(Timestamp::parse('yesterday'));
    }

    /**
     * @param array<string, mixed> $params
     */
    #[Test]
    #[DataProvider('invalidListProvider')]
    public function listParametersAreChecked(array $params, string $field): void
    {
        try {
            ListTasksParams::fromArray($params);
            self::fail('Expected InvalidParams.');
        } catch (A2aException $exception) {
            self::assertSame($field, $exception->fieldViolations[0]['field']);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidListProvider(): array
    {
        return [
            'page size above 100' => [['pageSize' => 150], 'pageSize'],
            'page size 0' => [['pageSize' => '0'], 'pageSize'],
            'negative history' => [['historyLength' => -5], 'historyLength'],
            'unknown state' => [['status' => 'TASK_STATE_RUNNING'], 'status'],
            'a timestamp that is none' => [['statusTimestampAfter' => 'last week'], 'statusTimestampAfter'],
            'a flag that is none' => [['includeArtifacts' => 'yes'], 'includeArtifacts'],
        ];
    }

    #[Test]
    public function listParametersHaveDefaultsAndAcceptQueryStrings(): void
    {
        $defaults = ListTasksParams::fromArray([]);
        self::assertSame(50, $defaults->pageSize);
        self::assertFalse($defaults->includeArtifacts);
        self::assertNull($defaults->status);

        $query = ListTasksParams::fromArray(['pageSize' => '10', 'includeArtifacts' => 'true', 'status' => 'TASK_STATE_WORKING', 'historyLength' => '2']);
        self::assertSame(10, $query->pageSize);
        self::assertTrue($query->includeArtifacts);
        self::assertSame(TaskState::Working, $query->status);
        self::assertSame(2, $query->historyLength);
        self::assertNull(ListTasksParams::fromArray(['status' => 'TASK_STATE_UNSPECIFIED'])->status, 'UNSPECIFIED is no filter.');
    }

    #[Test]
    public function statesKnowWhereAStreamEnds(): void
    {
        $terminal = array_filter(TaskState::cases(), static fn(TaskState $state): bool => $state->isTerminal());
        $interrupted = array_filter(TaskState::cases(), static fn(TaskState $state): bool => $state->isInterrupted());

        self::assertSame([TaskState::Completed, TaskState::Failed, TaskState::Canceled, TaskState::Rejected], array_values($terminal));
        self::assertSame([TaskState::InputRequired, TaskState::AuthRequired], array_values($interrupted));
        self::assertSame('input-required', TaskState::InputRequired->legacyValue());
        self::assertSame(TaskState::AuthRequired, TaskState::fromLegacy('auth-required'));
        foreach (TaskState::cases() as $state) {
            self::assertSame($state, TaskState::fromLegacy($state->legacyValue()));
        }
    }
}
