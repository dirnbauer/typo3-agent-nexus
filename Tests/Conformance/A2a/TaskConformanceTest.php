<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\A2a;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\A2a\Protocol\A2aError;
use Webconsulting\AgentNexus\A2a\Protocol\A2aException;
use Webconsulting\AgentNexus\A2a\Protocol\ListTasksParams;
use Webconsulting\AgentNexus\A2a\Protocol\ProtocolVersion;
use Webconsulting\AgentNexus\A2a\Protocol\TaskIdParams;
use Webconsulting\AgentNexus\A2a\Server\CallContext;

/**
 * Every task-shaped payload the agent emits, from real runs: stream frames of
 * a completed and a paused task, the resumed turn, SendMessage, GetTask,
 * CancelTask and ListTasks — and the error objects.
 */
final class TaskConformanceTest extends A2aConformanceTestCase
{
    #[Test]
    public function everyFrameOfACompletedTaskConforms(): void
    {
        $frames = self::stream(self::server(), 'Summarise the pricing page');

        $members = [];
        foreach ($frames as $frame) {
            self::assertStreamResponse($frame);
            $members[] = array_key_first($frame);
        }
        self::assertSame('task', $members[0]);
        self::assertContains('statusUpdate', $members);
        self::assertContains('artifactUpdate', $members);
    }

    #[Test]
    public function thePausedTaskAndItsResumedTurnConform(): void
    {
        $server = self::server();
        $first = self::stream($server, 'Draft an outreach email');
        $task = self::map($first[0]['task']);
        $second = self::stream($server, 'Agencies', ['taskId' => $task['id'], 'contextId' => $task['contextId']]);

        foreach ([...$first, ...$second] as $frame) {
            self::assertStreamResponse($frame);
        }
    }

    #[Test]
    public function aStatusUpdateWithTheRemovedFinalFlagIsRejected(): void
    {
        $frames = self::stream(self::server(), 'Summarise the pricing page');
        $update = array_find($frames, static fn(array $frame): bool => isset($frame['statusUpdate']));
        self::assertNotNull($update);
        $event = self::map($update['statusUpdate']);
        $event['final'] = true;

        self::assertViolates(self::schema('TaskStatusUpdateEvent'), $event, 'A2A 1.0 removed "final".');
        self::assertViolates(self::schema('StreamResponse'), ['kind' => 'status-update'] + $event, 'and the "kind" discriminator.');
    }

    #[Test]
    public function sendMessageAnswersWithAConformingResponse(): void
    {
        $response = self::server()->sendMessage(self::params('Plan the onboarding'), new CallContext());

        self::assertA2a('SendMessageResponse', $response);
        self::assertExactlyOneOf($response, ['task', 'message'], 'SendMessageResponse');
        self::assertTask(self::map($response['task']));
    }

    #[Test]
    public function getTaskAndCancelTaskReturnConformingTasks(): void
    {
        $server = self::server();
        $paused = self::map(self::stream($server, 'Draft an outreach email')[0]['task']);
        $id = $paused['id'];
        self::assertIsString($id);

        self::assertTask($server->getTask(new TaskIdParams($id), new CallContext()));
        self::assertTask($server->getTask(new TaskIdParams($id, 0), new CallContext()));
        self::assertTask($server->cancelTask(new TaskIdParams($id), new CallContext()));
    }

    #[Test]
    public function listTasksAnswersWithAConformingPage(): void
    {
        $server = self::server();
        self::stream($server, 'Summarise the pricing page');
        self::stream($server, 'Draft an outreach email');
        self::stream($server, 'Plan the onboarding');

        foreach ([['pageSize' => 2], ['pageSize' => 2, 'includeArtifacts' => true, 'historyLength' => 1]] as $params) {
            $page = $server->listTasks(ListTasksParams::fromArray($params));
            self::assertA2a('ListTasksResponse', $page);
            foreach (self::listOf($page['tasks']) as $task) {
                self::assertTask($task);
            }
        }

        $empty = $server->listTasks(ListTasksParams::fromArray(['contextId' => 'nobody']));
        self::assertA2a('ListTasksResponse', $empty);
        self::assertSame('', $empty['nextPageToken'], 'The token is present, and empty on the last page.');
    }

    #[Test]
    public function aJsonRpcErrorCarriesTypedDetails(): void
    {
        foreach ([
            A2aException::taskNotFound('t-1'),
            A2aException::invalidParams('message', 'is required.'),
            ProtocolVersion::unsupported('2.0'),
            new A2aException(A2aError::Internal),
        ] as $exception) {
            $error = $exception->toJsonRpcError();
            self::assertIsInt($error['code']);
            self::assertIsString($error['message']);
            foreach ($error['data'] ?? [] as $detail) {
                self::assertArrayHasKey('@type', $detail, 'Every detail names its type (ProtoJSON Any).');
            }
        }
    }

    #[Test]
    public function aRestErrorIsAGoogleRpcStatusWithAnErrorInfo(): void
    {
        $body = A2aException::taskNotFound('t-1')->toRestError();

        self::assertSame(['code', 'status', 'message', 'details'], array_keys($body['error']));
        self::assertSame(404, $body['error']['code']);
        self::assertSame('NOT_FOUND', $body['error']['status']);
        $info = $body['error']['details'][0];
        self::assertSame('type.googleapis.com/google.rpc.ErrorInfo', $info['@type']);
        self::assertSame('TASK_NOT_FOUND', $info['reason']);
        self::assertSame('a2a-protocol.org', $info['domain']);
    }
}
