<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Agentstack\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\Agentstack\Service\ProtocolCatalog;
use Webconsulting\AgentNexus\Agui\Service\EventCatalog;
use Webconsulting\AgentNexus\Ap2\Service\Jwt;
use Webconsulting\AgentNexus\Ap2\Service\MandateService;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;

/**
 * The catalogue's promise is that nothing in it is hand-maintained prose about
 * numbers: the facts come from the services that implement each protocol. The
 * tests below change nothing and assert that the derived values actually track
 * their sources.
 */
final class ProtocolCatalogTest extends UnitTestCase
{
    private ProtocolCatalog $subject;
    private SkillCatalog $skills;
    private EventCatalog $events;
    private Merchant $merchant;
    private ComponentRegistry $components;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skills = new SkillCatalog();
        $this->events = new EventCatalog();
        $this->merchant = new Merchant();
        $this->components = new ComponentRegistry();
        $this->subject = new ProtocolCatalog(
            $this->skills,
            $this->events,
            $this->merchant,
            new MandateService(new Jwt()),
            $this->components,
        );
    }

    #[Test]
    public function itDescribesExactlyTheFiveProtocolsTheExtensionImplements(): void
    {
        self::assertSame(['a2ui', 'agui', 'a2a', 'ucp', 'ap2'], $this->subject->protocols());
        self::assertCount(5, $this->subject->all());
    }

    #[Test]
    #[DataProvider('protocolProvider')]
    public function everyProtocolIsFullyDescribed(string $protocol): void
    {
        $entry = $this->subject->get($protocol);

        self::assertSame($protocol, $entry['key']);
        foreach (['label', 'name', 'edge', 'tagline', 'spec', 'diagram', 'diagramAlt'] as $field) {
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
            'a2ui' => ['a2ui'],
            'agui' => ['agui'],
            'a2a' => ['a2a'],
            'ucp' => ['ucp'],
            'ap2' => ['ap2'],
        ];
    }

    #[Test]
    public function anUnknownProtocolFallsBackInsteadOfFailing(): void
    {
        self::assertSame('a2ui', $this->subject->get('mcp')['key']);
        self::assertFalse($this->subject->has('mcp'));
        self::assertSame([], $this->subject->endpointIds('mcp'));
    }

    #[Test]
    public function theDiagramPointsAtACommittedBuildArtifact(): void
    {
        foreach ($this->subject->all() as $entry) {
            self::assertSame(
                'EXT:agent_nexus/Resources/Public/Diagrams/' . $entry['key'] . '.svg',
                $entry['diagram'],
            );
        }
    }

    #[Test]
    public function everyEndpointIsDeclaredWithAMethodAndAnExplanation(): void
    {
        foreach ($this->subject->all() as $entry) {
            foreach ($entry['endpoints'] as $endpoint) {
                self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $endpoint['id']);
                self::assertContains($endpoint['method'], ['GET', 'POST']);
                self::assertStringContainsString('eID=' . $endpoint['id'], $endpoint['path']);
                self::assertNotEmpty($endpoint['description']);
            }
        }
    }

    #[Test]
    public function theNineEndpointIdsAreUniqueAcrossProtocols(): void
    {
        $ids = [];
        foreach ($this->subject->protocols() as $protocol) {
            $ids = [...$ids, ...$this->subject->endpointIds($protocol)];
        }

        self::assertCount(9, $ids);
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    #[Test]
    public function theA2aFactsCountTheSkillsTheAgentActuallyAdvertises(): void
    {
        self::assertSame(
            (string)count($this->skills->all()),
            $this->fact('a2a', 'Advertised skills'),
        );
    }

    #[Test]
    public function theAguiFactsCountTheEventTypesTheCatalogueActuallyDefines(): void
    {
        $expected = array_sum(array_map(
            static fn(array $family): int => count($family['events']),
            $this->events->all(),
        ));

        self::assertSame((string)$expected, $this->fact('agui', 'Event types'));
        self::assertSame((string)count($this->events->all()), $this->fact('agui', 'Event families'));
    }

    #[Test]
    public function theUcpFactsFollowTheMerchantCatalogue(): void
    {
        self::assertSame((string)count($this->merchant->catalog()), $this->fact('ucp', 'Products'));
        self::assertSame(Merchant::CURRENCY, $this->fact('ucp', 'Currency'));
    }

    #[Test]
    public function theA2uiFactsFollowTheComponentRegistry(): void
    {
        self::assertSame(
            (string)count($this->components->getCatalogManifest()),
            $this->fact('a2ui', 'Catalog components'),
        );
    }

    #[Test]
    public function theAp2FactsComeFromAMandateChainThatWasActuallyVerified(): void
    {
        $checks = $this->fact('ap2', 'Chain checks');

        self::assertStringContainsString('Intent Mandate signature', $checks);
        self::assertStringContainsString('Within the spending cap', $checks);
    }

    private function fact(string $protocol, string $label): string
    {
        foreach ($this->subject->get($protocol)['facts'] as $fact) {
            if ($fact['label'] === $label) {
                return $fact['value'];
            }
        }
        self::fail('No fact labelled "' . $label . '" for ' . $protocol . '.');
    }
}
