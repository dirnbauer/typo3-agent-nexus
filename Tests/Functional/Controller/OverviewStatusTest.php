<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Webconsulting\AgentNexus\Agentstack\Command\SeedSiteCommand;
use Webconsulting\AgentNexus\Agentstack\Dto\ProtocolStatus;
use Webconsulting\AgentNexus\Agentstack\Service\ProtocolStatusService;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

/**
 * The hub's cards, built from real rows.
 *
 * The status service is what the overview module renders, so these tests write
 * log entries the way the protocols do and assert on the DTOs the template then
 * reads — health, counts, last run and the links a card offers.
 */
final class OverviewStatusTest extends AbstractAgentNexusTestCase
{
    private ProtocolStatusService $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(ProtocolStatusService::class);
    }

    #[Test]
    public function everyProtocolGetsACardEvenOnAnEmptyInstallation(): void
    {
        $statuses = $this->byKey();

        self::assertSame(['a2ui', 'agui', 'a2a', 'ucp', 'ap2'], array_keys($statuses));
        foreach ($statuses as $key => $status) {
            self::assertNotEmpty($status->label);
            self::assertNotEmpty($status->tagline);
            self::assertSame('agentnexus-module-' . $key, $status->icon);
            self::assertNull($status->lastRun, 'Nothing has run yet.');
            self::assertSame(0, $status->runsLast24h);
        }
    }

    #[Test]
    public function endpointsCountAsRegisteredWhenTheExtensionIsLoaded(): void
    {
        foreach ($this->byKey() as $key => $status) {
            self::assertTrue($status->endpointsRegistered, $key . ' reports missing endpoints');
        }

        self::assertSame(3, $this->byKey()['a2a']->endpointCount);
        self::assertSame(1, $this->byKey()['ap2']->endpointCount);
    }

    #[Test]
    public function aMissingEndpointTurnsTheCardRed(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['a2a_rpc']);

        $status = $this->byKey()['a2a'];

        self::assertFalse($status->endpointsRegistered);
        self::assertSame(ProtocolStatus::HEALTH_DANGER, $status->health);
        self::assertSame('agentnexus-status-danger', $status->healthIcon);
        self::assertSame('Endpoints missing', $status->healthLabel);
    }

    #[Test]
    public function withoutAStorageFolderTheCardWarnsButStaysUsable(): void
    {
        $status = $this->byKey()['ucp'];

        self::assertFalse($status->storageReady);
        self::assertSame(ProtocolStatus::HEALTH_WARN, $status->health);
        self::assertSame('agentnexus-status-warn', $status->healthIcon);
    }

    #[Test]
    public function aSeededSiteMakesEveryCardHealthyAndLinkable(): void
    {
        $this->seed();

        foreach ($this->byKey() as $key => $status) {
            self::assertTrue($status->storageReady, $key . ' has no storage folder');
            self::assertSame(ProtocolStatus::HEALTH_OK, $status->health);
            self::assertSame('Ready', $status->healthLabel);
            self::assertNotNull($status->frontendUrl, $key . ' has no demo page to link to');
            self::assertStringContainsString($key === 'agui' ? 'ag-ui' : $key, (string)$status->frontendUrl);
        }
    }

    #[Test]
    public function withoutAModelEveryProtocolReportsItsDeterministicMode(): void
    {
        foreach ($this->byKey() as $key => $status) {
            self::assertFalse($status->llmEnabled);
            self::assertSame('Scripted demo', $status->modeLabel);
            self::assertSame('nr-llm not installed', $status->llmReason, $key);
        }
    }

    #[Test]
    public function activityIsCountedFromEachProtocolsOwnLogTable(): void
    {
        $now = time();
        $this->log('tx_agentnexus_agui_run_log', 'request_date', $now - 60);
        $this->log('tx_agentnexus_agui_run_log', 'request_date', $now - 3600);
        $this->log('tx_agentnexus_agui_run_log', 'request_date', $now - 90000);
        $this->log('tx_agentnexus_a2a_task_log', 'request_date', $now - 120);

        $statuses = $this->byKey();

        self::assertSame(2, $statuses['agui']->runsLast24h, 'Only the last 24 hours count.');
        self::assertSame($now - 60, $statuses['agui']->lastRun);
        self::assertTrue($statuses['agui']->hasActivity);
        self::assertSame(1, $statuses['a2a']->runsLast24h);
        self::assertSame(0, $statuses['ucp']->runsLast24h, 'One protocol\'s activity is not another\'s.');
    }

    #[Test]
    public function a2uiActivityComesFromItsInquiriesBecauseItKeepsNoRunLog(): void
    {
        $this->log('tx_agentnexus_a2ui_inquiry', 'crdate', time() - 30);

        self::assertSame(1, $this->byKey()['a2ui']->runsLast24h);
    }

    #[Test]
    public function theActivityFeedMergesEveryProtocolNewestFirst(): void
    {
        $now = time();
        $this->log('tx_agentnexus_ucp_order_log', 'request_date', $now - 10);
        $this->log('tx_agentnexus_agui_run_log', 'request_date', $now - 500);
        $this->log('tx_agentnexus_ap2_mandate_log', 'request_date', $now - 200);

        $feed = $this->subject->recentActivity();

        self::assertCount(3, $feed);
        self::assertSame(['ucp', 'ap2', 'agui'], array_column($feed, 'protocol'));
        self::assertSame('Checkout run', $feed[0]['what']);
        self::assertSame('UCP', $feed[0]['label']);
    }

    #[Test]
    public function theFeedIsCappedAtTheRequestedLength(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->log('tx_agentnexus_a2a_task_log', 'request_date', time() - $i);
        }

        self::assertCount(10, $this->subject->recentActivity());
        self::assertCount(3, $this->subject->recentActivity(3));
    }

    #[Test]
    public function anEmptyInstallationHasAnEmptyFeedRatherThanAnError(): void
    {
        self::assertSame([], $this->subject->recentActivity());
    }

    /**
     * @return array<string, ProtocolStatus>
     */
    private function byKey(): array
    {
        $byKey = [];
        foreach ($this->get(ProtocolStatusService::class)->all() as $status) {
            $byKey[$status->key] = $status;
        }

        return $byKey;
    }

    /**
     * One row the way the protocol itself would write it — only pid, crdate and
     * the column the hub reads, since that is all the status service looks at.
     */
    private function log(string $table, string $timeColumn, int $time): void
    {
        $this->getConnectionPool()->getConnectionForTable($table)->insert($table, [
            'pid' => 0,
            'crdate' => $time,
            $timeColumn => $time,
        ]);
    }

    private function seed(): void
    {
        $tester = new CommandTester($this->get(SeedSiteCommand::class));
        $tester->execute(['--base' => ['https://agent-nexus.test/']]);
        $tester->assertCommandIsSuccessful();
    }
}
