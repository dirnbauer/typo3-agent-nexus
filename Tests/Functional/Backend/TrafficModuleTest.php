<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function longLogProvider(): array
    {
        return [
            'the first page links the next' => [[], 'Entries 1 to 50 of 53'],
            'the last page counts the rest' => [['page' => '2'], 'Entries 51 to 53 of 53'],
        ];
    }

    /**
     * One screen per test: the core's button bar outlives a module template,
     * so a second rendering in the same test would see the first one's buttons.
     *
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('longLogProvider')]
    public function aLongLogIsPagedWithItsTotal(array $query, string $range): void
    {
        $this->addEntries(53);

        $html = self::body($this->get(TrafficController::class)->listAction($this->moduleRequest('agentnexus_traffic', $query)));

        self::assertStringContainsString($range, $html);
        self::assertStringContainsString('page=', $html, 'The other page is linked.');
    }

    #[Test]
    public function aFilteredLogThatFitsOnOnePageHasNoPager(): void
    {
        $this->addEntries(53);

        $html = self::body($this->get(TrafficController::class)->listAction($this->moduleRequest('agentnexus_traffic', ['protocol' => 'ucp'])));

        self::assertStringContainsString('Operation53', $html);
        self::assertStringNotContainsString('Entries 1 to', $html, 'The 27 UCP entries fit on one page.');
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

    private function addEntries(int $count): void
    {
        $repository = $this->get(TrafficRepository::class);
        for ($i = 1; $i <= $count; $i++) {
            $repository->add($this->row($i % 2 === 0 ? 'a2a' : 'ucp', 'Operation' . $i, 'corr-' . $i));
        }
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
