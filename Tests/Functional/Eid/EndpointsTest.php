<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Eid;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

/**
 * All nine public endpoints, driven the way a browser drives them.
 *
 * Every test runs in deterministic mode — nr-llm is not installed in the test
 * instance, so LlmGuard refuses every call and each protocol answers with its
 * scripted demo. That is exactly the mode a fresh installation is in, and it is
 * the one that has to keep working: the demos must never depend on a model.
 */
final class EndpointsTest extends AbstractAgentNexusTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootPage();
        $this->writeTestSite();
    }

    #[Test]
    public function everyEndpointIsRegistered(): void
    {
        foreach ([
            'a2ui_generate', 'a2ui_submit', 'agui_assistant',
            'a2a_card', 'a2a_rpc', 'a2a_concierge',
            'ucp_manifest', 'ucp_checkout', 'ap2_authorize',
        ] as $eid) {
            self::assertArrayHasKey($eid, $GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include'], $eid . ' is not registered');
        }
    }

    #[Test]
    public function a2uiGenerateReturnsARenderableSurface(): void
    {
        $response = $this->post('a2ui_generate', ['intent' => 'I need a quote for a new website'], form: true);

        $json = $this->json($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($json['success']);
        self::assertSame('v1.0', $json['payload']['version']);

        $components = $json['payload']['createSurface']['components'];
        $ids = array_column($components, 'id');
        self::assertContains('root', $ids, 'An A2UI surface without a root cannot be rendered.');
        self::assertNotEmpty($json['provenance']['label']);
    }

    #[Test]
    public function a2uiGenerateRefusesAnEmptyIntent(): void
    {
        $response = $this->post('a2ui_generate', ['intent' => '  '], form: true);

        self::assertSame(400, $response->getStatusCode());
        self::assertFalse($this->json($response)['success']);
    }

    #[Test]
    public function a2uiSubmitStoresAnInquiry(): void
    {
        $response = $this->post('a2ui_submit', [
            'intent' => 'Website quote',
            'surfaceId' => 'demo',
            'payload' => (string)json_encode(['version' => 'v1.0']),
            'data' => (string)json_encode(['name' => 'Ada']),
            'page' => 1,
        ], form: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->json($response)['success']);
        self::assertSame(1, $this->rowCount('tx_agentnexus_a2ui_inquiry'));
    }

    #[Test]
    public function a2uiSubmitRefusesAnOversizedPayload(): void
    {
        $response = $this->post('a2ui_submit', ['payload' => str_repeat('x', 200001)], form: true);

        self::assertSame(413, $response->getStatusCode());
        self::assertSame(0, $this->rowCount('tx_agentnexus_a2ui_inquiry'));
    }

    #[Test]
    public function aguiAssistantStreamsAWellFormedEventSequence(): void
    {
        $response = $this->post('agui_assistant', ['preset' => 'plan', 'intent' => 'I need a plan', 'page' => 1]);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('text/event-stream', $response->getHeaderLine('Content-Type'));

        $events = $this->sse($response);
        $types = array_column($events, 'type');

        self::assertSame('RUN_STARTED', $types[0], 'Every AG-UI run opens with RUN_STARTED.');
        self::assertContains('RUN_FINISHED', $types, 'and closes with RUN_FINISHED.');
        self::assertContains('TEXT_MESSAGE_CONTENT', $types);
        self::assertContains('TOOL_CALL_START', $types, 'The human-in-the-loop gate is a tool call.');
        self::assertSame(1, $this->rowCount('tx_agentnexus_agui_run_log'));
    }

    #[Test]
    public function aguiAssistantOnlyStoresALeadAfterTheHumanApproves(): void
    {
        $this->post('agui_assistant', ['preset' => 'plan', 'intent' => 'I need a plan', 'page' => 1]);
        self::assertSame(0, $this->rowCount('tx_agentnexus_agui_lead'), 'Nothing is written before approval.');

        $this->post('agui_assistant', [
            'preset' => 'plan',
            'intent' => 'I need a plan',
            'page' => 1,
            'approval' => ['toolCallId' => 'tc-1', 'decision' => 'approved'],
            'lead' => ['name' => 'Ada', 'email' => 'ada@example.org'],
        ]);

        self::assertSame(1, $this->rowCount('tx_agentnexus_agui_lead'));
    }

    #[Test]
    public function a2aCardAdvertisesTheAgentAndItsSkills(): void
    {
        $card = $this->json($this->fetch('a2a_card'));

        self::assertSame('0.3.0', $card['protocolVersion']);
        self::assertStringContainsString('eID=a2a_rpc', $card['url']);
        self::assertTrue($card['capabilities']['streaming']);
        self::assertCount(3, $card['skills']);
        self::assertSame(
            ['summarize_page', 'draft_outreach', 'plan_onboarding'],
            array_column($card['skills'], 'id'),
        );
    }

    #[Test]
    public function a2aRpcStreamsATaskThroughItsLifecycle(): void
    {
        $response = $this->post('a2a_rpc', [
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'message/stream',
            'params' => ['message' => ['parts' => [['kind' => 'text', 'text' => 'Summarise the pricing page']]]],
        ]);

        self::assertSame(200, $response->getStatusCode());
        $frames = $this->sse($response);

        self::assertSame('task', $frames[0]['result']['kind']);
        self::assertSame(7, $frames[0]['id'], 'The JSON-RPC id is echoed on every frame.');

        $states = [];
        foreach ($frames as $frame) {
            if (($frame['result']['kind'] ?? '') === 'status-update') {
                $states[] = $frame['result']['status']['state'];
            }
        }
        self::assertSame(['working', 'completed'], $states);
        self::assertSame(1, $this->rowCount('tx_agentnexus_a2a_task_log'));
    }

    #[Test]
    public function a2aRpcRejectsAnUnknownMethodWithAJsonRpcError(): void
    {
        $response = $this->post('a2a_rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'message/teleport']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(-32601, $this->json($response)['error']['code']);
    }

    #[Test]
    public function a2aConciergeAnswersAndRecordsTheRequest(): void
    {
        $response = $this->post('a2a_concierge', [
            'prompt' => 'Draft an outreach email',
            'metadata' => ['skill' => 'draft_outreach'],
            'page' => 1,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertNotEmpty($this->sse($response));
        self::assertSame(1, $this->rowCount('tx_agentnexus_a2a_task_log'));
    }

    #[Test]
    public function ucpManifestDescribesTheMerchantAndItsCatalogue(): void
    {
        $manifest = $this->json($this->fetch('ucp_manifest'));

        self::assertSame('EUR', $manifest['merchant']['currency']);
        self::assertTrue($manifest['sandbox'], 'The demo store must announce that it is a sandbox.');
        self::assertTrue($manifest['capabilities']['humanAuthorization']);
        self::assertStringContainsString('eID=ucp_checkout', $manifest['endpoints']['checkout']);
        self::assertNotEmpty($manifest['catalog']);
    }

    #[Test]
    public function ucpCheckoutStopsAtTheAuthorizationGate(): void
    {
        $response = $this->post('ucp_checkout', ['intent' => 'pro', 'page' => 1]);

        self::assertSame(200, $response->getStatusCode());
        $types = array_column($this->sse($response), 'type');

        self::assertSame('checkout.started', $types[0]);
        self::assertContains('cart.updated', $types);
        self::assertContains('authorization.required', $types);
        self::assertNotContains('order.confirmed', $types, 'Nothing is confirmed without a human decision.');
        self::assertSame(1, $this->rowCount('tx_agentnexus_ucp_order_log'));
    }

    #[Test]
    public function ap2AuthorizeVerifiesTheMandateChain(): void
    {
        $response = $this->post('ap2_authorize', [
            'capCents' => 50000,
            'page' => 1,
            'cart' => [
                'merchant' => 'desiderio-store',
                'currency' => 'EUR',
                'totalCents' => 44800,
                'items' => [['id' => 'agency-bundle', 'price' => 14900]],
            ],
        ]);

        $json = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($json['authorized']);
        self::assertCount(5, $json['checks']);
        self::assertStringStartsWith('ey', $json['intentJwt'], 'A mandate is a compact JWS.');
        self::assertSame(1, $this->rowCount('tx_agentnexus_ap2_mandate_log'));
        self::assertSame(1, $this->rowCount('tx_agentnexus_ap2_authorization'));
    }

    #[Test]
    public function ap2AuthorizeRefusesACartOverTheCap(): void
    {
        $json = $this->json($this->post('ap2_authorize', [
            'capCents' => 1000,
            'page' => 1,
            'cart' => ['merchant' => 'desiderio-store', 'currency' => 'EUR', 'totalCents' => 44800, 'items' => []],
        ]));

        self::assertFalse($json['authorized']);
    }

    /* ---- helpers ---------------------------------------------------------- */

    /**
     * @param array<string, mixed> $payload
     */
    private function post(string $eid, array $payload, bool $form = false): \Psr\Http\Message\ResponseInterface
    {
        $request = (new InternalRequest(self::BASE . 'index.php'))
            ->withQueryParameter('eID', $eid)
            ->withMethod('POST');

        if ($form) {
            $request = $request->withParsedBody($payload)
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        } else {
            $body = new Stream('php://temp', 'rw');
            $body->write((string)json_encode($payload));
            $body->rewind();
            $request = $request->withBody($body)->withHeader('Content-Type', 'application/json');
        }

        return $this->executeFrontendSubRequest($request);
    }

    private function fetch(string $eid): \Psr\Http\Message\ResponseInterface
    {
        return $this->executeFrontendSubRequest(
            (new InternalRequest(self::BASE . 'index.php'))->withQueryParameter('eID', $eid),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded, 'Response was not JSON: ' . (string)$response->getBody());
        return $decoded;
    }

    /**
     * Parse an SSE body into the decoded payload of every `data:` frame.
     *
     * @return list<array<string, mixed>>
     */
    private function sse(\Psr\Http\Message\ResponseInterface $response): array
    {
        $events = [];
        foreach (explode("\n", (string)$response->getBody()) as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $decoded = json_decode(trim(substr($line, 5)), true);
            if (is_array($decoded)) {
                $events[] = $decoded;
            }
        }
        self::assertNotEmpty($events, 'The stream carried no data frames.');
        return $events;
    }

    private function rowCount(string $table): int
    {
        return (int)$this->getConnectionPool()
            ->getConnectionForTable($table)
            ->count('uid', $table, []);
    }
}
