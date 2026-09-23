<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\A2a;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * The JSON-RPC 2.0 binding through the real frontend stack: the 1.0 methods,
 * version negotiation, the 0.3 dialect, the task store behind them and the
 * traffic log beside them.
 */
final class JsonRpcEndpointTest extends AbstractA2aTestCase
{
    #[Test]
    public function sendMessageRunsATaskToTheEnd(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 'r-1', 'method' => 'SendMessage', 'params' => ['message' => self::message('Summarise the pricing page')]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('1.0', $response->getHeaderLine('A2A-Version'), 'The negotiated version is echoed.');
        $json = $this->json($response);
        self::assertSame('2.0', $json['jsonrpc']);
        self::assertSame('r-1', $json['id']);
        $task = $json['result']['task'];
        self::assertSame('TASK_STATE_COMPLETED', $task['status']['state']);
        self::assertSame('summary.md', $task['artifacts'][0]['name']);

        $objects = $this->rows('tx_agentnexus_object');
        self::assertCount(1, $objects, 'The task is kept in the object store.');
        self::assertSame('task', $objects[0]['kind']);
        self::assertSame($task['id'], $objects[0]['object_id']);
        self::assertSame($task['contextId'], $objects[0]['context_id']);
        self::assertSame('TASK_STATE_COMPLETED', $objects[0]['state']);
        self::assertSame('api', $objects[0]['source']);
        self::assertSame('Summarise a page', $objects[0]['label']);
        $payload = json_decode((string)$objects[0]['payload'], true);
        self::assertIsArray($payload);
        self::assertSame($task, $payload, 'The payload is the Task exactly as it went over the wire.');
        $history = json_decode((string)$objects[0]['history'], true);
        self::assertIsArray($history);
        self::assertSame(['TASK_STATE_SUBMITTED', 'TASK_STATE_WORKING', 'TASK_STATE_COMPLETED'], array_column($history, 'state'));
    }

    #[Test]
    public function theExchangeIsRecordedWithItsOperationAndTask(): void
    {
        $task = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendMessage', 'params' => ['message' => self::message('Plan onboarding')]]))['result']['task'];

        $traffic = $this->rows('tx_agentnexus_traffic');
        self::assertCount(1, $traffic);
        self::assertSame('a2a', $traffic[0]['protocol']);
        self::assertSame('SendMessage', $traffic[0]['operation']);
        self::assertSame($task['id'], $traffic[0]['correlation_id']);
        self::assertSame('api', $traffic[0]['channel']);
        self::assertSame(200, (int)$traffic[0]['status_code']);
    }

    #[Test]
    public function sendStreamingMessageStreamsEnvelopedFrames(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'SendStreamingMessage', 'params' => ['message' => self::message('Summarise the pricing page')]]);

        self::assertSame(200, $response->getStatusCode());
        $frames = $this->sse($response);
        foreach ($frames as $frame) {
            self::assertSame(['jsonrpc', 'id', 'result'], array_keys($frame));
            self::assertSame(7, $frame['id'], 'Every frame carries the request id.');
            self::assertCount(1, $frame['result'], 'Each result is one StreamResponse member.');
        }
        self::assertArrayHasKey('task', $frames[0]['result']);
        $last = $frames[count($frames) - 1]['result'];
        self::assertSame('TASK_STATE_COMPLETED', $last['statusUpdate']['status']['state']);

        $traffic = $this->rows('tx_agentnexus_traffic');
        self::assertSame(1, (int)$traffic[0]['is_stream']);
        self::assertSame(count($frames), (int)$traffic[0]['event_count']);
        self::assertSame($frames[0]['result']['task']['id'], $traffic[0]['correlation_id']);
    }

    #[Test]
    public function aPausedTaskResumesWithItsTaskId(): void
    {
        $frames = $this->sse($this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendStreamingMessage', 'params' => ['message' => self::message('Draft an outreach email')]]));
        $task = $frames[0]['result']['task'];
        $last = $frames[count($frames) - 1]['result'];
        self::assertSame('TASK_STATE_INPUT_REQUIRED', $last['statusUpdate']['status']['state']);

        $resumed = $this->sse($this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'SendStreamingMessage', 'params' => [
            'message' => self::message('Agencies', ['taskId' => $task['id'], 'contextId' => $task['contextId']]),
        ]]));

        self::assertSame($task['id'], $resumed[0]['result']['task']['id']);
        self::assertSame('TASK_STATE_COMPLETED', $resumed[count($resumed) - 1]['result']['statusUpdate']['status']['state']);
        $objects = $this->rows('tx_agentnexus_object');
        self::assertCount(1, $objects, 'One task, two turns.');
        self::assertSame('TASK_STATE_COMPLETED', $objects[0]['state']);
    }

    #[Test]
    public function getListAndCancelReadTheStore(): void
    {
        $finished = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendMessage', 'params' => ['message' => self::message('Summarise the pricing page')]]))['result']['task'];
        $paused = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'SendMessage', 'params' => ['message' => self::message('Draft an outreach email')]]))['result']['task'];

        $got = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'GetTask', 'params' => ['id' => $finished['id'], 'historyLength' => 1]]))['result'];
        self::assertSame($finished['id'], $got['id']);
        self::assertCount(1, $got['history']);

        $list = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ListTasks', 'params' => ['pageSize' => 1]]))['result'];
        self::assertSame(2, $list['totalSize']);
        self::assertSame(1, $list['pageSize']);
        self::assertCount(1, $list['tasks']);
        self::assertNotSame('', $list['nextPageToken']);
        $next = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'ListTasks', 'params' => ['pageSize' => 1, 'pageToken' => $list['nextPageToken']]]))['result'];
        self::assertCount(1, $next['tasks']);
        self::assertSame('', $next['nextPageToken']);
        self::assertEqualsCanonicalizing([$finished['id'], $paused['id']], [$list['tasks'][0]['id'], $next['tasks'][0]['id']]);

        $canceled = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'CancelTask', 'params' => ['id' => $paused['id']]]))['result'];
        self::assertSame('TASK_STATE_CANCELED', $canceled['status']['state']);

        $refused = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'CancelTask', 'params' => ['id' => $finished['id']]]));
        self::assertSame(-32002, $refused['error']['code']);
        self::assertSame('TASK_NOT_CANCELABLE', $refused['error']['data'][0]['reason']);
    }

    #[Test]
    public function subscribingToAFinishedTaskIsAnError(): void
    {
        $task = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendMessage', 'params' => ['message' => self::message('Summarise')]]))['result']['task'];

        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'SubscribeToTask', 'params' => ['id' => $task['id']]]);

        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'A refused stream is a plain error, not a stream.');
        self::assertSame(-32004, $this->json($response)['error']['code']);
    }

    #[Test]
    public function subscribingToAQueuedTaskStreamsItsWork(): void
    {
        $queued = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendMessage', 'params' => [
            'message' => self::message('Summarise the pricing page'),
            'configuration' => ['returnImmediately' => true],
        ]]))['result']['task'];
        self::assertSame('TASK_STATE_SUBMITTED', $queued['status']['state']);

        $frames = $this->sse($this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'SubscribeToTask', 'params' => ['id' => $queued['id']]]));

        self::assertSame($queued['id'], $frames[0]['result']['task']['id'], 'The first frame is the task as it is now.');
        self::assertSame('TASK_STATE_COMPLETED', $frames[count($frames) - 1]['result']['statusUpdate']['status']['state']);
    }

    #[Test]
    public function aMissingVersionHeaderMeans03AndA10MethodSaysWhatToSend(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendMessage', 'params' => ['message' => self::message('Hi')]], null);

        $json = $this->json($response);
        self::assertSame(200, $response->getStatusCode(), 'JSON-RPC errors travel with HTTP 200.');
        self::assertSame(-32601, $json['error']['code']);
        self::assertStringContainsString('A2A-Version: 1.0', $json['error']['message']);
        self::assertSame('0.3', $response->getHeaderLine('A2A-Version'));
    }

    #[Test]
    public function anUnsupportedVersionListsTheSupportedOnes(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendMessage', 'params' => ['message' => self::message('Hi')]], '2.0');

        $error = $this->json($response)['error'];
        self::assertSame(-32009, $error['code']);
        self::assertSame('VERSION_NOT_SUPPORTED', $error['data'][0]['reason']);
        self::assertSame('1.0, 0.3', $error['data'][0]['metadata']['supportedVersions']);
        self::assertSame('', $response->getHeaderLine('A2A-Version'), 'No version was agreed on.');
    }

    #[Test]
    public function theVersionMayAlsoComeAsAQueryParameter(): void
    {
        $request = (new InternalRequest(self::API . 'jsonrpc'))
            ->withQueryParameter('A2A-Version', '1.0')
            ->withMethod('POST');
        $body = new Stream('php://temp', 'rw');
        $body->write((string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'GetTask', 'params' => ['id' => 'missing']]));
        $body->rewind();

        $json = $this->json($this->executeFrontendSubRequest($request->withBody($body)));

        self::assertSame(-32001, $json['error']['code'], 'Understood as 1.0: GetTask exists, the task does not.');
    }

    #[Test]
    public function the03DialectAnswersIn03(): void
    {
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'message/send', 'params' => ['message' => [
            'kind' => 'message', 'messageId' => 'm-1', 'role' => 'user', 'parts' => [['kind' => 'text', 'text' => 'Summarise the pricing page']],
        ]]], null);

        $task = $this->json($response)['result'];
        self::assertSame('task', $task['kind'], 'message/send answers with the bare Task.');
        self::assertSame('completed', $task['status']['state']);
        self::assertSame('text', $task['artifacts'][0]['parts'][0]['kind']);
        self::assertSame('agent', $task['history'][1]['role']);

        $traffic = $this->rows('tx_agentnexus_traffic');
        self::assertSame('message/send', $traffic[0]['operation']);
    }

    #[Test]
    public function the03StreamEndsOnAFinalStatusUpdate(): void
    {
        $frames = $this->sse($this->rpc(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'message/stream', 'params' => ['message' => [
            'kind' => 'message', 'messageId' => 'm-2', 'role' => 'user', 'parts' => [['kind' => 'text', 'text' => 'Draft an outreach email']],
        ]]], null));

        self::assertSame('task', $frames[0]['result']['kind']);
        $last = $frames[count($frames) - 1]['result'];
        self::assertSame('status-update', $last['kind']);
        self::assertSame('input-required', $last['status']['state']);
        self::assertTrue($last['final']);
        $task = $frames[0]['result'];

        $resumed = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tasks/get', 'params' => ['id' => $task['id']]], null));
        self::assertSame('input-required', $resumed['result']['status']['state']);
    }

    #[Test]
    public function a03MethodNameUnder10SaysWhichMethodToCall(): void
    {
        $error = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tasks/get', 'params' => ['id' => 'x']]))['error'];

        self::assertSame(-32601, $error['code']);
        self::assertStringContainsString('GetTask', $error['message']);
    }

    #[Test]
    public function malformedRequestsGetTheStandardErrors(): void
    {
        $request = (new InternalRequest(self::API . 'jsonrpc'))->withMethod('POST');
        $body = new Stream('php://temp', 'rw');
        $body->write('{"jsonrpc": "2.0", "method": ');
        $body->rewind();
        $parse = $this->json($this->executeFrontendSubRequest($request->withBody($body)));
        self::assertSame(-32700, $parse['error']['code']);
        self::assertNull($parse['id']);

        self::assertSame(-32600, $this->json($this->rpc([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'GetTask']]))['error']['code'], 'Batches are not supported.');
        self::assertSame(-32600, $this->json($this->rpc(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'GetTask']))['error']['code']);
        self::assertSame(-32601, $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'Teleport']))['error']['code']);

        $invalid = $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendMessage', 'params' => ['message' => ['role' => 'ROLE_USER', 'parts' => []]]]));
        self::assertSame(-32602, $invalid['error']['code']);
        self::assertSame('type.googleapis.com/google.rpc.BadRequest', $invalid['error']['data'][0]['@type']);
    }

    #[Test]
    public function pushNotificationsAndTheExtendedCardAreNotOffered(): void
    {
        self::assertSame(-32003, $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'CreateTaskPushNotificationConfig', 'params' => ['taskId' => 't', 'url' => 'https://example.org/hook']]))['error']['code']);
        self::assertSame(-32003, $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ListTaskPushNotificationConfigs', 'params' => ['taskId' => 't']]))['error']['code']);
        self::assertSame(-32004, $this->json($this->rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'GetExtendedAgentCard']))['error']['code']);
    }

    #[Test]
    public function theWidgetIsRecognisedByItsMessageMetadata(): void
    {
        $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendMessage', 'params' => ['message' => self::message('Summarise the pricing page', [
            'metadata' => ['agentNexus' => ['ce' => 99, 'page' => 1, 'url' => 'https://agent-nexus.test/a2a']],
        ])]]);

        $traffic = $this->rows('tx_agentnexus_traffic');
        self::assertSame('widget', $traffic[0]['channel']);
        $objects = $this->rows('tx_agentnexus_object');
        self::assertSame('widget', $objects[0]['source']);
        self::assertSame(1, (int)$objects[0]['pid'], 'Stored on the page, as its site has no storage folder.');
    }

    #[Test]
    public function aClientOverTheRateLimitGets429(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->rpc(['jsonrpc' => '2.0', 'id' => $i, 'method' => 'GetTask', 'params' => ['id' => 'missing']]);
        }
        $response = $this->rpc(['jsonrpc' => '2.0', 'id' => 31, 'method' => 'GetTask', 'params' => ['id' => 'missing']]);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('600', $response->getHeaderLine('Retry-After'));
        $error = $this->json($response)['error'];
        self::assertSame(-32000, $error['code']);
        self::assertSame('RATE_LIMIT_EXCEEDED', $error['data'][0]['reason']);
    }
}
