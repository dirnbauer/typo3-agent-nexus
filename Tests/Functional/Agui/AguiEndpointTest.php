<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Agui;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use Webconsulting\AgentNexus\Agui\Controller\RunController;
use Webconsulting\AgentNexus\Agui\Protocol\EventVerifier;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

/**
 * POST /api/agent-nexus/ag-ui through the real frontend middleware stack: the
 * stream a client receives, what is refused before a stream opens, the
 * approval as interrupt and resume, the run records and the traffic log.
 */
final class AguiEndpointTest extends AbstractAgentNexusTestCase
{
    private const string ENDPOINT = 'https://agent-nexus.test/api/agent-nexus/ag-ui';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootPage();
        $this->writeTestSite();
    }

    #[Test]
    public function aRunStreamsVerifiedAgUi10EventsAndIsRecorded(): void
    {
        $response = $this->post(self::runInput('thread-a', 'run-a1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/event-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $body = (string)$response->getBody();
        self::assertStringStartsWith(': AG-UI 1.0', $body);
        $events = self::events($body);
        EventVerifier::verify($events, 'thread-a', 'run-a1');
        $interrupt = self::interrupt($events);

        $run = $this->get(ObjectStore::class)->find(ObjectKind::Run, 'run-a1');
        self::assertNotNull($run);
        self::assertSame('interrupted', $run->state);
        self::assertSame('thread-a', $run->contextId);
        self::assertSame('api', $run->source);
        self::assertSame(count($events), $run->payload['eventCount'] ?? null);
        self::assertIsArray($run->payload['input'] ?? null);
        self::assertSame('1.0', $run->payload['input']['protocolVersion'] ?? null);
        self::assertIsArray($run->payload['interrupts'] ?? null);
        self::assertSame($interrupt['id'], $run->payload['interrupts'][0]['id'] ?? null);
        self::assertSame(['running', 'interrupted'], array_column($run->history, 'state'));

        $traffic = $this->traffic('run-a1');
        self::assertSame('RunAgent', $traffic['operation']);
        self::assertSame('api', $traffic['channel']);
        self::assertSame(1, (int)$traffic['is_stream']);
        self::assertSame(count($events), (int)$traffic['event_count']);
    }

    #[Test]
    public function anApprovalResumesTheRunAndStoresTheRequest(): void
    {
        $interrupt = self::interrupt($this->stream(self::runInput('thread-b', 'run-b1')));

        $response = $this->post(self::runInput('thread-b', 'run-b2', [
            'resume' => [['interruptId' => $interrupt['id'], 'status' => 'resolved', 'payload' => ['approved' => true, 'name' => 'Ada Lovelace', 'email' => 'ada@example.org']]],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $events = self::events((string)$response->getBody());
        EventVerifier::verify($events, 'thread-b', 'run-b2');
        $types = array_column($events, 'type');
        self::assertNotContains('TOOL_CALL_START', $types);
        $result = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'TOOL_CALL_RESULT'))[0] ?? [];
        self::assertSame($interrupt['toolCallId'], $result['toolCallId'] ?? null);
        $finished = self::last($events);
        self::assertSame(['type' => 'success'], $finished['outcome'] ?? null);

        $store = $this->get(ObjectStore::class);
        $resumed = $store->find(ObjectKind::Run, 'run-b2');
        self::assertNotNull($resumed);
        self::assertSame('finished', $resumed->state);
        self::assertIsArray($resumed->payload['result'] ?? null);
        self::assertSame(['name' => 'Ada Lovelace', 'email' => 'ada@example.org'], $resumed->payload['result']['lead'] ?? null);
        self::assertTrue($resumed->payload['result']['simulated'] ?? null);
        self::assertSame('interrupted', $store->find(ObjectKind::Run, 'run-b1')?->state, 'The interrupted run keeps how it ended.');
    }

    #[Test]
    public function aCancelledInterruptSendsNothing(): void
    {
        $interrupt = self::interrupt($this->stream(self::runInput('thread-c', 'run-c1')));

        $events = $this->stream(self::runInput('thread-c', 'run-c2', [
            'resume' => [['interruptId' => $interrupt['id'], 'status' => 'cancelled']],
        ]));

        EventVerifier::verify($events, 'thread-c', 'run-c2');
        $finished = self::last($events);
        self::assertIsArray($finished['result'] ?? null);
        self::assertSame('declined', $finished['result']['status'] ?? null);
        self::assertArrayNotHasKey('lead', $finished['result']);
    }

    #[Test]
    public function aForgedInterruptIsRefusedBeforeAnyStream(): void
    {
        $this->stream(self::runInput('thread-d', 'run-d1'));

        $response = $this->post(self::runInput('thread-d', 'run-d2', [
            'resume' => [['interruptId' => 'int_forged', 'status' => 'resolved', 'payload' => ['approved' => true, 'name' => 'Eve', 'email' => 'eve@example.org']]],
        ]));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('unknown_interrupt', self::error($response)['reason'] ?? null);
        self::assertNull($this->get(ObjectStore::class)->find(ObjectKind::Run, 'run-d2'), 'A refused run is not recorded.');
    }

    #[Test]
    public function anAnsweredInterruptCannotBeReplayed(): void
    {
        $interrupt = self::interrupt($this->stream(self::runInput('thread-e', 'run-e1')));
        $answer = ['resume' => [['interruptId' => $interrupt['id'], 'status' => 'resolved', 'payload' => ['approved' => true, 'name' => 'Ada', 'email' => 'ada@example.org']]]];
        self::assertNotSame([], $this->stream(self::runInput('thread-e', 'run-e2', $answer)));

        $response = $this->post(self::runInput('thread-e', 'run-e3', $answer));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('no_open_interrupt', self::error($response)['reason'] ?? null);
    }

    #[Test]
    public function aThreadWaitingForAnAnswerTakesNoNewRun(): void
    {
        $this->stream(self::runInput('thread-f', 'run-f1'));

        $response = $this->post(self::runInput('thread-f', 'run-f2'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('open_interrupt', self::error($response)['reason'] ?? null);
    }

    #[Test]
    public function aRunIdIsNeverUsedTwice(): void
    {
        $this->stream(self::runInput('thread-g', 'run-g1'));

        $response = $this->post(self::runInput('thread-other', 'run-g1'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('run_id_reused', self::error($response)['reason'] ?? null);
    }

    #[Test]
    public function aMalformedInputIsRefusedWith400AndNoStream(): void
    {
        $response = $this->post(['threadId' => 'thread-h', 'runId' => 'run-h1']);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('/messages', self::error($response)['pointer'] ?? null);
        self::assertNull($this->get(ObjectStore::class)->find(ObjectKind::Run, 'run-h1'));
    }

    #[Test]
    public function anotherMajorVersionIsRefused(): void
    {
        $response = $this->post(self::runInput('thread-i', 'run-i1', ['protocolVersion' => '2.0']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('unsupported_protocol_version', self::error($response)['reason'] ?? null);
    }

    #[Test]
    public function tooManyRunsFromOneAddressAreRefusedWith429(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(400, $this->post('{}', '10.9.9.9')->getStatusCode());
        }

        $response = $this->post('{}', '10.9.9.9');

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('600', $response->getHeaderLine('Retry-After'));
        self::assertSame('rate_limited', self::error($response)['reason'] ?? null);
        self::assertSame(400, $this->post('{}', '10.9.9.10')->getStatusCode(), 'Other addresses are not affected.');
    }

    #[Test]
    public function theWidgetIsRecordedAsTheWidget(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 7,
            'pid' => 1,
            'CType' => 'agentnexus_assistant',
            'pi_flexform' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
                . '<field index="settings.use_llm"><value index="vDEF">1</value></field></language></sheet></data></T3FlexForms>',
        ]);

        $events = $this->stream(self::runInput('thread-w', 'run-w1', [
            'forwardedProps' => ['agentNexus' => ['ce' => 7, 'page' => 1, 'url' => 'https://agent-nexus.test/', 'preset' => 'support']],
        ]));

        EventVerifier::verify($events, 'thread-w', 'run-w1');
        $provenance = $events[1]['value'] ?? null;
        self::assertIsArray($provenance);
        self::assertSame('scripted', $provenance['mode'], 'Without nr-llm the widget run stays scripted.');
        self::assertSame('nr-llm not installed', $provenance['reason'] ?? null);
        $run = $this->get(ObjectStore::class)->find(ObjectKind::Run, 'run-w1');
        self::assertNotNull($run);
        self::assertSame('widget', $run->source);
        self::assertSame(1, $run->pid);
        self::assertStringStartsWith('support', $run->label);
        self::assertIsArray($run->payload['input'] ?? null);
        self::assertArrayNotHasKey('forwardedProps', $run->payload['input'], 'The widget context is not part of the stored input.');
        self::assertSame('widget', $this->traffic('run-w1')['channel']);
    }

    #[Test]
    public function theEditorAgentsInterruptIsAnsweredInTheBackendOnly(): void
    {
        $response = $this->get(RunController::class)->run($this->backendRequest(self::runInput('thread-x', 'run-x1', [
            'forwardedProps' => ['agentNexus' => ['preset' => 'seo']],
        ])));
        self::assertSame(200, $response->getStatusCode());
        $events = self::events((string)$response->getBody());
        EventVerifier::verify($events, 'thread-x', 'run-x1');
        $interrupt = self::interrupt($events);
        self::assertSame('backend', $this->get(ObjectStore::class)->find(ObjectKind::Run, 'run-x1')?->source);

        $public = $this->post(self::runInput('thread-x', 'run-x2', [
            'resume' => [['interruptId' => $interrupt['id'], 'status' => 'resolved', 'payload' => ['approved' => true]]],
        ]));
        self::assertSame(409, $public->getStatusCode(), 'A visitor cannot approve an editor\'s change.');

        $backend = $this->get(RunController::class)->run($this->backendRequest(self::runInput('thread-x', 'run-x3', [
            'resume' => [['interruptId' => $interrupt['id'], 'status' => 'resolved', 'payload' => ['approved' => true]]],
        ])));
        $events = self::events((string)$backend->getBody());
        EventVerifier::verify($events, 'thread-x', 'run-x3');
        $finished = self::last($events);
        self::assertIsArray($finished['result'] ?? null);
        self::assertSame('applied', $finished['result']['status'] ?? null);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function runInput(string $threadId, string $runId, array $extra = []): array
    {
        return $extra + [
            'threadId' => $threadId,
            'runId' => $runId,
            'protocolVersion' => '1.0',
            'messages' => [['id' => 'msg-' . $runId, 'role' => 'user', 'content' => 'Which plan suits a team of five?']],
            'tools' => [],
            'context' => [],
            'state' => new \stdClass(),
            'forwardedProps' => new \stdClass(),
        ];
    }

    /**
     * Post a run and read its whole stream, which is when the run happens.
     *
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    private function stream(array $input): array
    {
        $response = $this->post($input);
        $body = (string)$response->getBody();
        self::assertSame(200, $response->getStatusCode(), $body);
        return self::events($body);
    }

    /**
     * @param array<string, mixed>|string $input
     */
    private function post(array|string $input, string $remoteAddress = '127.0.0.1'): ResponseInterface
    {
        $body = new Stream('php://temp', 'rw');
        $body->write(is_string($input) ? $input : (string)json_encode($input));
        $body->rewind();
        $request = (new InternalRequest(self::ENDPOINT))
            ->withMethod('POST')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'text/event-stream')
            ->withBody($body)
            ->withServerParams([
                'SCRIPT_NAME' => '/index.php',
                'HTTP_HOST' => 'agent-nexus.test',
                'SERVER_NAME' => 'agent-nexus.test',
                'HTTPS' => 'on',
                'REMOTE_ADDR' => $remoteAddress,
                'REQUEST_TIME_FLOAT' => microtime(true),
            ]);
        return $this->executeFrontendSubRequest($request);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function backendRequest(array $input): ServerRequest
    {
        $body = new Stream('php://temp', 'rw');
        $body->write((string)json_encode($input));
        $body->rewind();
        return new ServerRequest('https://agent-nexus.test/typo3/ajax/agentnexus/agui/run', 'POST', $body, ['Content-Type' => 'application/json']);
    }

    /**
     * The events of an SSE body, one per `data:` line.
     *
     * @return list<array<string, mixed>>
     */
    private static function events(string $body): array
    {
        $events = [];
        foreach (explode("\n\n", $body) as $frame) {
            if (!str_starts_with($frame, 'data: ')) {
                continue;
            }
            $event = json_decode(substr($frame, 6), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($event);
            $events[] = $event;
        }
        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function interrupt(array $events): array
    {
        $finished = self::last($events);
        self::assertSame('RUN_FINISHED', $finished['type'] ?? null);
        self::assertIsArray($finished['outcome'] ?? null);
        self::assertSame('interrupt', $finished['outcome']['type'] ?? null);
        self::assertIsArray($finished['outcome']['interrupts'][0] ?? null);
        return $finished['outcome']['interrupts'][0];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function last(array $events): array
    {
        self::assertNotSame([], $events, 'The stream is empty.');
        return $events[count($events) - 1];
    }

    /**
     * @return array<string, mixed>
     */
    private static function error(ResponseInterface $response): array
    {
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['error'] ?? null);
        return $body['error'];
    }

    /**
     * @return array<string, mixed>
     */
    private function traffic(string $runId): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable('tx_agentnexus_traffic')
            ->select(['*'], 'tx_agentnexus_traffic', ['correlation_id' => $runId])
            ->fetchAssociative();
        self::assertIsArray($row, 'No traffic entry for ' . $runId);
        return $row;
    }
}
