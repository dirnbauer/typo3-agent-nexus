<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Ap2;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Http\Ap2Endpoint;
use Webconsulting\AgentNexus\Ap2\Sandbox\KeyRing;
use Webconsulting\AgentNexus\Ap2\Sandbox\MandateRecorder;
use Webconsulting\AgentNexus\Ap2\Sandbox\Role;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

/**
 * The two public AP2 routes, driven through the real frontend middleware
 * stack the way the Trusted Surface widget and curl call them.
 */
final class AuthorizeEndpointTest extends AbstractAgentNexusTestCase
{
    private const string API = 'https://agent-nexus.test/api/agent-nexus/ap2/';
    private const string UCP_JWK = 'https://ucp.dev/schemas/profile.json#/$defs/jwk_public_key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRootPage();
        $this->writeTestSite();
        $cacheManager = $this->get(CacheManager::class);
        $cacheManager->getCache('agentnexus')->flush();
        $cacheManager->getCache(RateLimiter::CACHE)->flush();
    }

    #[Test]
    public function theJwksPublishesThePublicKeyOfEveryRole(): void
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest(self::API . 'jwks.json'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
        $body = $this->json($response);
        self::assertSame($this->get(KeyRing::class)->jwks(), $body);
        $keys = $body['keys'];
        self::assertCount(count(Role::cases()), $keys);
        foreach ($keys as $jwk) {
            self::assertSame([], SchemaValidator::errors($jwk, self::UCP_JWK));
            self::assertArrayNotHasKey('d', $jwk, 'Never a private key.');
        }
    }

    #[Test]
    public function withinTheCapThePurchaseIsAuthorisedAndEveryObjectIsRecorded(): void
    {
        $response = $this->post(['capCents' => 50000, 'intent' => 'within']);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertTrue($body['simulated']);
        self::assertTrue($body['authorised']);
        self::assertSame('authorised', $body['outcome']);
        self::assertNull($body['error']);
        self::assertNull($body['fallback']);
        self::assertSame(44700, Json::map($body['cart'])['total'] ?? null);
        self::assertSame(['done', 'done', 'done', 'passed', 'passed', 'passed'], array_column(Json::objects($body['steps']), 'status'));

        $artefacts = Json::map($body['artefacts']);
        $receipts = Json::map($body['receipts']);
        self::assertSame('Success', $this->claims($receipts, 'checkout')['status'] ?? null);
        self::assertSame('Success', $this->claims($receipts, 'payment')['status'] ?? null);
        self::assertSame(Json::map($artefacts['closedCheckoutMandate'] ?? null)['reference'] ?? null, $this->claims($receipts, 'checkout')['reference'] ?? null, 'The checkout receipt names the closed checkout mandate.');
        self::assertSame(Json::map($artefacts['closedPaymentMandate'] ?? null)['reference'] ?? null, $this->claims($receipts, 'payment')['reference'] ?? null, 'The payment receipt names the closed payment mandate.');

        $chainId = Json::string($body['chainId']);
        self::assertSame(Json::map($artefacts['openCheckoutMandate'] ?? null)['reference'] ?? null, $chainId, 'The chain is named after the open checkout mandate.');
        $objects = $this->mandates($chainId);
        self::assertCount(7, $objects, 'Five tokens and two receipts.');
        foreach ($objects as $object) {
            self::assertSame('api', $object->source);
            self::assertSame(0, $object->pid);
        }
        self::assertSame(MandateRecorder::STATE_VERIFIED, $this->object($artefacts, 'closedCheckoutMandate')->state);
        self::assertSame(MandateRecorder::STATE_VERIFIED, $this->object($artefacts, 'closedPaymentMandate')->state);
        self::assertSame(MandateRecorder::STATE_VERIFIED, $this->object($artefacts, 'openPaymentMandate')->state, 'Used once, within its cap.');

        $http = $this->traffic($chainId);
        self::assertCount(1, $http);
        self::assertSame('ap2', $http[0]['protocol']);
        self::assertSame('api', $http[0]['channel']);
        self::assertSame(200, (int)$http[0]['status_code']);
        self::assertSame(['PresentPaymentMandate', 'SettlePayment'], array_column($this->traffic(Json::string(Json::map($artefacts['closedPaymentMandate'] ?? null)['reference'] ?? null)), 'operation'));
        self::assertSame(['PresentCheckoutMandate'], array_column($this->traffic(Json::string(Json::map($artefacts['closedCheckoutMandate'] ?? null)['reference'] ?? null)), 'operation'));
    }

    #[Test]
    public function everyTokenInTheAnswerVerifiesWithThePublishedKeys(): void
    {
        $body = $this->json($this->post(['capCents' => 50000]));
        $jwks = $this->json($this->executeFrontendSubRequest(new InternalRequest(self::API . 'jwks.json')));
        $keys = [];
        foreach (Json::objects($jwks['keys'] ?? null) as $jwk) {
            $keys[Json::string($jwk['kid'] ?? null)] = EcKey::fromJwk($jwk);
        }

        foreach ([...array_values(Json::map($body['artefacts'])), ...array_values(Json::map($body['receipts']))] as $artefact) {
            $artefact = Json::map($artefact);
            $issuerJwt = explode('~', Json::string($artefact['token'] ?? null))[0];
            $kid = Jws::decode($issuerJwt)->kid();
            if ($kid === '') {
                self::assertSame('shopping-agent', $artefact['role'] ?? null, 'Only the agent signs without a kid: its key is in cnf.jwk.');
                continue;
            }
            self::assertArrayHasKey($kid, $keys, 'The signer is in the JWKS.');
            self::assertNotSame([], Jws::verify($issuerJwt, $keys[$kid]), Json::string($artefact['title'] ?? null));
        }
    }

    #[Test]
    public function overTheCapTheCredentialProviderRefusesAndThePersonIsAsked(): void
    {
        $body = $this->json($this->post(['capCents' => 50000, 'intent' => 'over']));

        self::assertFalse($body['authorised']);
        self::assertSame('refused', $body['outcome']);
        self::assertSame('unresolved_constraint', $body['error']);
        self::assertSame(54700, Json::map($body['cart'])['total'] ?? null);
        self::assertSame(['mode' => 'human_present', 'error' => 'unresolved_constraint'], array_intersect_key(Json::map($body['fallback']), ['mode' => 1, 'error' => 1]));
        self::assertSame(['done', 'done', 'done', 'failed', 'skipped', 'skipped'], array_column(Json::objects($body['steps']), 'status'));

        $receipts = Json::map($body['receipts']);
        self::assertNull($receipts['checkout']);
        $payment = $this->claims($receipts, 'payment');
        self::assertSame('Error', $payment['status'] ?? null);
        self::assertSame('unresolved_constraint', $payment['error'] ?? null);
        self::assertSame('credential-provider', Json::map($receipts['payment'])['role'] ?? null);

        $checks = Json::objects(Json::map(Json::map($body['verifications'])['credentialProvider'] ?? null)['checks'] ?? null);
        $failed = array_values(array_filter($checks, static fn(array $check): bool => $check['pass'] === false));
        self::assertSame(['spending_cap'], array_column($failed, 'id'));

        $chainId = Json::string($body['chainId']);
        self::assertCount(6, $this->mandates($chainId), 'Five tokens and the refusal.');
        $artefacts = Json::map($body['artefacts']);
        self::assertSame(MandateRecorder::STATE_REJECTED, $this->object($artefacts, 'closedPaymentMandate')->state);
        self::assertSame(MandateRecorder::STATE_ISSUED, $this->object($artefacts, 'closedCheckoutMandate')->state, 'The merchant never saw it.');
        $exchanges = $this->traffic(Json::string(Json::map($artefacts['closedPaymentMandate'] ?? null)['reference'] ?? null));
        self::assertSame(['PresentPaymentMandate'], array_column($exchanges, 'operation'));
        self::assertSame(422, (int)$exchanges[0]['status_code']);
    }

    #[Test]
    public function aMerchantThePersonDidNotApproveIsRefused(): void
    {
        $body = $this->json($this->post(['capCents' => 50000, 'merchantId' => 'other-shop']));

        self::assertFalse($body['authorised']);
        $checks = Json::objects(Json::map(Json::map($body['verifications'])['credentialProvider'] ?? null)['checks'] ?? null);
        self::assertContains('allowed_payee', array_column(array_filter($checks, static fn(array $check): bool => $check['pass'] === false), 'id'));
    }

    #[Test]
    public function aWidgetRequestIsRecordedAsTheWidgetsOnItsPage(): void
    {
        $body = $this->json($this->post(['capCents' => '50000', 'agentNexus' => ['ce' => 10, 'page' => 1, 'url' => 'https://agent-nexus.test/']]));

        self::assertTrue($body['authorised'], 'A cap in a digit string is accepted.');
        self::assertNull($body['explanation'], 'No model is configured.');
        $chainId = Json::string($body['chainId']);
        foreach ($this->mandates($chainId) as $object) {
            self::assertSame('widget', $object->source);
            self::assertSame(1, $object->pid);
        }
        self::assertSame('widget', $this->traffic($chainId)[0]['channel']);
    }

    #[Test]
    public function badRequestsAreRefusedWithAReadableError(): void
    {
        foreach ([
            [['capCents' => 0], 422, 'capCents'],
            [['capCents' => -100], 422, 'capCents'],
            [['capCents' => 12.5], 422, 'capCents'],
            [['capCents' => 'fifty'], 422, 'capCents'],
            [['capCents' => Ap2Endpoint::MAX_CAP + 1], 422, 'capCents'],
            [['capCents' => 50000, 'intent' => 'maybe'], 422, 'intent'],
            [['capCents' => 50000, 'merchantId' => 'nowhere'], 422, 'merchantId'],
            ['{not json', 400, 'JSON object'],
            ['["a list"]', 400, 'JSON object'],
            [str_repeat(' ', 17000) . '{}', 400, 'JSON object'],
        ] as [$request, $status, $mentions]) {
            $response = $this->post($request);
            self::assertSame($status, $response->getStatusCode(), (string)json_encode($request));
            $body = $this->json($response);
            self::assertTrue($body['simulated']);
            self::assertStringContainsString($mentions, Json::string(Json::map($body['error'])['message'] ?? null));
        }
        self::assertSame(0, $this->get(ObjectStore::class)->count(new ObjectFilter(ObjectKind::Mandate)), 'Nothing is signed for a bad request.');
    }

    #[Test]
    public function aClientOverTheLimitIsTurnedAway(): void
    {
        for ($i = 0; $i < Ap2Endpoint::RATE_LIMIT; $i++) {
            self::assertSame(422, $this->post(['capCents' => 0])->getStatusCode(), 'Request ' . ($i + 1));
        }

        $response = $this->post(['capCents' => 50000]);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame((string)Ap2Endpoint::RATE_WINDOW, $response->getHeaderLine('Retry-After'));
        self::assertSame(429, Json::map($this->json($response)['error'] ?? null)['code'] ?? null);
    }

    /**
     * @param array<string, mixed>|string $body
     */
    private function post(array|string $body): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(is_string($body) ? $body : (string)json_encode($body));
        $stream->rewind();
        return $this->executeFrontendSubRequest(
            (new InternalRequest(self::API . 'authorize'))
                ->withMethod('POST')
                ->withBody($stream)
                ->withHeader('Content-Type', 'application/json'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        return Json::map($data);
    }

    /**
     * @param array<string, mixed> $receipts
     * @return array<string, mixed>
     */
    private function claims(array $receipts, string $kind): array
    {
        return Json::map(Json::map($receipts[$kind] ?? null)['claims'] ?? null);
    }

    /**
     * @param array<string, mixed> $artefacts
     */
    private function object(array $artefacts, string $key): ProtocolObject
    {
        $reference = Json::string(Json::map($artefacts[$key] ?? null)['reference'] ?? null);
        $object = $this->get(ObjectStore::class)->find(ObjectKind::Mandate, $reference);
        self::assertNotNull($object, 'No mandate object for ' . $key);
        return $object;
    }

    /**
     * @return list<ProtocolObject>
     */
    private function mandates(string $chainId): array
    {
        return $this->get(ObjectStore::class)->list(new ObjectFilter(ObjectKind::Mandate, contextId: $chainId));
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
}
