<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Agentstack;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\Agentstack\Service\ProtocolCatalog;
use Webconsulting\AgentNexus\Agentstack\Service\SpecificationVersions;
use Webconsulting\AgentNexus\Agui\Service\EventCatalog;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Protocol;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;

/**
 * The catalogue's promise is that nothing in it is hand-maintained prose about
 * numbers: the endpoints are the routes that are served, the versions come
 * from one registry and the facts from the services that implement each
 * protocol. These tests assert that every derived value tracks its source.
 */
final class ProtocolCatalogTest extends AbstractAgentNexusTestCase
{
    private ProtocolCatalog $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(ProtocolCatalog::class);
    }

    #[Test]
    public function itDescribesExactlyTheFiveProtocolsTheExtensionImplements(): void
    {
        self::assertSame(['a2ui', 'agui', 'a2a', 'ucp', 'ap2'], $this->subject->protocols());
        self::assertSame(Protocol::values(), $this->subject->protocols());
        self::assertCount(5, $this->subject->all());
    }

    #[Test]
    #[DataProvider('protocolProvider')]
    public function everyProtocolIsFullyDescribed(string $protocol): void
    {
        $entry = $this->subject->get($protocol);

        self::assertSame($protocol, $entry['key']);
        foreach (['label', 'name', 'edge', 'tagline', 'spec', 'specVersion', 'specLatest', 'diagram', 'diagramAlt'] as $field) {
            self::assertNotEmpty($entry[$field], $protocol . '.' . $field);
        }
        self::assertNotEmpty($entry['endpoints']);
        self::assertCount(4, $entry['howItWorks'], 'Each protocol is explained in four steps.');
        self::assertNotEmpty($entry['facts']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function protocolProvider(): array
    {
        return [
            'A2UI' => ['a2ui'],
            'AG-UI' => ['agui'],
            'A2A' => ['a2a'],
            'UCP' => ['ucp'],
            'AP2' => ['ap2'],
        ];
    }

    #[Test]
    public function anUnknownProtocolFallsBackInsteadOfFailing(): void
    {
        self::assertSame('a2ui', $this->subject->get('mcp')['key']);
        self::assertFalse($this->subject->has('mcp'));
    }

    #[Test]
    public function theDiagramPointsAtACommittedBuildArtifact(): void
    {
        foreach ($this->subject->protocols() as $protocol) {
            self::assertSame(
                'EXT:agent_nexus/Resources/Public/Diagrams/' . $protocol . '.svg',
                $this->subject->get($protocol)['diagram'],
            );
            self::assertFileExists(dirname(__DIR__, 3) . '/Resources/Public/Diagrams/' . $protocol . '.svg');
        }
    }

    #[Test]
    public function theEndpointsAreTheRoutesThatAreServed(): void
    {
        $registry = $this->get(RouteRegistry::class);
        foreach (Protocol::cases() as $protocol) {
            $endpoints = $this->subject->endpoints($protocol);
            self::assertSame(
                array_map(static fn($route): string => $route->id, $registry->forProtocol($protocol)),
                array_column($endpoints, 'id'),
            );
            foreach ($endpoints as $endpoint) {
                self::assertNotEmpty($endpoint['method']);
                self::assertStringStartsWith('/', $endpoint['path']);
                self::assertNotEmpty($endpoint['binding'], $endpoint['id'] . ' names no binding');
                self::assertNotEmpty($endpoint['description'], $endpoint['id'] . ' is not explained');
            }
        }
    }

    #[Test]
    public function theDiscoveryDocumentsLiveWhereTheSpecificationsPutThem(): void
    {
        $paths = array_merge(
            array_column($this->subject->endpoints(Protocol::A2a), 'path'),
            array_column($this->subject->endpoints(Protocol::Ucp), 'path'),
        );

        self::assertContains('/.well-known/agent-card.json', $paths);
        self::assertContains('/.well-known/ucp', $paths);
    }

    #[Test]
    public function theSpecificationVersionIsTheOneTheRegistryStates(): void
    {
        $versions = $this->get(SpecificationVersions::class);
        foreach (Protocol::cases() as $protocol) {
            $entry = $this->subject->get($protocol->value);
            self::assertSame($versions->for($protocol)['implemented'], $entry['specVersion']);
            self::assertSame($versions->for($protocol)['url'], $entry['spec']);
        }
    }

    #[Test]
    public function theA2aFactsCountTheSkillsTheAgentActuallyAdvertises(): void
    {
        self::assertSame(
            (string)count($this->get(SkillCatalog::class)->all()),
            $this->fact('a2a', 'Advertised skills'),
        );
    }

    #[Test]
    public function theAguiFactsCountTheEventTypesTheCatalogueActuallyDefines(): void
    {
        $families = $this->get(EventCatalog::class)->all();
        $expected = array_sum(array_map(static fn(array $f): int => count($f['events']), $families));

        self::assertSame((string)$expected, $this->fact('agui', 'Event types'));
        self::assertSame((string)count($families), $this->fact('agui', 'Event families'));
    }

    #[Test]
    public function theUcpFactsFollowTheMerchantCatalogue(): void
    {
        self::assertSame((string)count($this->get(Merchant::class)->catalog()), $this->fact('ucp', 'Products'));
        self::assertSame(Merchant::CURRENCY, $this->fact('ucp', 'Currency'));
    }

    #[Test]
    public function theA2uiFactsFollowTheBasicCatalogue(): void
    {
        self::assertSame(
            (string)count($this->get(ComponentRegistry::class)->getCatalogManifest()),
            $this->fact('a2ui', 'Catalogue components'),
        );
    }

    #[Test]
    public function theAp2FactsComeFromAMandateChainThatWasActuallyVerified(): void
    {
        $checks = $this->fact('ap2', 'Chain checks');

        self::assertStringContainsString('Within the spending cap', $checks);
        self::assertStringContainsString('Signed by the Trusted Surface', $checks);
    }

    private function fact(string $protocol, string $label): string
    {
        foreach ($this->subject->get($protocol)['facts'] as $fact) {
            if ($fact['label'] === $label) {
                return $fact['value'];
            }
        }
        self::fail(sprintf('%s has no fact "%s".', $protocol, $label));
    }
}
