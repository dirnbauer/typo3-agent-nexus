<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\A2a;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * The HTTP+JSON binding: the same operations as JSON-RPC under resource URLs,
 * `application/a2a+json`, google.rpc.Status errors, bare stream frames — and
 * 1.0 only.
 */
final class RestEndpointTest extends AbstractA2aTestCase
{
    private const array V1 = ['A2A-Version' => '1.0'];

    #[Test]
    public function sendMessageAnswersWithTheTask(): void
    {
        $response = $this->post('rest/message:send', ['message' => self::message('Summarise the pricing page')], self::V1);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/a2a+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('1.0', $response->getHeaderLine('A2A-Version'));
        $task = $this->json($response)['task'];
        self::assertSame('TASK_STATE_COMPLETED', $task['status']['state']);

        $traffic = $this->rows('tx_agentnexus_traffic');
        self::assertSame('SendMessage', $traffic[0]['operation']);
        self::assertSame($task['id'], $traffic[0]['correlation_id']);
    }

    #[Test]
    public function aTaskCanBeReadListedAndCanceled(): void
    {
        $paused = $this->json($this->post('rest/message:send', ['message' => self::message('Draft an outreach email')], self::V1))['task'];

        $got = $this->fetch('rest/tasks/' . $paused['id'] . '?historyLength=0', self::V1);
        self::assertSame(200, $got->getStatusCode());
        self::assertArrayNotHasKey('history', $this->json($got));

        $list = $this->json($this->fetch('rest/tasks?status=TASK_STATE_INPUT_REQUIRED&pageSize=10&includeArtifacts=true', self::V1));
        self::assertSame(1, $list['totalSize']);
        self::assertSame($paused['id'], $list['tasks'][0]['id']);
        self::assertSame([], $list['tasks'][0]['artifacts'], 'includeArtifacts shows the list even when it is empty.');

        $canceled = $this->post('rest/tasks/' . $paused['id'] . ':cancel', null, self::V1);
        self::assertSame(200, $canceled->getStatusCode());
        self::assertSame('TASK_STATE_CANCELED', $this->json($canceled)['status']['state']);
    }

    #[Test]
    public function anUnknownTaskIs404WithAnErrorInfo(): void
    {
        $response = $this->fetch('rest/tasks/no-such-task', self::V1);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/a2a+json', $response->getHeaderLine('Content-Type'));
        $error = $this->json($response)['error'];
        self::assertSame(404, $error['code']);
        self::assertSame('NOT_FOUND', $error['status']);
        self::assertSame('type.googleapis.com/google.rpc.ErrorInfo', $error['details'][0]['@type']);
        self::assertSame('TASK_NOT_FOUND', $error['details'][0]['reason']);
        self::assertSame('a2a-protocol.org', $error['details'][0]['domain']);
        self::assertSame('no-such-task', $error['details'][0]['metadata']['taskId']);
    }

    #[Test]
    public function cancelingAFinishedTaskIs400NotCancelable(): void
    {
        $task = $this->json($this->post('rest/message:send', ['message' => self::message('Summarise')], self::V1))['task'];

        $response = $this->post('rest/tasks/' . $task['id'] . ':cancel', null, self::V1);

        self::assertSame(400, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('FAILED_PRECONDITION', $error['status']);
        self::assertSame('TASK_NOT_CANCELABLE', $error['details'][0]['reason']);
    }

    #[Test]
    public function streamingSendsBareStreamResponses(): void
    {
        $frames = $this->sse($this->post('rest/message:stream', ['message' => self::message('Plan onboarding')], self::V1));

        self::assertArrayHasKey('task', $frames[0]);
        foreach ($frames as $frame) {
            self::assertCount(1, $frame, 'No JSON-RPC envelope: one StreamResponse member.');
        }
        self::assertSame('TASK_STATE_COMPLETED', $frames[count($frames) - 1]['statusUpdate']['status']['state']);
    }

    #[Test]
    public function subscribeAcceptsGetAndPost(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $paused = $this->json($this->post('rest/message:send', ['message' => self::message('Draft an outreach email')], self::V1))['task'];
            $request = (new InternalRequest(self::API . 'rest/tasks/' . $paused['id'] . ':subscribe'))
                ->withMethod($method)
                ->withHeader('A2A-Version', '1.0');

            $frames = $this->sse($this->executeFrontendSubRequest($request));

            self::assertCount(1, $frames, $method . ': a paused task is the one frame of its stream.');
            self::assertSame('TASK_STATE_INPUT_REQUIRED', $frames[0]['task']['status']['state']);
        }
    }

    #[Test]
    public function withoutTheVersionHeaderTheRequestIsA03RequestAndRefused(): void
    {
        $response = $this->post('rest/message:send', ['message' => self::message('Hi')]);

        self::assertSame(400, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('VERSION_NOT_SUPPORTED', $error['details'][0]['reason']);
        self::assertSame('1.0', $error['details'][0]['metadata']['supportedVersions'], 'This binding speaks 1.0 only.');
        self::assertStringContainsString('A2A-Version: 1.0', $error['message']);
    }

    #[Test]
    public function pushNotificationsAndTheExtendedCardAreAnswered400(): void
    {
        $this->assertA2aError($this->post('rest/tasks/t-1/pushNotificationConfigs', ['url' => 'https://example.org/hook'], self::V1), 400, 'PUSH_NOTIFICATION_NOT_SUPPORTED');
        $this->assertA2aError($this->fetch('rest/tasks/t-1/pushNotificationConfigs', self::V1), 400, 'PUSH_NOTIFICATION_NOT_SUPPORTED');
        $this->assertA2aError($this->fetch('rest/tasks/t-1/pushNotificationConfigs/c-1', self::V1), 400, 'PUSH_NOTIFICATION_NOT_SUPPORTED');
        $this->assertA2aError($this->fetch('rest/extendedAgentCard', self::V1), 400, 'UNSUPPORTED_OPERATION');

        $operations = array_column($this->rows('tx_agentnexus_traffic'), 'operation');
        self::assertSame(['CreateTaskPushNotificationConfig', 'ListTaskPushNotificationConfigs', 'GetTaskPushNotificationConfig', 'GetExtendedAgentCard'], $operations);
    }

    #[Test]
    public function invalidInputIs400WithTheField(): void
    {
        $response = $this->fetch('rest/tasks?pageSize=500', self::V1);

        self::assertSame(400, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertSame('INVALID_ARGUMENT', $error['status']);
        self::assertSame('pageSize', $error['details'][0]['fieldViolations'][0]['field']);
    }

    private function assertA2aError(ResponseInterface $response, int $status, string $reason): void
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($reason, $this->json($response)['error']['details'][0]['reason']);
    }
}
