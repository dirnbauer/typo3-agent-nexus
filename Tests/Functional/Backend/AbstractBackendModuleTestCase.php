<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Backend;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

/**
 * Renders Agent Nexus backend screens the way the backend would: the real
 * module route (with its module, package and options), a backend admin and
 * that user's language service, then the controller action itself.
 */
abstract class AbstractBackendModuleTestCase extends AbstractAgentNexusTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function moduleRequest(string $routeIdentifier, array $query = []): ServerRequestInterface
    {
        $symfonyRoute = $this->get(Router::class)->getRoute($routeIdentifier);
        self::assertNotNull($symfonyRoute, 'No backend route ' . $routeIdentifier);
        // The backend routing middleware hands controllers this TYPO3 route,
        // built from the Symfony one with its identifier.
        $route = $symfonyRoute instanceof Route ? $symfonyRoute : Route::fromSymfonyRoute($symfonyRoute, $routeIdentifier);

        $uri = 'https://agent-nexus.test/typo3' . $route->getPath() . ($query === [] ? '' : '?' . http_build_query($query));
        $request = (new ServerRequest($uri, 'GET', 'php://input', [], [
            'HTTP_HOST' => 'agent-nexus.test',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/typo3/index.php',
            'REQUEST_URI' => '/typo3' . $route->getPath(),
        ]))
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withAttribute('module', $route->getOption('module'));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    protected static function body(ResponseInterface $response): string
    {
        self::assertSame(200, $response->getStatusCode());
        return (string)$response->getBody();
    }
}
