<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Agentstack\Controller\OverviewController;

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
        self::assertStringContainsString('Not implemented', $html, 'MCP is referenced, not implemented.');
        self::assertStringContainsString('Specification versions', $html);
        self::assertSame(5, substr_count($html, 'class="card card-size-medium'), 'One card per protocol.');
    }

    #[Test]
    public function theSetupListNamesWhatIsMissing(): void
    {
        $html = self::body($this->get(OverviewController::class)->indexAction($this->moduleRequest('agentnexus_overview')));

        self::assertStringContainsString('No demo site yet', $html);
        self::assertStringContainsString('No language model installed', $html);
    }
}
