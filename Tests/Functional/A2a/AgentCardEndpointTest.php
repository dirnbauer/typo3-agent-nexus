<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\A2a;

use PHPUnit\Framework\Attributes\Test;

/**
 * Discovery: the Agent Card at the well-known address and below the API path.
 */
final class AgentCardEndpointTest extends AbstractA2aTestCase
{
    #[Test]
    public function theWellKnownAddressServesTheAgentCard(): void
    {
        $response = $this->fetch(self::BASE . '.well-known/agent-card.json');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $card = $this->json($response);
        self::assertSame('TYPO3 Site Agent', $card['name']);
        self::assertSame(
            [
                ['url' => 'https://agent-nexus.test/api/agent-nexus/a2a/jsonrpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '1.0'],
                ['url' => 'https://agent-nexus.test/api/agent-nexus/a2a/rest', 'protocolBinding' => 'HTTP+JSON', 'protocolVersion' => '1.0'],
                ['url' => 'https://agent-nexus.test/api/agent-nexus/a2a/jsonrpc', 'protocolBinding' => 'JSONRPC', 'protocolVersion' => '0.3'],
            ],
            $card['supportedInterfaces'],
            'Absolute URLs of this host, JSON-RPC 1.0 preferred.',
        );
        self::assertSame(['summarize_page', 'draft_outreach', 'plan_onboarding'], array_column(self::list($card['skills']), 'id'));
        self::assertSame(['streaming' => true, 'pushNotifications' => false, 'extendedAgentCard' => false], $card['capabilities']);
        foreach (['url', 'preferredTransport', 'additionalInterfaces', 'protocolVersion', 'supportsAuthenticatedExtendedCard', 'security', 'securitySchemes'] as $removed) {
            self::assertArrayNotHasKey($removed, $card, $removed . ' is not part of a 1.0 card (or has nothing to say here).');
        }
    }

    #[Test]
    public function theCardIsCacheableAndRevalidates(): void
    {
        $response = $this->fetch(self::BASE . '.well-known/agent-card.json');
        self::assertStringContainsString('max-age=300', $response->getHeaderLine('Cache-Control'));
        $etag = $response->getHeaderLine('ETag');
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);

        $again = $this->fetch(self::BASE . '.well-known/agent-card.json', ['If-None-Match' => $etag]);
        self::assertSame(304, $again->getStatusCode());
        self::assertSame('', (string)$again->getBody());
    }

    #[Test]
    public function theApiPathServesTheSameCard(): void
    {
        $wellKnown = $this->json($this->fetch(self::BASE . '.well-known/agent-card.json'));
        $api = $this->fetch('agent-card.json');

        self::assertSame(200, $api->getStatusCode());
        self::assertSame($wellKnown, $this->json($api));
        self::assertSame('*', $api->getHeaderLine('Access-Control-Allow-Origin'), 'The router makes every endpoint callable from another origin.');
    }

    #[Test]
    public function fetchingTheCardIsRecordedAsDiscovery(): void
    {
        $this->fetch(self::BASE . '.well-known/agent-card.json');

        $traffic = $this->rows('tx_agentnexus_traffic');
        self::assertCount(1, $traffic);
        self::assertSame('a2a', $traffic[0]['protocol']);
        self::assertSame('GetAgentCard', $traffic[0]['operation']);
        self::assertSame('api', $traffic[0]['channel']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);
        $list = [];
        foreach ($value as $item) {
            self::assertIsArray($item);
            $entry = [];
            foreach ($item as $key => $field) {
                $entry[(string)$key] = $field;
            }
            $list[] = $entry;
        }
        return $list;
    }
}
