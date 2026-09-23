<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Agentstack\Controller\TrafficController;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRepository;

/**
 * The traffic log renders, filters, pages, polls and shows one exchange.
 */
final class TrafficModuleTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function anEmptyLogSaysHowToFillIt(): void
    {
        $html = self::body($this->get(TrafficController::class)->listAction($this->moduleRequest('agentnexus_traffic')));

        self::assertStringContainsString('<h1>Traffic</h1>', $html);
        self::assertStringContainsString('Nothing recorded yet', $html);
        self::assertStringContainsString('aria-live="polite"', $html, 'The live status must be announced.');
    }

    #[Test]
    public function entriesAreListedNewestFirst(): void
    {
        $repository = $this->get(TrafficRepository::class);
        $repository->add($this->row('a2a', 'SendMessage', 'task-1'));
        $repository->add($this->row('ucp', 'create_checkout', 'chk_1'));

        $html = self::body($this->get(TrafficController::class)->listAction($this->moduleRequest('agentnexus_traffic')));

        self::assertLessThan(strpos($html, 'SendMessage'), strpos($html, 'create_checkout'), 'Newest first.');
    }

    #[Test]
    public function theListCanBeFilteredByProtocol(): void
    {
        $repository = $this->get(TrafficRepository::class);
        $repository->add($this->row('a2a', 'SendMessage', 'task-1'));
        $repository->add($this->row('ucp', 'create_checkout', 'chk_1'));

        $html = self::body($this->get(TrafficController::class)->listAction($this->moduleRequest('agentnexus_traffic', ['protocol' => 'a2a'])));

        self::assertStringContainsString('SendMessage', $html);
        self::assertStringNotContainsString('create_checkout', $html);
        self::assertStringContainsString('Reset filter', $html);
    }

    #[Test]
    public function theLivePollReturnsOnlyNewerEntries(): void
    {
        $repository = $this->get(TrafficRepository::class);
        $seen = $repository->add($this->row('agui', 'RunAgent', 'run-1'));
        $repository->add($this->row('agui', 'RunAgent', 'run-2'));
        $repository->add($this->row('a2ui', 'createSurface', 'surface-1'));

        $response = $this->get(TrafficController::class)->pollAction(
            $this->moduleRequest('agentnexus_traffic', ['after' => (string)$seen, 'protocol' => 'agui']),
        );
        $data = json_decode((string)$response->getBody(), true);

        self::assertIsArray($data);
        self::assertCount(1, $data['rows']);
        self::assertSame('run-2', $data['rows'][0]['correlationId']);
    }

    #[Test]
    public function theDetailShowsBodiesAndStreamedFrames(): void
    {
        $uid = $this->get(TrafficRepository::class)->add($this->row('agui', 'RunAgent', 'run-9', [
            'is_stream' => 1,
            'event_count' => 2,
            'request_body' => '{"threadId":"t-1","runId":"run-9","messages":[]}',
            'events' => (string)json_encode([
                ['t' => 3, 'data' => ['type' => 'RUN_STARTED', 'threadId' => 't-1', 'runId' => 'run-9']],
                ['t' => 41, 'data' => ['type' => 'RUN_FINISHED', 'threadId' => 't-1', 'runId' => 'run-9']],
            ]),
        ]));

        $html = self::body($this->get(TrafficController::class)->detailAction(
            $this->moduleRequest('agentnexus_traffic.detail', ['uid' => (string)$uid]),
        ));

        self::assertStringContainsString('Exchange ' . $uid, $html);
        self::assertStringContainsString('RUN_STARTED', $html);
        self::assertStringContainsString('+41 ms', $html);
        self::assertStringContainsString('&quot;threadId&quot;: &quot;t-1&quot;', $html);
    }

    #[Test]
    public function aMissingEntryIsExplainedNotAnError(): void
    {
        $html = self::body($this->get(TrafficController::class)->detailAction(
            $this->moduleRequest('agentnexus_traffic.detail', ['uid' => '999']),
        ));

        self::assertStringContainsString('no longer exists', $html);
    }

    /**
     * @param array<string, int|string> $overrides
     * @return array<string, int|string>
     */
    private function row(string $protocol, string $operation, string $correlation, array $overrides = []): array
    {
        return $overrides + [
            'protocol' => $protocol,
            'channel' => 'api',
            'method' => 'POST',
            'endpoint' => 'POST /api/agent-nexus/' . $protocol,
            'operation' => $operation,
            'correlation_id' => $correlation,
            'status_code' => 200,
            'is_error' => 0,
            'is_stream' => 0,
            'duration_ms' => 12,
            'event_count' => 0,
            'request_headers' => '{"content-type":"application/json"}',
            'request_body' => '',
            'response_headers' => '{}',
            'response_body' => '',
            'events' => '',
            'error' => '',
            'be_user' => 0,
        ];
    }
}
