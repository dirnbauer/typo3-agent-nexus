<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\A2ui;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Tests\Conformance\A2ui\A2uiSchemas;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

/**
 * The A2UI HTTP binding, driven through the real frontend middleware stack
 * the way a widget or another agent calls it. The test instance has no
 * nr-llm, so every surface comes from the built-in generator — the mode a
 * fresh installation is in.
 */
final class SurfaceEndpointTest extends AbstractAgentNexusTestCase
{
    private const string API = 'https://agent-nexus.test/api/agent-nexus/a2ui/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootPage();
        $this->writeTestSite();
        $this->get(CacheManager::class)->getCache('agentnexus')->flush();
    }

    #[Test]
    public function aRequestBecomesTheMessagesOfAV091Surface(): void
    {
        $response = $this->post('surfaces', ['intent' => 'I need a quote for a new website']);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $body = $this->json($response);
        self::assertSame('v0.9.1', $body['version']);
        self::assertSame(['mode' => 'builtin', 'label' => 'Scripted demo'], $body['provenance']);
        self::assertMatchesRegularExpression('/^quote-[0-9a-f]{8}$/', $body['surfaceId']);
        self::assertSame([], A2uiSchemas::errors($body['messages'], A2uiSchemas::V0_9_LIST));
        self::assertSame(['createSurface', 'updateComponents', 'updateDataModel'], array_map(
            static fn(array $message): string => (string)array_key_last($message),
            $body['messages'],
        ));

        $surface = $this->surface($body['surfaceId']);
        self::assertSame('created', $surface->state);
        self::assertSame('api', $surface->source);
        self::assertSame('I need a quote for a new website', $surface->label);
        self::assertSame(0, $surface->pid);
        self::assertSame('v0.9.1', $surface->payload['version']);
        self::assertSame($body['messages'], $surface->payload['messages']);
        self::assertSame([], $surface->payload['actions']);

        $traffic = $this->traffic($body['surfaceId']);
        self::assertCount(1, $traffic);
        self::assertSame('a2ui', $traffic[0]['protocol']);
        self::assertSame('createSurface', $traffic[0]['operation']);
        self::assertSame('api', $traffic[0]['channel']);
        self::assertSame(200, (int)$traffic[0]['status_code']);
    }

    #[Test]
    public function theReleaseCandidateIsAvailableOnRequest(): void
    {
        $body = $this->json($this->post('surfaces', ['intent' => 'a contact form', 'version' => 'v1.0']));

        self::assertSame('v1.0', $body['version']);
        self::assertCount(1, $body['messages'], 'v1.0 creates the surface in one message.');
        self::assertSame([], A2uiSchemas::errors($body['messages'], A2uiSchemas::V1_0_LIST));
    }

    #[Test]
    public function theVersionFollowsTheRendererCapabilities(): void
    {
        $body = $this->json($this->post('surfaces', [
            'intent' => 'a contact form',
            'a2uiRendererCapabilities' => ['v1.0' => ['supportedCatalogIds' => ['https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json']]],
        ]));

        self::assertSame('v1.0', $body['version']);
    }

    #[Test]
    public function badRequestsAreRefusedWithAnA2uiError(): void
    {
        $missing = $this->post('surfaces', ['version' => 'v0.9.1']);
        self::assertSame(422, $missing->getStatusCode());
        $error = $this->json($missing);
        self::assertSame('VALIDATION_FAILED', $error['error']['code']);
        self::assertSame('/intent', $error['error']['path']);
        self::assertSame([], A2uiSchemas::errors($error, A2uiSchemas::V0_9_CLIENT));

        self::assertSame('UNSUPPORTED_VERSION', $this->json($this->post('surfaces', ['intent' => 'x', 'version' => 'v0.8']))['error']['code']);
        self::assertSame(400, $this->post('surfaces', '{not json')->getStatusCode());
        self::assertSame(400, $this->post('surfaces', '["a list"]')->getStatusCode());
        self::assertSame(413, $this->post('surfaces', ['intent' => str_repeat('x', 20000)])->getStatusCode());
    }

    #[Test]
    public function aWidgetRequestIsRecordedAsTheWidgetsAndStoredOnItsPage(): void
    {
        $this->inquiryElement();

        $body = $this->json($this->post('surfaces', [
            'intent' => 'Book an introductory call',
            'agentNexus' => ['ce' => 10, 'page' => 1, 'url' => 'https://agent-nexus.test/'],
        ]));

        $surface = $this->surface($body['surfaceId']);
        self::assertSame('widget', $surface->source);
        self::assertSame(1, $surface->pid);
        self::assertSame('widget', $this->traffic($body['surfaceId'])[0]['channel']);
    }

    #[Test]
    public function anActionIsAnsweredWithAConfirmationAndStoredOnTheSurface(): void
    {
        $this->inquiryElement();
        $created = $this->json($this->post('surfaces', [
            'intent' => 'A contact form',
            'agentNexus' => ['ce' => 10, 'page' => 1, 'url' => 'https://agent-nexus.test/'],
        ]));
        $surfaceId = $created['surfaceId'];
        $dataModel = ['name' => 'Ada', 'email' => 'ada@example.org', 'subject' => 'Hello', 'message' => 'A question', 'consent' => true];

        $response = $this->post('actions', [
            'version' => 'v0.9.1',
            'action' => $this->action($surfaceId, 'sendMessage', 'submit', $dataModel),
            'metadata' => ['a2uiClientDataModel' => ['version' => 'v0.9.1', 'surfaces' => [$surfaceId => $dataModel]]],
            'agentNexus' => ['ce' => 10, 'page' => 1, 'url' => 'https://agent-nexus.test/'],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame([], A2uiSchemas::errors($body, A2uiSchemas::V0_9_LIST_WRAPPER), 'The answer is the official list wrapper.');
        self::assertSame(['updateComponents', 'updateDataModel'], array_map(static fn(array $m): string => (string)array_key_last($m), $body['messages']));
        self::assertStringContainsString('Thanks, we will be in touch.', (string)json_encode($body), 'The confirmation is the widget\'s own text.');

        $surface = $this->surface($surfaceId);
        self::assertSame('submitted', $surface->state);
        self::assertSame($dataModel, $surface->payload['dataModel'], 'The submitted inquiry is the surface\'s data model.');
        self::assertSame('sendMessage', $surface->payload['actions'][0]['action']['name']);
        self::assertCount(5, $surface->payload['messages'], 'The reply is part of the surface\'s message stream.');

        $traffic = $this->traffic($surfaceId);
        self::assertSame(['createSurface', 'action'], array_column($traffic, 'operation'));

        $again = $this->post('actions', ['version' => 'v0.9.1', 'action' => $this->action($surfaceId, 'sendMessage', 'submit', $dataModel)]);
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('SURFACE_ALREADY_SUBMITTED', $this->json($again)['error']['code']);
    }

    #[Test]
    public function startingOverDeletesTheSurface(): void
    {
        $surfaceId = $this->json($this->post('surfaces', ['intent' => 'a contact form', 'version' => 'v1.0']))['surfaceId'];

        $body = $this->json($this->post('actions', ['version' => 'v1.0', 'action' => $this->action($surfaceId, 'discard', 'discard', [])]));

        self::assertSame([['version' => 'v1.0', 'deleteSurface' => ['surfaceId' => $surfaceId]]], $body['messages']);
        self::assertSame([], A2uiSchemas::errors($body, A2uiSchemas::V1_0_LIST_WRAPPER));
        self::assertSame('deleted', $this->surface($surfaceId)->state);

        $gone = $this->post('actions', ['version' => 'v1.0', 'action' => $this->action($surfaceId, 'sendMessage', 'submit', [])]);
        self::assertSame(410, $gone->getStatusCode());
        self::assertSame('SURFACE_DELETED', $this->json($gone)['error']['code']);
    }

    #[Test]
    public function anUnknownSurfaceIsNotFound(): void
    {
        $response = $this->post('actions', ['version' => 'v0.9.1', 'action' => $this->action('nowhere-1', 'sendMessage', 'submit', [])]);

        self::assertSame(404, $response->getStatusCode());
        $error = $this->json($response);
        self::assertSame(['code' => 'SURFACE_NOT_FOUND', 'surfaceId' => 'nowhere-1', 'message' => 'This agent has no surface "nowhere-1".'], $error['error']);
        self::assertSame([], A2uiSchemas::errors($error, A2uiSchemas::V0_9_CLIENT));
        self::assertSame('nowhere-1', $this->traffic('nowhere-1')[0]['correlation_id']);
    }

    #[Test]
    public function anActionMustComeFromTheSurfaceAndInItsVersion(): void
    {
        $surfaceId = $this->json($this->post('surfaces', ['intent' => 'a contact form']))['surfaceId'];

        $foreign = $this->json($this->post('actions', ['version' => 'v0.9.1', 'action' => $this->action($surfaceId, 'sendMessage', 'elsewhere', [])]));
        self::assertSame('/action/sourceComponentId', $foreign['error']['path']);

        $version = $this->json($this->post('actions', ['version' => 'v1.0', 'action' => $this->action($surfaceId, 'sendMessage', 'submit', [])]));
        self::assertSame('/version', $version['error']['path']);

        $timestamp = $this->json($this->post('actions', ['version' => 'v0.9.1', 'action' => ['timestamp' => 'yesterday'] + $this->action($surfaceId, 'sendMessage', 'submit', [])]));
        self::assertSame('/action/timestamp', $timestamp['error']['path']);

        self::assertSame('created', $this->surface($surfaceId)->state);
    }

    #[Test]
    public function aReportedRendererErrorIsRecorded(): void
    {
        $surfaceId = $this->json($this->post('surfaces', ['intent' => 'a contact form']))['surfaceId'];

        $response = $this->post('actions', ['version' => 'v0.9.1', 'error' => [
            'code' => 'VALIDATION_FAILED', 'surfaceId' => $surfaceId, 'path' => '/components/3', 'message' => 'Unknown component',
        ]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['messages' => []], $this->json($response));
        self::assertSame('VALIDATION_FAILED', $this->surface($surfaceId)->payload['actions'][0]['error']['code']);
        self::assertSame('error', $this->traffic($surfaceId)[1]['operation']);
    }

    #[Test]
    public function aClientOverTheLimitIsTurnedAway(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::assertSame(200, $this->post('surfaces', ['intent' => 'a contact form'])->getStatusCode(), 'Request ' . ($i + 1));
        }

        $response = $this->post('surfaces', ['intent' => 'a contact form']);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('600', $response->getHeaderLine('Retry-After'));
        self::assertSame('RATE_LIMITED', $this->json($response)['error']['code']);
    }

    #[Test]
    public function theCatalogueDescribesTheAgent(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest(self::API . 'catalog'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('max-age=300', $response->getHeaderLine('Cache-Control'));
        $body = $this->json($response);
        self::assertSame([], A2uiSchemas::errors($body['capabilities'], A2uiSchemas::V0_9_SERVER_CAPABILITIES));
        self::assertSame([], A2uiSchemas::errors($body['capabilities'], A2uiSchemas::V1_0_AGENT_CAPABILITIES));
        self::assertSame('v0.9.1', $body['defaultVersion']);
        self::assertSame(self::API . 'surfaces', $body['endpoints']['surfaces']);
        self::assertSame(['v0.9.1', 'v1.0'], array_column($body['versions'], 'version'));
        self::assertCount(18, $body['versions'][0]['components']);
        self::assertCount(14, $body['versions'][1]['functions']);
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private function post(string $endpoint, array|string $body): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(is_string($body) ? $body : (string)json_encode($body));
        $stream->rewind();
        return $this->executeFrontendSubRequest(
            (new InternalRequest(self::API . $endpoint))
                ->withMethod('POST')
                ->withBody($stream)
                ->withHeader('Content-Type', 'application/json'),
        );
    }

    /**
     * @param array<string, mixed> $context
     * @return array{name: string, surfaceId: string, sourceComponentId: string, timestamp: string, context: array<string, mixed>|\stdClass}
     */
    private function action(string $surfaceId, string $name, string $source, array $context): array
    {
        return [
            'name' => $name,
            'surfaceId' => $surfaceId,
            'sourceComponentId' => $source,
            'timestamp' => '2026-09-23T10:15:00.000Z',
            'context' => $context === [] ? new \stdClass() : $context,
        ];
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

    private function surface(string $surfaceId): ProtocolObject
    {
        $surface = $this->get(ObjectStore::class)->find(ObjectKind::Surface, $surfaceId);
        self::assertNotNull($surface, 'No surface ' . $surfaceId);
        return $surface;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function traffic(string $correlationId): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_agentnexus_traffic');
        $rows = $queryBuilder
            ->select('*')
            ->from('tx_agentnexus_traffic')
            ->where($queryBuilder->expr()->eq('correlation_id', $queryBuilder->createNamedParameter($correlationId)))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
        return array_values($rows);
    }

    private function inquiryElement(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 10,
            'pid' => 1,
            'CType' => 'agentnexus_inquiry',
            'header' => 'Try it',
            'pi_flexform' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
                . '<field index="settings.success_message"><value index="vDEF">Thanks, we will be in touch.</value></field>'
                . '<field index="settings.business_context"><value index="vDEF">We sell bicycles.</value></field>'
                . '</language></sheet></data></T3FlexForms>',
        ]);
    }
}
