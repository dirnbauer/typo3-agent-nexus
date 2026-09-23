<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\A2a;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;

/**
 * The concierge shell: its chips come from the skill catalogue, it knows the
 * JSON-RPC endpoint, and every control is labelled.
 */
final class ConciergeWidgetTest extends AbstractA2aTestCase
{
    #[Test]
    public function theShellCarriesTheEndpointAndTheCatalogueSkills(): void
    {
        $routes = $this->get(RouteRegistry::class);
        $request = (new ServerRequest(self::BASE . 'a2a', 'GET', 'php://input', [], ['HTTP_HOST' => 'agent-nexus.test', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/index.php']))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: ['EXT:agent_nexus/Resources/Private/Templates/'],
            partialRootPaths: ['EXT:agent_nexus/Resources/Private/Partials/'],
            templatePathAndFilename: 'EXT:agent_nexus/Resources/Private/Templates/ConciergePlugin/Show.html',
            request: $request,
        ));
        $view->assignMultiple([
            'settings' => ['intro' => 'Ask our site agent.', 'placeholder' => 'Describe the task', 'show_events' => '1'],
            'data' => ['uid' => 42, 'header' => 'Site agent'],
            'pageId' => 7,
            'skills' => array_values($this->get(SkillCatalog::class)->all()),
            'endpoint' => $routes->get('a2a.jsonrpc')?->uri($routes->apiBasePath()),
        ]);

        $html = $view->render();

        self::assertStringContainsString('data-endpoint="/api/agent-nexus/a2a/jsonrpc"', $html);
        self::assertStringContainsString('data-ce="42"', $html);
        self::assertStringContainsString('data-page="7"', $html);
        foreach ($this->get(SkillCatalog::class)->all() as $id => $skill) {
            self::assertStringContainsString('data-skill="' . $id . '"', $html, 'Every catalogue skill has a chip.');
            self::assertStringContainsString('>' . htmlspecialchars($skill['name']) . '</button>', $html);
        }
        self::assertSame(1, substr_count($html, 'aria-pressed="true"'), 'The first chip starts active.');
        self::assertStringContainsString('aria-label="Describe the task"', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringNotContainsString('eID=', $html, 'No eID any more.');
    }
}
