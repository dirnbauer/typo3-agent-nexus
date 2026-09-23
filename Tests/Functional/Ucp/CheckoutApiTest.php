<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Ucp;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * The UCP business over HTTP: discovery and the REST binding of the checkout
 * capability, through the frontend middleware stack.
 */
final class CheckoutApiTest extends UcpTestCase
{
    #[Test]
    public function theBusinessProfileIsPublishedAtTheWellKnownPath(): void
    {
        $response = $this->request('GET', self::HOST . '/.well-known/ucp');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $cacheControl = $response->getHeaderLine('Cache-Control');
        self::assertStringContainsString('public', $cacheControl);
        self::assertSame(1, preg_match('/max-age=(\d+)/', $cacheControl, $maxAge));
        self::assertGreaterThanOrEqual(60, (int)$maxAge[1]);
        self::assertDoesNotMatchRegularExpression('/private|no-store|no-cache/', $cacheControl);
        self::assertNotSame('', $response->getHeaderLine('ETag'));

        $profile = self::json($response);
        self::assertSame([], SchemaValidator::errors(self::wire($response), 'https://ucp.dev/schemas/profile.json#/$defs/business_schema'));
        self::assertSame(self::API, $profile['ucp']['services'][Spec::SERVICE_SHOPPING][0]['endpoint'] ?? null);
    }

    #[Test]
    public function theProfileRevalidatesWithItsETag(): void
    {
        $etag = $this->request('GET', self::HOST . '/.well-known/ucp')->getHeaderLine('ETag');

        $response = $this->request('GET', self::HOST . '/.well-known/ucp', ['If-None-Match' => $etag]);

        self::assertSame(304, $response->getStatusCode());
    }

    #[Test]
    public function theProfileIsAlsoServedUnderTheApiPath(): void
    {
        $wellKnown = self::json($this->request('GET', self::HOST . '/.well-known/ucp'));

        self::assertSame($wellKnown, self::json($this->request('GET', self::API . '/profile')));
        $platform = $this->request('GET', self::API . '/platform-profile');
        self::assertStringContainsString('"payment_handlers":{}', (string)$platform->getBody());
        self::assertSame([], SchemaValidator::errors(self::wire($platform), 'https://ucp.dev/schemas/profile.json#/$defs/platform_schema'));
    }

    #[Test]
    public function aSessionGoesFromCreateThroughUpdateToCompleted(): void
    {
        $created = $this->request('POST', self::API . '/checkout-sessions', self::headers(), $this->cart());
        self::assertSame(201, $created->getStatusCode());
        $id = self::json($created)['id'] ?? '';
        self::assertIsString($id);
        self::assertSame(self::API . '/checkout-sessions/' . $id, $created->getHeaderLine('Location'));
        self::assertSame(Spec::STATUS_INCOMPLETE, self::json($created)['status']);

        $updated = $this->request('PUT', self::API . '/checkout-sessions/' . $id, self::headers(), json_encode([
            'line_items' => [['item' => ['id' => 'pro-license'], 'quantity' => 1]],
            'buyer' => ['email' => 'ada@example.org'],
        ], JSON_THROW_ON_ERROR));
        self::assertSame(200, $updated->getStatusCode());
        self::assertSame(Spec::STATUS_READY, self::json($updated)['status']);

        $completed = $this->request('POST', self::API . '/checkout-sessions/' . $id . '/complete', self::headers(), json_encode([
            'payment' => ['instruments' => [['id' => 'instr_1', 'handler_id' => 'sandbox_pay', 'type' => 'sandbox', 'credential' => ['type' => 'token', 'token' => 'sandbox-success']]]],
        ], JSON_THROW_ON_ERROR));
        $body = self::json($completed);
        self::assertSame(200, $completed->getStatusCode());
        self::assertSame(Spec::STATUS_COMPLETED, $body['status']);
        self::assertSame([], SchemaValidator::errors(self::wire($completed), 'https://ucp.dev/schemas/shopping/checkout.json'));

        $fetched = $this->request('GET', self::API . '/checkout-sessions/' . $id, self::headers(false));
        self::assertSame($body, self::json($fetched));

        $stored = $this->get(ObjectStore::class)->find(ObjectKind::Checkout, $id);
        self::assertNotNull($stored);
        self::assertSame(Spec::STATUS_COMPLETED, $stored->state);
        self::assertSame('api', $stored->source);
        self::assertSame('€49.00 · 1 item', $stored->label);
        self::assertSame(
            [Spec::STATUS_INCOMPLETE, Spec::STATUS_READY, Spec::STATUS_IN_PROGRESS, Spec::STATUS_COMPLETED],
            array_column($stored->history, 'state'),
        );

        $traffic = array_values(array_filter($this->trafficRows(), static fn(array $row): bool => $row['correlation_id'] === $id));
        self::assertSame(['create_checkout', 'update_checkout', 'complete_checkout', 'get_checkout'], array_column($traffic, 'operation'));
        foreach ($traffic as $row) {
            self::assertSame('ucp', $row['protocol']);
            self::assertSame('api', $row['channel']);
        }
    }

    #[Test]
    public function aCanceledSessionCannotChangeAgain(): void
    {
        $id = self::json($this->request('POST', self::API . '/checkout-sessions', self::headers(), $this->cart()))['id'] ?? '';
        self::assertIsString($id);

        $canceled = $this->request('POST', self::API . '/checkout-sessions/' . $id . '/cancel', self::headers());
        $again = $this->request('POST', self::API . '/checkout-sessions/' . $id . '/cancel', self::headers());

        self::assertSame(200, $canceled->getStatusCode());
        self::assertSame(Spec::STATUS_CANCELED, self::json($canceled)['status']);
        self::assertSame(409, $again->getStatusCode());
        self::assertSame([], SchemaValidator::errors(self::wire($again), 'https://ucp.dev/schemas/common/types/error_response.json'));
    }

    #[Test]
    public function aRetryReplaysAndAChangedRetryConflicts(): void
    {
        $headers = self::headers();
        $first = $this->request('POST', self::API . '/checkout-sessions', $headers, $this->cart());
        $retry = $this->request('POST', self::API . '/checkout-sessions', ['Request-Id' => self::uuid()] + $headers, $this->cart());
        $changed = $this->request('POST', self::API . '/checkout-sessions', $headers, $this->cart('support-pack'));

        self::assertSame(201, $retry->getStatusCode());
        self::assertSame((string)$first->getBody(), (string)$retry->getBody());
        self::assertSame(409, $changed->getStatusCode());
        self::assertSame('idempotency_conflict', self::json($changed)['code']);
        self::assertSame(1, $this->getConnectionPool()->getConnectionForTable(ObjectStore::TABLE)->count('*', ObjectStore::TABLE, ['kind' => 'checkout']));
    }

    #[Test]
    public function withoutUcpAgentTheRequestIsRejected(): void
    {
        $headers = self::headers();
        unset($headers['UCP-Agent']);

        $response = $this->request('POST', self::API . '/checkout-sessions', $headers, $this->cart());

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_profile_url', self::json($response)['code']);
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable(ObjectStore::TABLE)->count('*', ObjectStore::TABLE, ['kind' => 'checkout']));
    }

    #[Test]
    public function anUnknownSessionIsNotFound(): void
    {
        $response = $this->request('GET', self::API . '/checkout-sessions/chk_000000000000000000000000', self::headers(false));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame([], SchemaValidator::errors(self::wire($response), 'https://ucp.dev/schemas/common/types/error_response.json'));
    }

    #[Test]
    public function theRequestIdIsEchoedAndProtocolHeadersAreExposed(): void
    {
        $headers = self::headers();

        $response = $this->request('POST', self::API . '/checkout-sessions', $headers, $this->cart());

        self::assertSame($headers['Request-Id'], $response->getHeaderLine('Request-Id'));
        self::assertStringContainsString('Request-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    private function cart(string $productId = 'pro-license'): string
    {
        return json_encode(['line_items' => [['item' => ['id' => $productId], 'quantity' => 1]]], JSON_THROW_ON_ERROR);
    }
}
