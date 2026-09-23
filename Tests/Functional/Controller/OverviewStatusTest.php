<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Webconsulting\AgentNexus\Agentstack\Command\SeedSiteCommand;
use Webconsulting\AgentNexus\Agentstack\Dto\ProtocolStatus;
use Webconsulting\AgentNexus\Agentstack\Service\ProtocolStatusService;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

/**
 * The overview's cards, built from real rows.
 *
 * The status service is what the overview module and the "Protocol hub"
 * element render, so these tests write protocol objects the way the protocols
 * do and assert on the DTOs the templates read — health, specification
 * version, counts, last activity and the links a card offers.
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
            self::assertNotEmpty($status->specVersion, $key . ' names no specification version');
            self::assertSame('agentnexus-module-' . $key, $status->icon);
            self::assertSame('agentnexus_' . $key, $status->moduleIdentifier);
            self::assertNull($status->lastRun, 'Nothing has run yet.');
            self::assertSame(0, $status->runsLast24h);
        }
    }

    #[Test]
    public function everyProtocolServesItsEndpointsThroughTheApiRouter(): void
    {
        foreach ($this->byKey() as $key => $status) {
            self::assertTrue($status->endpointsRegistered, $key . ' has no routes');
            self::assertGreaterThan(0, $status->endpointCount, $key);
        }
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
    public function withoutAModelEveryProtocolReportsItsScriptedMode(): void
    {
        foreach ($this->byKey() as $key => $status) {
            self::assertFalse($status->llmEnabled);
            self::assertSame('Scripted demo', $status->modeLabel);
            self::assertSame('nr-llm not installed', $status->llmReason, $key);
        }
    }

    #[Test]
    public function activityIsCountedFromEachProtocolsObjects(): void
    {
        $now = time();
        $this->object(ObjectKind::Run, 'run-1', $now - 60);
        $this->object(ObjectKind::Run, 'run-2', $now - 3600);
        $this->object(ObjectKind::Run, 'run-3', $now - 90000);
        $this->object(ObjectKind::Task, 'task-1', $now - 120);

        $statuses = $this->byKey();

        self::assertSame(2, $statuses['agui']->runsLast24h, 'Only the last 24 hours count.');
        self::assertSame($now - 60, $statuses['agui']->lastRun);
        self::assertTrue($statuses['agui']->hasActivity);
        self::assertSame(1, $statuses['a2a']->runsLast24h);
        self::assertSame(0, $statuses['ucp']->runsLast24h, 'One protocol\'s activity is not another\'s.');
    }

    #[Test]
    public function theActivityFeedMergesEveryProtocolNewestFirst(): void
    {
        $now = time();
        $this->object(ObjectKind::Checkout, 'chk_1', $now - 10);
        $this->object(ObjectKind::Run, 'run-1', $now - 500);
        $this->object(ObjectKind::Mandate, 'mandate-1', $now - 200);

        $feed = $this->subject->recentActivity();

        self::assertCount(3, $feed);
        self::assertSame(['ucp', 'ap2', 'agui'], array_column($feed, 'protocol'));
        self::assertSame('UCP', $feed[0]['label']);
        self::assertSame('chk_1', $feed[0]['object']);
        self::assertStringContainsString('inspector', $feed[0]['uri']);
    }

    #[Test]
    public function theFeedIsCappedAtTheRequestedLength(): void
    {
        for ($i = 1; $i <= 14; $i++) {
            $this->object(ObjectKind::Task, 'task-' . $i, time() - $i);
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
     * An object as a protocol stores it, last changed at the given time.
     */
    private function object(ObjectKind $kind, string $id, int $changed): void
    {
        $stored = $this->get(ObjectStore::class)->save(new ProtocolObject($kind, $id, state: 'working'));
        $this->getConnectionPool()->getConnectionForTable(ObjectStore::TABLE)
            ->update(ObjectStore::TABLE, ['tstamp' => $changed, 'crdate' => $changed], ['uid' => $stored->uid]);
    }

    private function seed(): void
    {
        $tester = new CommandTester($this->get(SeedSiteCommand::class));
        $tester->execute(['--base' => ['https://agent-nexus.test/']]);
        $tester->assertCommandIsSuccessful();
    }
}
