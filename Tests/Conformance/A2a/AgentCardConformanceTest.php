<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\A2a;

use PHPUnit\Framework\Attributes\Test;

/**
 * The published Agent Card against the A2A 1.0 schema: the card, each
 * interface, skill and the provider — and none of the 0.3 fields 1.0 removed.
 */
final class AgentCardConformanceTest extends A2aConformanceTestCase
{
    #[Test]
    public function theCardConformsToA2a10(): void
    {
        $card = self::card();

        self::assertA2a('AgentCard', $card);
        self::assertA2a('AgentProvider', self::map($card['provider']));
        self::assertConformsTo(self::schema('AgentCapabilities'), $card['capabilities']);
        self::assertNotSame([], $card['defaultInputModes'], 'A REQUIRED list has at least one entry.');
        self::assertNotSame([], $card['defaultOutputModes']);
        self::assertNotSame([], $card['skills']);
    }

    #[Test]
    public function everyInterfaceIsAbsoluteAndNamesItsVersion(): void
    {
        $interfaces = self::listOf(self::card()['supportedInterfaces']);

        self::assertCount(3, $interfaces);
        foreach ($interfaces as $interface) {
            self::assertA2a('AgentInterface', $interface);
            self::assertIsString($interface['url']);
            self::assertStringStartsWith(self::ORIGIN . '/', $interface['url']);
            self::assertContains($interface['protocolBinding'], ['JSONRPC', 'HTTP+JSON']);
            self::assertContains($interface['protocolVersion'], ['1.0', '0.3'], 'Major.Minor, no patch version.');
        }
        self::assertSame(['JSONRPC', '1.0'], [$interfaces[0]['protocolBinding'], $interfaces[0]['protocolVersion']], 'The first interface is the preferred one.');
    }

    #[Test]
    public function everySkillCarriesItsRequiredFields(): void
    {
        foreach (self::listOf(self::card()['skills']) as $skill) {
            self::assertA2a('AgentSkill', $skill);
            self::assertNotSame([], $skill['tags']);
        }
    }

    #[Test]
    public function noFieldThatA2a10RemovedIsSent(): void
    {
        $card = self::card();

        foreach (['url', 'preferredTransport', 'additionalInterfaces', 'protocolVersion', 'supportsAuthenticatedExtendedCard', 'security'] as $removed) {
            self::assertArrayNotHasKey($removed, $card);
        }
        self::assertArrayNotHasKey('stateTransitionHistory', self::map($card['capabilities']));
        self::assertArrayNotHasKey('securitySchemes', $card, 'A public agent leaves the security fields out rather than sending them empty.');
        self::assertArrayNotHasKey('securityRequirements', $card);
    }

    #[Test]
    public function aCardWithA03FieldIsRejectedByTheSchema(): void
    {
        $card = self::card();
        $card['preferredTransport'] = 'JSONRPC';

        self::assertViolates(self::schema('AgentCard'), $card, 'preferredTransport was removed in A2A 1.0.');
    }

    #[Test]
    public function capabilitiesUseTheir10Names(): void
    {
        $capabilities = self::card()['capabilities'];
        $legacy = self::map($capabilities);
        $legacy['stateTransitionHistory'] = true;

        self::assertSame(['streaming' => true, 'pushNotifications' => false, 'extendedAgentCard' => false], $capabilities);
        self::assertViolates(self::schema('AgentCapabilities'), $legacy, 'stateTransitionHistory is gone in 1.0.');
    }
}
