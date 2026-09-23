<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\A2ui;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Stream;
use Webconsulting\AgentNexus\A2ui\Controller\A2uiModuleController;
use Webconsulting\AgentNexus\A2ui\Controller\PlaygroundAjaxController;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Tests\Conformance\A2ui\A2uiSchemas;
use Webconsulting\AgentNexus\Tests\Functional\Backend\AbstractBackendModuleTestCase;

/**
 * The A2UI backend screens and the playground's two routes, rendered and
 * called the way the backend does for an admin.
 */
final class PlaygroundModuleTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function thePlaygroundOffersTheExamplesAndBothVersions(): void
    {
        $html = self::body($this->get(A2uiModuleController::class)->playgroundAction($this->moduleRequest('agentnexus_a2ui_playground')));

        self::assertStringContainsString('<h1>A2UI playground</h1>', $html);
        self::assertStringContainsString('data-a2ui-playground', $html);
        self::assertStringContainsString('<label class="form-label" for="a2ui-intent">Describe the form you need</label>', $html);
        self::assertStringContainsString('value="Request a quote for a project"', $html);
        self::assertStringContainsString('value="v0.9.1"', $html);
        self::assertStringContainsString('value="v1.0"', $html);
        self::assertStringContainsString('https://agent-nexus.test/api/agent-nexus/a2ui/surfaces', $html);
        self::assertStringContainsString('No surfaces yet. Generate one above.', $html);
        self::assertStringContainsString('Scripted demo', $html, 'Without a model the screen says the generator answers.');
    }

    #[Test]
    public function generatingAndSendingWorkThroughTheBackendRoutes(): void
    {
        $controller = $this->get(PlaygroundAjaxController::class);

        $generated = $this->json($controller->generate($this->ajax('ajax_agentnexus_a2ui_generate', ['intent' => 'A newsletter sign-up form', 'version' => 'v0.9.1'])));
        self::assertSame([], A2uiSchemas::errors($generated['messages'], A2uiSchemas::V0_9_LIST));
        $surface = $this->get(ObjectStore::class)->find(ObjectKind::Surface, $generated['surfaceId']);
        self::assertNotNull($surface);
        self::assertSame('backend', $surface->source);
        self::assertSame(1, $surface->beUser);

        $answer = $this->json($controller->action($this->ajax('ajax_agentnexus_a2ui_action', [
            'version' => 'v0.9.1',
            'action' => [
                'name' => 'subscribeNewsletter',
                'surfaceId' => $generated['surfaceId'],
                'sourceComponentId' => 'submit',
                'timestamp' => '2026-09-23T10:15:00Z',
                'context' => ['email' => 'ada@example.org', 'topics' => ['product'], 'consent' => true],
            ],
        ])));
        self::assertSame([], A2uiSchemas::errors($answer, A2uiSchemas::V0_9_LIST_WRAPPER));
        self::assertSame('submitted', $this->get(ObjectStore::class)->find(ObjectKind::Surface, $generated['surfaceId'])?->state);

        $html = self::body($this->get(A2uiModuleController::class)->playgroundAction($this->moduleRequest('agentnexus_a2ui_playground')));
        self::assertStringContainsString($generated['surfaceId'], $html, 'The new surface is listed.');
        self::assertStringContainsString('A newsletter sign-up form', $html);
        self::assertStringContainsString('badge-success', $html);
    }

    #[Test]
    public function aBadRequestToTheBackendRouteIsAnA2uiError(): void
    {
        $response = $this->get(PlaygroundAjaxController::class)->generate($this->ajax('ajax_agentnexus_a2ui_generate', ['intent' => '']));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('/intent', $this->json($response)['error']['path']);
    }

    #[Test]
    public function theCatalogueShowsEveryComponentOfTheStableVersion(): void
    {
        $html = self::body($this->get(A2uiModuleController::class)->catalogAction($this->moduleRequest('agentnexus_a2ui_catalog')));

        self::assertStringContainsString('<h1>Basic catalogue</h1>', $html);
        self::assertStringContainsString('https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json', $html);
        self::assertSame(18, substr_count($html, 'class="card anx-a2ui__component"'));
        self::assertSame(18, substr_count($html, 'data-a2ui-example '));
        self::assertStringContainsString('<code>validationRegexp</code>', $html);
        self::assertStringContainsString('h1, h2, h3, h4, h5, caption, body', $html);
        self::assertStringNotContainsString('Release candidate</p>', $html);
        self::assertStringContainsString('<code>formatString</code>', $html);
        self::assertStringContainsString('<code>primaryColor</code>', $html, 'v0.9.1 has a theme.');

        preg_match_all('/data-a2ui-messages="([^"]*)"/', $html, $matches);
        foreach ($matches[1] as $encoded) {
            $messages = json_decode(html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5), false, 512, JSON_THROW_ON_ERROR);
            self::assertSame([], A2uiSchemas::errors($messages, A2uiSchemas::V0_9_LIST), 'Every live example is valid A2UI.');
        }
    }

    #[Test]
    public function theCatalogueShowsTheReleaseCandidateOnRequest(): void
    {
        $html = self::body($this->get(A2uiModuleController::class)->catalogAction($this->moduleRequest('agentnexus_a2ui_catalog', ['version' => 'v1.0'])));

        self::assertStringContainsString('https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json', $html);
        self::assertStringContainsString('v1.0 is still changing.', $html);
        self::assertStringContainsString('<code>placeholder</code>', $html);
        self::assertStringNotContainsString('<code>validationRegexp</code>', $html);
        self::assertStringNotContainsString('<code>primaryColor</code>', $html, 'v1.0 removed the theme.');
        self::assertStringContainsString('aria-current="page"', $html);

        preg_match_all('/data-a2ui-messages="([^"]*)"/', $html, $matches);
        self::assertCount(18, $matches[1]);
        foreach ($matches[1] as $encoded) {
            $messages = json_decode(html_entity_decode($encoded, ENT_QUOTES | ENT_HTML5), false, 512, JSON_THROW_ON_ERROR);
            self::assertSame([], A2uiSchemas::errors($messages, A2uiSchemas::V1_0_LIST));
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function ajax(string $route, array $body): ServerRequestInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write((string)json_encode($body));
        $stream->rewind();
        return $this->moduleRequest($route)
            ->withMethod('POST')
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $object = [];
        foreach ($data as $key => $value) {
            $object[(string)$key] = $value;
        }
        return $object;
    }
}
