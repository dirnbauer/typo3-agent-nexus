<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Http;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures\UcpStack;
use Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures\UnavailableCacheBackend;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutRecord;
use Webconsulting\AgentNexus\Ucp\Checkout\IdempotencyStore;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * The REST binding: headers, status codes, idempotency and what is stored.
 */
final class CheckoutEndpointTest extends UnitTestCase
{
    private const string BASE = '/api/agent-nexus/ucp/checkout-sessions';
    protected bool $resetSingletonInstances = true;

    private UcpStack $stack;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stack = new UcpStack();
    }

    #[Test]
    public function aCreatedSessionAnswers201WithItsLocationAndTheRequestId(): void
    {
        $headers = UcpStack::headers(UcpStack::uuid());

        $response = $this->stack->send('POST', self::BASE, $headers, $this->cart());

        self::assertSame(201, $response->getStatusCode());
        $body = UcpStack::json($response);
        $id = $body['id'];
        self::assertIsString($id);
        self::assertMatchesRegularExpression('/^chk_[0-9a-f]{24}$/', $id);
        self::assertSame(UcpStack::ORIGIN . self::BASE . '/' . $id, $response->getHeaderLine('Location'));
        self::assertSame($headers['Request-Id'], $response->getHeaderLine('Request-Id'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $stored = $this->stack->storage->find($id);
        self::assertNotNull($stored);
        self::assertSame(Spec::STATUS_INCOMPLETE, $stored->state);
        self::assertSame(Channel::Api->value, $stored->source);
        self::assertSame('€49.00 · 1 item', $stored->label);
        self::assertSame($body, CheckoutRecord::checkout($stored), 'The stored payload is the checkout exactly as returned.');
    }

    #[Test]
    public function withoutAUcpAgentHeaderTheRequestIsRejected(): void
    {
        $headers = UcpStack::headers(UcpStack::uuid());
        unset($headers['UCP-Agent']);

        $response = $this->stack->send('POST', self::BASE, $headers, $this->cart());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_profile_url', UcpStack::json($response)['code']);
        self::assertSame([], $this->stack->storage->objects);
    }

    #[Test]
    public function anotherUcpVersionIsUnsupported(): void
    {
        $headers = ['UCP-Agent' => 'profile="https://platform.example/.well-known/ucp";version="2099-01-01"'] + UcpStack::headers(UcpStack::uuid());

        $response = $this->stack->send('POST', self::BASE, $headers, $this->cart());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('version_unsupported', UcpStack::json($response)['code']);
    }

    #[Test]
    public function requestIdAndIdempotencyKeyMustBeUuids(): void
    {
        $noRequestId = UcpStack::headers(UcpStack::uuid());
        unset($noRequestId['Request-Id']);
        $badKey = UcpStack::headers('retry-1');
        $noKey = UcpStack::headers();

        foreach ([$noRequestId, $badKey, $noKey] as $headers) {
            $response = $this->stack->send('POST', self::BASE, $headers, $this->cart());
            self::assertSame(400, $response->getStatusCode());
            self::assertSame('invalid_request', UcpStack::json($response)['code']);
        }
        self::assertSame([], $this->stack->storage->objects);
    }

    #[Test]
    public function aBodyThatIsNotAJsonObjectIsRejected(): void
    {
        foreach (['', 'line_items', '[1, 2]', '{"line_items": [{"item": {"id": "pro-license"}, "quantity": 1.0}]}'] as $body) {
            $response = $this->stack->send('POST', self::BASE, UcpStack::headers(UcpStack::uuid()), $body);
            self::assertSame(400, $response->getStatusCode(), $body);
        }
    }

    #[Test]
    public function theSameKeyAndBodyReplayTheStoredResponse(): void
    {
        $headers = UcpStack::headers(UcpStack::uuid());
        $first = $this->stack->send('POST', self::BASE, $headers, $this->cart());
        $retry = $this->stack->send('POST', self::BASE, ['Request-Id' => UcpStack::uuid()] + $headers, $this->cart());

        self::assertSame(201, $retry->getStatusCode());
        self::assertSame((string)$first->getBody(), (string)$retry->getBody());
        self::assertSame($first->getHeaderLine('Location'), $retry->getHeaderLine('Location'));
        self::assertCount(1, $this->stack->storage->objects, 'A retry does not open a second session.');
    }

    #[Test]
    public function theSameKeyWithAnotherBodyIsAConflict(): void
    {
        $headers = UcpStack::headers(UcpStack::uuid());
        $this->stack->send('POST', self::BASE, $headers, $this->cart());

        $response = $this->stack->send('POST', self::BASE, $headers, $this->cart('support-pack'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('idempotency_conflict', UcpStack::json($response)['code']);
        self::assertCount(1, $this->stack->storage->objects);
    }

    #[Test]
    public function withoutIdempotencyStorageNothingIsChanged(): void
    {
        $stack = new UcpStack(cache: new VariableFrontend(IdempotencyStore::CACHE, new UnavailableCacheBackend()));

        $response = $stack->send('POST', self::BASE, UcpStack::headers(UcpStack::uuid()), $this->cart());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('idempotency_unavailable', UcpStack::json($response)['code']);
        self::assertSame('30', $response->getHeaderLine('Retry-After'));
        self::assertSame([], $stack->storage->objects);
    }

    #[Test]
    public function anUnknownSessionIsA404WithAnErrorResponse(): void
    {
        foreach (['chk_000000000000000000000000', 'not-an-id'] as $id) {
            $response = $this->stack->send('GET', self::BASE . '/' . $id, UcpStack::headers());

            self::assertSame(404, $response->getStatusCode());
            $body = UcpStack::json($response);
            self::assertSame(['version' => Spec::VERSION, 'status' => 'error'], $body['ucp']);
            self::assertSame('not_found', $body['messages'][0]['code'] ?? null);
        }
    }

    #[Test]
    public function aSessionGoesFromCreateToCompleted(): void
    {
        $id = $this->create();
        $object = $this->stack->storage->find($id);
        self::assertNotNull($object);
        $this->stack->storage->save($object->withPayload(CheckoutRecord::payload(CheckoutRecord::checkout($object), ['note' => 'kept'])));

        $updated = $this->stack->send('PUT', self::BASE . '/' . $id, UcpStack::headers(UcpStack::uuid()), json_encode([
            'line_items' => [['item' => ['id' => 'pro-license'], 'quantity' => 1]],
            'buyer' => ['email' => 'ada@example.org'],
        ], JSON_THROW_ON_ERROR));
        self::assertSame(200, $updated->getStatusCode());
        self::assertSame(Spec::STATUS_READY, UcpStack::json($updated)['status']);

        $completed = $this->stack->send('POST', self::BASE . '/' . $id . '/complete', UcpStack::headers(UcpStack::uuid()), json_encode([
            'payment' => ['instruments' => [$this->stack->payments->instrument('instr_1')]],
        ], JSON_THROW_ON_ERROR));
        $body = UcpStack::json($completed);
        self::assertSame(200, $completed->getStatusCode());
        self::assertSame(Spec::STATUS_COMPLETED, $body['status']);
        self::assertSame('ord_' . substr($id, 4), $body['order']['id'] ?? null);

        $fetched = $this->stack->send('GET', self::BASE . '/' . $id, UcpStack::headers());
        self::assertSame($body, UcpStack::json($fetched));

        $stored = $this->stack->storage->find($id);
        self::assertNotNull($stored);
        self::assertSame(
            [Spec::STATUS_INCOMPLETE, Spec::STATUS_READY, Spec::STATUS_IN_PROGRESS, Spec::STATUS_COMPLETED],
            array_column($stored->history, 'state'),
        );
        self::assertSame(['note' => 'kept'], CheckoutRecord::meta($stored), 'The private member survives every change.');
        self::assertArrayNotHasKey(CheckoutRecord::PRIVATE_KEY, UcpStack::json($fetched), 'and never leaves the server.');

        $again = $this->stack->send('POST', self::BASE . '/' . $id . '/cancel', UcpStack::headers(UcpStack::uuid()));
        self::assertSame(409, $again->getStatusCode());
        self::assertSame('checkout_not_modifiable', UcpStack::json($again)['messages'][0]['code'] ?? null);
    }

    #[Test]
    public function aSessionCanBeCanceledWithoutABody(): void
    {
        $id = $this->create();

        $response = $this->stack->send('POST', self::BASE . '/' . $id . '/cancel', UcpStack::headers(UcpStack::uuid()));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Spec::STATUS_CANCELED, UcpStack::json($response)['status']);
        self::assertSame(Spec::STATUS_CANCELED, $this->stack->storage->find($id)?->state);
    }

    #[Test]
    public function readsNeedNoIdempotencyKey(): void
    {
        $id = $this->create();

        self::assertSame(200, $this->stack->send('GET', self::BASE . '/' . $id, UcpStack::headers())->getStatusCode());
    }

    #[Test]
    public function theBusinessProfileIsServedWithPublicCachingAndAnETag(): void
    {
        $response = $this->stack->send('GET', '/.well-known/ucp');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
        $etag = $response->getHeaderLine('ETag');
        self::assertNotSame('', $etag);
        $profile = UcpStack::json($response);
        self::assertSame(UcpStack::ORIGIN . '/api/agent-nexus/ucp', $profile['ucp']['services'][Spec::SERVICE_SHOPPING][0]['endpoint'] ?? null);

        $revalidated = $this->stack->send('GET', '/.well-known/ucp', ['If-None-Match' => $etag]);
        self::assertSame(304, $revalidated->getStatusCode());
        self::assertSame('', (string)$revalidated->getBody());
    }

    private function create(): string
    {
        $response = $this->stack->send('POST', self::BASE, UcpStack::headers(UcpStack::uuid()), $this->cart());
        $id = UcpStack::json($response)['id'] ?? null;
        self::assertIsString($id);
        return $id;
    }

    private function cart(string $productId = 'pro-license'): string
    {
        return json_encode(['line_items' => [['item' => ['id' => $productId], 'quantity' => 1]]], JSON_THROW_ON_ERROR);
    }
}
