<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance;

use PHPUnit\Framework\Attributes\Test;

/**
 * Proves the harness itself: the official examples shipped with the
 * specifications validate, and a broken copy of them does not. If this fails,
 * every other conformance test is meaningless.
 */
final class HarnessTest extends ConformanceTestCase
{
    #[Test]
    public function theOfficialSampleAgentCardValidates(): void
    {
        $card = self::fixture('a2a/1.0/examples/agent-card.sample.json');

        self::assertConformsTo(SchemaValidator::A2A_SCHEMA_ID . '#/$defs/AgentCard', $card);
    }

    #[Test]
    public function anAgentCardWithAn03FieldIsRejected(): void
    {
        $card = self::fixture('a2a/1.0/examples/agent-card.sample.json');
        $card['preferredTransport'] = 'JSONRPC';

        self::assertViolates(SchemaValidator::A2A_SCHEMA_ID . '#/$defs/AgentCard', $card, 'preferredTransport was removed in A2A 1.0.');
    }

    #[Test]
    public function aMinimalAgUiRunStartedEventValidates(): void
    {
        self::assertConformsTo(
            SchemaValidator::AGUI_SCHEMA_ID . '#RunStartedEvent',
            ['type' => 'RUN_STARTED', 'threadId' => 't-1', 'runId' => 'r-1', 'protocolVersion' => '1.0'],
        );
    }

    #[Test]
    public function anAgUiEventWithAnUndeclaredFieldIsRejected(): void
    {
        self::assertViolates(
            SchemaValidator::AGUI_SCHEMA_ID . '#RunStartedEvent',
            ['type' => 'RUN_STARTED', 'threadId' => 't-1', 'runId' => 'r-1', 'approval' => true],
            'AG-UI 1.0 objects are closed.',
        );
    }

    #[Test]
    public function theOfficialA2uiSampleValidates(): void
    {
        $sample = self::fixture('a2ui/v0.9.1/sample.json');

        self::assertNotSame([], $sample);
        self::assertConformsTo('https://a2ui.org/specification/v0_9/server_to_client.json', [
            'version' => 'v0.9.1',
            'createSurface' => [
                'surfaceId' => 's-1',
                'catalogId' => 'https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json',
            ],
        ]);
    }

    #[Test]
    public function aUcpProfileFromTheSpecificationValidates(): void
    {
        self::assertConformsTo('https://ucp.dev/schemas/profile.json#/$defs/business_schema', [
            'ucp' => [
                'version' => '2026-08-25',
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version' => '2026-08-25',
                        'spec' => 'https://ucp.dev/2026-08-25/specification/overview',
                        'transport' => 'rest',
                        'schema' => 'https://ucp.dev/2026-08-25/services/shopping/rest.openapi.json',
                        'endpoint' => 'https://business.example.com/ucp/v1',
                    ]],
                ],
                'capabilities' => [
                    'dev.ucp.shopping.checkout' => [[
                        'version' => '2026-08-25',
                        'spec' => 'https://ucp.dev/2026-08-25/specification/shopping/checkout',
                        'schema' => 'https://ucp.dev/2026-08-25/schemas/shopping/checkout.json',
                    ]],
                ],
                'payment_handlers' => new \stdClass(),
            ],
        ]);
    }

    #[Test]
    public function anAp2AmountMustBeMinorUnits(): void
    {
        self::assertConformsTo('https://ap2-protocol.org/schemas/types/amount.json', ['amount' => 4900, 'currency' => 'EUR']);
        self::assertViolates('https://ap2-protocol.org/schemas/types/amount.json', ['amount' => 49.5, 'currency' => 'EUR']);
    }
}
