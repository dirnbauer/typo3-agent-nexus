<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Package\MetaData;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use Webconsulting\AgentNexus\Agentstack\Controller\OverviewController;
use Webconsulting\AgentNexus\Agentstack\Service\McpServerDetector;
use Webconsulting\AgentNexus\Agentstack\Service\ProtocolStatusService;
use Webconsulting\AgentNexus\Agentstack\Service\SiteLocator;
use Webconsulting\AgentNexus\Agentstack\Service\SpecificationVersions;
use Webconsulting\AgentNexus\Shared\Backend\ModuleFrame;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRepository;

/**
 * The overview renders for a backend user and states what it promises: a card
 * per protocol, the implemented and latest specification versions, and a setup
 * list — in the backend user's language.
 */
final class OverviewModuleTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function theOverviewShowsEveryProtocolAndItsSpecificationVersion(): void
    {
        $html = self::body($this->get(OverviewController::class)->indexAction($this->moduleRequest('agentnexus_overview')));

        self::assertStringContainsString('<h1>', $html);
        foreach (['A2UI', 'AG-UI', 'A2A', 'UCP', 'AP2', 'MCP'] as $label) {
            self::assertStringContainsString($label, $html);
        }
        self::assertStringContainsString('2026-08-25', $html, 'The UCP version is stated.');
        self::assertStringContainsString('v0.9.1', $html, 'The A2UI version is stated.');
        self::assertStringContainsString('Not installed', $html, 'Without hn/typo3-mcp-server there is no MCP server.');
        self::assertStringNotContainsString('Provided by', $html);
        self::assertStringContainsString('Specification versions', $html);
        self::assertSame(5, substr_count($html, 'class="card card-size-medium'), 'One card per protocol.');
    }

    #[Test]
    public function aGermanBackendReadsGermanCards(): void
    {
        $this->useBackendLanguage('de');

        $html = self::body($this->get(OverviewController::class)->indexAction($this->moduleRequest('agentnexus_overview')));

        self::assertStringContainsString('Der Agent beschreibt eine Oberfläche als Daten.', $html);
        self::assertStringContainsString('Ein Agentenlauf kommt als Strom typisierter Ereignisse an.', $html);
        self::assertStringNotContainsString('The agent describes an interface as data', $html);
        self::assertStringNotContainsString('An agent run arrives as a stream', $html);
        self::assertStringContainsString('Kein Sprachmodell: netresearch/nr-llm ist nicht installiert.', $html, 'The reason for the scripted mode is translated too.');
        self::assertStringNotContainsString('nr-llm not installed', $html);
    }

    #[Test]
    public function anInstalledMcpServerIsNamedWithItsProtocolVersionsAndModule(): void
    {
        $metaData = new MetaData('mcp_server');
        $metaData->setVersion('0.9.1');
        $package = self::createStub(PackageInterface::class);
        $package->method('getPackagePath')->willReturn(__DIR__ . '/../../Unit/Agentstack/Fixtures/mcp_server/');
        $package->method('getPackageMetaData')->willReturn($metaData);
        $packages = self::createStub(PackageManager::class);
        $packages->method('isPackageActive')->willReturnMap([['mcp_server', true]]);
        $packages->method('getPackage')->willReturnMap([['mcp_server', $package]]);
        $modules = self::createStub(ModuleProvider::class);
        $modules->method('isModuleRegistered')->willReturnMap([['user_mcp_server', true]]);
        $modules->method('accessGranted')->willReturnCallback(static fn(string $identifier): bool => $identifier === 'user_mcp_server');
        $uris = self::createStub(UriBuilder::class);
        $uris->method('buildUriFromRoute')->willReturnCallback(
            static fn(string $route): Uri => $route === 'user_mcp_server' ? new Uri('/typo3/module/user/mcp-server') : throw new \LogicException($route, 1758800102),
        );
        $controller = new OverviewController(
            $this->get(ModuleFrame::class),
            $this->get(ProtocolStatusService::class),
            $this->get(SpecificationVersions::class),
            $this->get(SiteLocator::class),
            $this->get(RouteRegistry::class),
            $this->get(TrafficRepository::class),
            $this->get(LanguageModel::class),
            $this->get(UsageLedger::class),
            $this->get(ExtensionSettings::class),
            $this->get(PackageManager::class),
            $this->get(UriBuilder::class),
            new McpServerDetector($packages, $modules, $uris, new NullLogger()),
        );

        $html = self::body($controller->indexAction($this->moduleRequest('agentnexus_overview')));

        self::assertStringContainsString('Provided by typo3-mcp-server 0.9.1', $html);
        self::assertStringContainsString('Protocol versions 2025-11-25, 2026-07-28', $html);
        self::assertStringContainsString('href="/typo3/module/user/mcp-server"', $html);
        self::assertStringContainsString('Open the MCP server module', $html);
        self::assertStringNotContainsString('Not installed', $html);
    }

    #[Test]
    public function theSetupListNamesWhatIsMissing(): void
    {
        $html = self::body($this->get(OverviewController::class)->indexAction($this->moduleRequest('agentnexus_overview')));

        self::assertStringContainsString('No demo site yet', $html);
        self::assertStringContainsString('No language model installed', $html);
    }
}
