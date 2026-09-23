<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Ucp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;
use Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures\FixedExtensionConfiguration;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutService;
use Webconsulting\AgentNexus\Ucp\Payment\SandboxPaymentHandler;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * Every checkout the business returns — in every state it can reach — and
 * every error response against UCP 2026-08-25's schemas.
 */
final class CheckoutConformanceTest extends ConformanceTestCase
{
    private const string CHECKOUT = 'https://ucp.dev/schemas/shopping/checkout.json';
    private const string ERROR_RESPONSE = 'https://ucp.dev/schemas/common/types/error_response.json';
    private const string MESSAGE = 'https://ucp.dev/schemas/common/types/message.json';
    private const string TOTALS = 'https://ucp.dev/schemas/common/types/totals.json';

    /**
     * @return array<string, array{0: string}>
     */
    public static function reachableStates(): array
    {
        return [
            'incomplete' => ['incomplete'],
            'ready for complete' => ['ready'],
            'completed' => ['completed'],
            'canceled' => ['canceled'],
            'an update the store rejected' => ['rejectedUpdate'],
            'a declined payment' => ['declinedPayment'],
            'two lines, one of them twice' => ['severalLines'],
        ];
    }

    #[Test]
    #[DataProvider('reachableStates')]
    public function everyCheckoutTheBusinessReturnsConforms(string $state): void
    {
        $checkout = $this->checkout($state);

        self::assertConformsTo(self::CHECKOUT, $checkout);
        self::assertConformsTo(self::TOTALS, $checkout['totals']);
        foreach (is_array($checkout['messages'] ?? null) ? $checkout['messages'] : [] as $message) {
            self::assertConformsTo(self::MESSAGE, $message);
        }
        $this->assertTotalsAddUp($checkout);
        self::assertSame('success', $checkout['ucp']['status'] ?? null, 'A response carrying a checkout is not an error response.');
    }

    #[Test]
    public function statusMatchesWhatTheSessionContains(): void
    {
        self::assertSame(Spec::STATUS_INCOMPLETE, $this->checkout('incomplete')['status']);
        self::assertSame(Spec::STATUS_READY, $this->checkout('ready')['status']);
        self::assertSame(Spec::STATUS_COMPLETED, $this->checkout('completed')['status']);
        self::assertArrayHasKey('order', $this->checkout('completed'));
        self::assertArrayNotHasKey('continue_url', $this->checkout('completed'));
        self::assertSame(Spec::STATUS_CANCELED, $this->checkout('canceled')['status']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function failures(): array
    {
        return [
            'an item the store does not sell' => ['unknownItem'],
            'a finished session' => ['notModifiable'],
            'an expired session' => ['expired'],
        ];
    }

    #[Test]
    #[DataProvider('failures')]
    public function everyErrorResponseConforms(string $failure): void
    {
        $service = $this->service();
        $now = new \DateTimeImmutable('2026-09-23T10:00:00Z');
        $result = match ($failure) {
            'unknownItem' => $service->create(['line_items' => [['item' => ['id' => 'no-such-thing'], 'quantity' => 1]]], 'chk_1', $now),
            'notModifiable' => $service->cancel($this->checkout('completed')),
            default => $service->complete($this->checkout('ready'), [], $now->add(new \DateInterval('P1D')), 'ord_1', 'https://shop.example/o'),
        };

        self::assertNull($result->checkout);
        self::assertConformsTo(self::ERROR_RESPONSE, $result->body);
    }

    #[Test]
    public function aCheckoutWithoutLinksIsRejected(): void
    {
        $checkout = $this->checkout('ready');
        unset($checkout['links']);

        self::assertViolates(self::CHECKOUT, $checkout, 'links is required, even when it is empty.');
    }

    #[Test]
    public function anAmountInMajorUnitsIsRejected(): void
    {
        $fractional = $this->checkout('ready');
        $fractional['totals'][1]['amount'] = 49.5;
        $text = $this->checkout('ready');
        $text['line_items'][0]['item']['price'] = '49.00';

        self::assertViolates(self::CHECKOUT, $fractional, 'Amounts are integers in minor units.');
        self::assertViolates(self::CHECKOUT, $text, 'A price is a number, not a formatted string.');
    }

    #[Test]
    public function aSecondSubtotalIsRejected(): void
    {
        $checkout = $this->checkout('ready');
        $checkout['totals'][] = ['type' => 'subtotal', 'amount' => 0];

        self::assertViolates(self::CHECKOUT, $checkout, 'Exactly one subtotal.');
    }

    #[Test]
    public function aPositiveDiscountIsRejected(): void
    {
        $checkout = $this->checkout('ready');
        array_splice($checkout['totals'], 1, 0, [['type' => 'discount', 'amount' => 500]]);

        self::assertViolates(self::CHECKOUT, $checkout, 'Discounts are negative.');
    }

    #[Test]
    public function anErrorResponseWithACheckoutInItIsRejected(): void
    {
        $body = $this->service()->create(['line_items' => [['item' => ['id' => 'no-such-thing'], 'quantity' => 1]]], 'chk_1', new \DateTimeImmutable())->body;
        $body['id'] = 'chk_1';

        self::assertViolates(self::ERROR_RESPONSE, $body, 'error_response is a closed object.');
    }

    #[Test]
    public function anErrorMessageWithoutSeverityIsRejected(): void
    {
        self::assertViolates(self::MESSAGE, ['type' => 'error', 'code' => 'payment_failed', 'content' => 'No.']);
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function assertTotalsAddUp(array $checkout): void
    {
        $totals = is_array($checkout['totals'] ?? null) ? $checkout['totals'] : [];
        $sum = 0;
        $total = null;
        foreach ($totals as $entry) {
            self::assertIsArray($entry);
            self::assertIsInt($entry['amount']);
            if ($entry['type'] === 'total') {
                $total = $entry['amount'];
            } elseif ($entry['type'] !== 'items_discount') {
                $sum += $entry['amount'];
            }
        }
        self::assertSame($total, $sum, 'Every entry that is not the total adds up to the total.');
    }

    /**
     * @return array<string, mixed>
     */
    private function checkout(string $state): array
    {
        $service = $this->service();
        $now = new \DateTimeImmutable('2026-09-23T10:00:00Z');
        $payment = ['payment' => ['instruments' => [(new SandboxPaymentHandler())->instrument('instr_1')]]];
        $declined = ['payment' => ['instruments' => [(new SandboxPaymentHandler())->instrument('instr_1', true)]]];
        $line = ['item' => ['id' => 'pro-license'], 'quantity' => 1];
        $incomplete = $service->create(['line_items' => [$line]], 'chk_0123456789abcdef01234567', $now)->body;
        $ready = $service->create(['line_items' => [$line], 'buyer' => ['email' => 'ada@example.org', 'first_name' => 'Ada']], 'chk_0123456789abcdef01234567', $now)->body;

        return match ($state) {
            'incomplete' => $incomplete,
            'ready' => $ready,
            'completed' => $service->complete($ready, $payment, $now, 'ord_0123456789abcdef01234567', 'https://shop.example/api/agent-nexus/ucp/checkout-sessions/chk_0123456789abcdef01234567')->body,
            'canceled' => $service->cancel($incomplete)->body,
            'rejectedUpdate' => $service->update($ready, ['line_items' => [['item' => ['id' => 'no-such-thing'], 'quantity' => 1]]], $now)->body,
            'declinedPayment' => $service->complete($ready, $declined, $now, 'ord_1', 'https://shop.example/o')->body,
            default => $service->create(['line_items' => [['item' => ['id' => 'agency-bundle'], 'quantity' => 2], ['item' => ['id' => 'onboarding-addon'], 'quantity' => 1]]], 'chk_0123456789abcdef01234567', $now)->body,
        };
    }

    private function service(): CheckoutService
    {
        return new CheckoutService(new Merchant(), new SandboxPaymentHandler(), new ExtensionSettings(new FixedExtensionConfiguration([
            'ucpTermsOfServiceUrl' => 'https://shop.example/terms',
        ])));
    }
}
