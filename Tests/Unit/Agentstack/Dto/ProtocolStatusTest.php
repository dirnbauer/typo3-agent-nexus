<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Agentstack\Dto;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Agentstack\Dto\ProtocolStatus;

final class ProtocolStatusTest extends UnitTestCase
{
    #[Test]
    public function aProtocolWithoutRoutesIsBroken(): void
    {
        $status = $this->protocolStatus(routed: false, storage: true);

        self::assertSame(ProtocolStatus::HEALTH_DANGER, $status->health);
        self::assertSame('agentnexus-status-danger', $status->healthIcon);
        self::assertSame('Endpoints missing', $status->healthLabel);
    }

    #[Test]
    public function missingStorageOnlyWarns(): void
    {
        self::assertSame(ProtocolStatus::HEALTH_WARN, $this->protocolStatus(routed: true, storage: false)->health);
    }

    #[Test]
    public function routesAndStorageMakeItReady(): void
    {
        $status = $this->protocolStatus(routed: true, storage: true);

        self::assertSame(ProtocolStatus::HEALTH_OK, $status->health);
        self::assertSame('Ready', $status->healthLabel);
        self::assertFalse($status->hasActivity);
    }

    private function protocolStatus(bool $routed, bool $storage): ProtocolStatus
    {
        return new ProtocolStatus(
            key: 'a2a',
            label: 'A2A',
            name: 'Agent-to-Agent',
            tagline: 'An Agent Card tells other agents what this site can do.',
            icon: 'agentnexus-module-a2a',
            endpointsRegistered: $routed,
            endpointCount: $routed ? 12 : 0,
            llmEnabled: false,
            llmReason: 'nr-llm not installed',
            storageReady: $storage,
            lastRun: null,
            runsLast24h: 0,
            moduleIdentifier: 'agentnexus_a2a',
            playgroundUri: '',
            frontendUrl: null,
            specVersion: '1.0',
        );
    }
}
