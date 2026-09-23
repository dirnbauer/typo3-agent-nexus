<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Checkout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures\FixedExtensionConfiguration;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutResult;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutService;
use Webconsulting\AgentNexus\Ucp\Checkout\CompletionGuard;
use Webconsulting\AgentNexus\Ucp\Checkout\InvalidRequest;
use Webconsulting\AgentNexus\Ucp\Checkout\Messages;
use Webconsulting\AgentNexus\Ucp\Payment\SandboxPaymentHandler;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * The checkout state machine and its arithmetic, without HTTP or storage.
 */
final class CheckoutServiceTest extends UnitTestCase
{
    private const string ID = 'chk_0123456789abcdef01234567';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = new \DateTimeImmutable('2026-09-23T10:00:00Z');
    }

    #[Test]
    public function aSessionWithoutAnEmailAddressIsIncompleteAndSaysWhatIsMissing(): void
    {
        $result = $this->service()->create(['line_items' => [$this->line('pro-license')]], self::ID, $this->now);

        self::assertSame(201, $result->status);
        self::assertSame(Spec::STATUS_INCOMPLETE, $result->body['status']);
        $missing = $this->messagesWith($result->body, 'field_required');
        self::assertCount(1, $missing);
        self::assertSame('$.buyer.email', $missing[0]['path']);
        self::assertSame(Messages::SEVERITY_RECOVERABLE, $missing[0]['severity']);
    }

    #[Test]
    public function anEmailAddressMakesTheSessionReadyForComplete(): void
    {
        $result = $this->service()->create(['line_items' => [$this->line('pro-license')], 'buyer' => ['email' => 'ada@example.org']], self::ID, $this->now);

        self::assertSame(Spec::STATUS_READY, $result->body['status']);
        self::assertSame([], $this->messagesOfType($result->body, 'error'));
        self::assertSame(['email' => 'ada@example.org'], $result->body['buyer']);
    }

    #[Test]
    public function pricesAndTitlesComeFromTheCatalogueNeverFromTheRequest(): void
    {
        $result = $this->service()->create([
            'line_items' => [['item' => ['id' => 'pro-license', 'title' => 'Free licence', 'price' => 1], 'quantity' => 2]],
            'totals' => [['type' => 'total', 'amount' => 1]],
            'status' => 'completed',
            'currency' => 'USD',
        ], self::ID, $this->now);

        $line = $this->lines($result->body)[0];
        self::assertSame(['id' => 'pro-license', 'title' => 'Desiderio Pro Licence', 'price' => 4900], $line['item']);
        self::assertSame(9800, CheckoutService::total($result->body));
        self::assertSame(Spec::STATUS_INCOMPLETE, $result->body['status']);
        self::assertSame('EUR', $result->body['currency']);
    }

    #[Test]
    public function totalsAddUpWithExactlyOneSubtotalAndOneTotal(): void
    {
        $result = $this->service()->create([
            'line_items' => [$this->line('agency-bundle', 2), $this->line('onboarding-addon')],
        ], self::ID, $this->now);

        $totals = $result->body['totals'];
        self::assertIsArray($totals);
        self::assertSame(['subtotal', 'total'], array_column($totals, 'type'));
        foreach ($this->lines($result->body) as $line) {
            $item = $line['item'];
            self::assertIsArray($item);
            $lineTotals = array_column($line['totals'], 'amount', 'type');
            self::assertSame($item['price'] * $line['quantity'], $lineTotals['total']);
        }
        $amounts = array_column($totals, 'amount', 'type');
        self::assertSame(2 * 14900 + 29900, $amounts['total']);
        // Every entry that is not the total adds up to the total.
        self::assertSame($amounts['total'], array_sum(array_diff_key($amounts, ['total' => true])));
        self::assertSame(2 * 14900 + 29900, array_sum(array_map(static fn(array $l): int => array_column($l['totals'], 'amount', 'type')['total'], $this->lines($result->body))));
    }

    #[Test]
    public function everyAmountIsAnInteger(): void
    {
        $body = $this->service()->create(['line_items' => [$this->line('support-pack', 3)]], self::ID, $this->now)->body;

        array_walk_recursive($body, static function (mixed $value, int|string $key): void {
            if ($key === 'amount' || $key === 'price') {
                self::assertIsInt($value);
            }
        });
    }

    #[Test]
    public function aSessionExpiresSixHoursAfterItWasCreated(): void
    {
        $result = $this->service()->create(['line_items' => [$this->line('pro-license')]], self::ID, $this->now);

        self::assertSame('2026-09-23T16:00:00Z', $result->body['expires_at']);
    }

    #[Test]
    public function anItemTheStoreDoesNotSellCreatesNoSession(): void
    {
        $result = $this->service()->create(['line_items' => [$this->line('pro-license'), $this->line('no-such-thing')]], self::ID, $this->now);

        self::assertSame(200, $result->status);
        self::assertNull($result->checkout);
        self::assertSame(['version' => Spec::VERSION, 'status' => 'error'], $result->body['ucp']);
        $errors = $this->messagesWith($result->body, 'item_unavailable');
        self::assertSame('$.line_items[1].item.id', $errors[0]['path']);
        self::assertSame(Messages::SEVERITY_UNRECOVERABLE, $errors[0]['severity']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedRequests(): array
    {
        return [
            'no line items' => [[]],
            'an empty cart' => [['line_items' => []]],
            'line items as an object' => [['line_items' => ['a' => ['item' => ['id' => 'pro-license'], 'quantity' => 1]]]],
            'no item id' => [['line_items' => [['item' => [], 'quantity' => 1]]]],
            'a quantity of zero' => [['line_items' => [['item' => ['id' => 'pro-license'], 'quantity' => 0]]]],
            'a quantity as text' => [['line_items' => [['item' => ['id' => 'pro-license'], 'quantity' => '1']]]],
            'a fractional quantity' => [['line_items' => [['item' => ['id' => 'pro-license'], 'quantity' => 1.5]]]],
            'a buyer that is a list' => [['line_items' => [['item' => ['id' => 'pro-license'], 'quantity' => 1]], 'buyer' => ['ada@example.org']]],
            'too many lines' => [['line_items' => array_fill(0, 21, ['item' => ['id' => 'pro-license'], 'quantity' => 1])]],
        ];
    }

    /**
     * @param array<string, mixed> $request
     */
    #[Test]
    #[DataProvider('malformedRequests')]
    public function aRequestThatIsNotACheckoutIsInvalid(array $request): void
    {
        $this->expectException(InvalidRequest::class);
        $this->service()->create($request, self::ID, $this->now);
    }

    #[Test]
    public function aQuantityAboveTheSandboxLimitIsABusinessError(): void
    {
        $result = $this->service()->create(['line_items' => [$this->line('pro-license', CheckoutService::MAX_QUANTITY + 1)]], self::ID, $this->now);

        self::assertNull($result->checkout);
        self::assertCount(1, $this->messagesWith($result->body, 'quantity_limit'));
    }

    #[Test]
    public function onlyThePieceAsSaleBasisIsAccepted(): void
    {
        $perPiece = $this->service()->create(['line_items' => [['item' => ['id' => 'pro-license', 'quantity_unit' => ['unit' => 'C62', 'display_text' => 'piece']], 'quantity' => 1]]], self::ID, $this->now);
        $perKilo = $this->service()->create(['line_items' => [['item' => ['id' => 'pro-license', 'quantity_unit' => ['unit' => 'KGM', 'display_text' => 'kg']], 'quantity' => 1]]], self::ID, $this->now);

        self::assertSame(201, $perPiece->status);
        self::assertNull($perKilo->checkout);
        self::assertCount(1, $this->messagesWith($perKilo->body, 'quantity_unit_mismatch'));
    }

    #[Test]
    public function anInvalidEmailAddressIsReportedAndNotKept(): void
    {
        $result = $this->service()->create(['line_items' => [$this->line('pro-license')], 'buyer' => ['email' => 'not an address', 'first_name' => 'Ada']], self::ID, $this->now);

        self::assertSame(Spec::STATUS_INCOMPLETE, $result->body['status']);
        self::assertSame(['first_name' => 'Ada'], $result->body['buyer']);
        self::assertCount(1, $this->messagesWith($result->body, 'field_invalid'));
        self::assertSame([], $this->messagesWith($result->body, 'field_required'), 'One message per problem.');
    }

    #[Test]
    public function anUpdateReplacesTheSessionAndKeepsTheIdsOfKnownLines(): void
    {
        $created = $this->create(['line_items' => [$this->line('pro-license')]]);
        $lineId = $this->lines($created)[0]['id'];

        $result = $this->service()->update($created, [
            'line_items' => [['id' => $lineId, 'item' => ['id' => 'pro-license'], 'quantity' => 3], $this->line('support-pack')],
            'buyer' => ['email' => 'ada@example.org'],
        ], $this->now);

        self::assertSame(200, $result->status);
        self::assertNotNull($result->checkout);
        $lines = $this->lines($result->body);
        self::assertSame($lineId, $lines[0]['id']);
        self::assertNotSame($lineId, $lines[1]['id']);
        self::assertSame(3 * 4900 + 9900, CheckoutService::total($result->body));
        self::assertSame(Spec::STATUS_READY, $result->body['status']);
        self::assertSame([['state' => Spec::STATUS_READY, 'note' => 'Buyer details complete.']], $result->transitions);
        self::assertSame($created['expires_at'], $result->body['expires_at'], 'An update does not extend the session.');
    }

    #[Test]
    public function anUpdateThatLeavesOutTheBuyerRemovesIt(): void
    {
        $created = $this->create(['line_items' => [$this->line('pro-license')], 'buyer' => ['email' => 'ada@example.org']]);

        $result = $this->service()->update($created, ['line_items' => [$this->line('pro-license')]], $this->now);

        self::assertArrayNotHasKey('buyer', $result->body);
        self::assertSame(Spec::STATUS_INCOMPLETE, $result->body['status']);
    }

    #[Test]
    public function anUpdateWithAnItemTheStoreDoesNotSellChangesNothing(): void
    {
        $created = $this->create(['line_items' => [$this->line('pro-license')]]);

        $result = $this->service()->update($created, ['line_items' => [$this->line('no-such-thing')]], $this->now);

        self::assertSame(200, $result->status);
        self::assertNull($result->checkout);
        self::assertSame($created['line_items'], $result->body['line_items']);
        self::assertSame(Messages::SEVERITY_RECOVERABLE, $this->messagesWith($result->body, 'item_unavailable')[0]['severity']);
    }

    #[Test]
    public function completingAnIncompleteSessionReturnsItWithWhatIsMissing(): void
    {
        $created = $this->create(['line_items' => [$this->line('pro-license')]]);

        $result = $this->complete($created);

        self::assertSame(200, $result->status);
        self::assertNull($result->checkout);
        self::assertSame(Spec::STATUS_INCOMPLETE, $result->body['status']);
        self::assertCount(1, $this->messagesWith($result->body, 'field_required'));
    }

    #[Test]
    public function theSuccessTokenCompletesTheSessionAndPlacesAnOrder(): void
    {
        $created = $this->create(['line_items' => [$this->line('pro-license')], 'buyer' => ['email' => 'ada@example.org']]);

        $result = $this->complete($created);

        self::assertSame(200, $result->status);
        self::assertSame(Spec::STATUS_COMPLETED, $result->body['status']);
        self::assertSame(['id' => 'ord_1', 'permalink_url' => 'https://shop.example/checkout', 'label' => 'Sandbox order ORD_1'], $result->body['order']);
        self::assertSame([Spec::STATUS_IN_PROGRESS, Spec::STATUS_COMPLETED], array_column($result->transitions, 'state'));
        self::assertArrayNotHasKey('continue_url', $result->body);
        $payment = $result->body['payment'];
        self::assertIsArray($payment);
        self::assertSame([['id' => 'instr_1', 'handler_id' => 'sandbox_pay', 'type' => 'sandbox', 'selected' => true]], $payment['instruments'], 'The credential never comes back.');
        self::assertStringNotContainsString(SandboxPaymentHandler::TOKEN_SUCCESS, json_encode($result->body, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function theDeclineTokenFailsWithPaymentFailedAndChangesNothing(): void
    {
        $created = $this->create(['line_items' => [$this->line('pro-license')], 'buyer' => ['email' => 'ada@example.org']]);

        $result = $this->complete($created, decline: true);

        self::assertNull($result->checkout);
        self::assertSame(Spec::STATUS_READY, $result->body['status']);
        self::assertCount(1, $this->messagesWith($result->body, 'payment_failed'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusablePayments(): array
    {
        $instrument = ['id' => 'instr_1', 'handler_id' => 'sandbox_pay', 'type' => 'sandbox', 'credential' => ['type' => 'token', 'token' => 'sandbox-success']];
        return [
            'two instruments' => [['payment' => ['instruments' => [$instrument, $instrument]]]],
            'no instrument' => [['payment' => ['instruments' => []]]],
            'another handler' => [['payment' => ['instruments' => [['handler_id' => 'com.example.cards'] + $instrument]]]],
            'another instrument type' => [['payment' => ['instruments' => [['type' => 'card'] + $instrument]]]],
            'no credential' => [['payment' => ['instruments' => [array_diff_key($instrument, ['credential' => true])]]]],
            'an unknown token' => [['payment' => ['instruments' => [['credential' => ['type' => 'token', 'token' => 'tok_visa']] + $instrument]]]],
        ];
    }

    /**
     * @param array<string, mixed> $request
     */
    #[Test]
    #[DataProvider('unusablePayments')]
    public function anUnusablePaymentFailsWithPaymentFailed(array $request): void
    {
        $created = $this->create(['line_items' => [$this->line('pro-license')], 'buyer' => ['email' => 'ada@example.org']]);

        $result = $this->service()->complete($created, $request, $this->now, 'ord_1', 'https://shop.example/checkout');

        self::assertNull($result->checkout);
        self::assertCount(1, $this->messagesWith($result->body, 'payment_failed'));
    }

    #[Test]
    public function completingWithoutAPaymentAsksForOne(): void
    {
        $created = $this->create(['line_items' => [$this->line('pro-license')], 'buyer' => ['email' => 'ada@example.org']]);

        $result = $this->service()->complete($created, [], $this->now, 'ord_1', 'https://shop.example/checkout');

        self::assertSame('$.payment', $this->messagesWith($result->body, 'field_required')[0]['path']);
    }

    #[Test]
    public function aCompletionGuardCanRefuseBeforeAnythingIsCharged(): void
    {
        $guard = new class implements CompletionGuard {
            /** @var list<array<string, mixed>> */
            public array $seen = [];

            public function check(array $checkout, array $request): ?array
            {
                $this->seen[] = $request;
                return isset($request['ap2']) ? null : Messages::error('mandate_required', 'An AP2 checkout mandate is required.');
            }
        };
        $service = $this->service([$guard]);
        $created = $service->create(['line_items' => [$this->line('pro-license')], 'buyer' => ['email' => 'ada@example.org']], self::ID, $this->now)->body;

        $refused = $service->complete($created, $this->payment(), $this->now, 'ord_1', 'https://shop.example/checkout');
        $allowed = $service->complete($created, $this->payment() + ['ap2' => ['checkout_mandate' => 'x']], $this->now, 'ord_1', 'https://shop.example/checkout');

        self::assertNull($refused->checkout);
        self::assertCount(1, $this->messagesWith($refused->body, 'mandate_required'));
        self::assertSame(Spec::STATUS_COMPLETED, $allowed->body['status']);
        self::assertCount(2, $guard->seen);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function finalStatuses(): array
    {
        return ['completed' => [Spec::STATUS_COMPLETED], 'canceled' => [Spec::STATUS_CANCELED]];
    }

    #[Test]
    #[DataProvider('finalStatuses')]
    public function aFinishedSessionRefusesEveryChangeWithAConflict(string $status): void
    {
        $created = $this->create(['line_items' => [$this->line('pro-license')], 'buyer' => ['email' => 'ada@example.org']]);
        $finished = $status === Spec::STATUS_COMPLETED ? $this->complete($created)->body : $this->service()->cancel($created)->body;

        foreach ([
            $this->service()->update($finished, ['line_items' => [$this->line('pro-license')]], $this->now),
            $this->complete($finished),
            $this->service()->cancel($finished),
        ] as $result) {
            self::assertSame(409, $result->status);
            self::assertNull($result->checkout);
            self::assertSame('error', $result->body['ucp']['status'] ?? null);
            self::assertCount(1, $this->messagesWith($result->body, 'checkout_not_modifiable'));
        }
    }

    #[Test]
    public function aSessionCanBeCanceledBeforeItIsCompleted(): void
    {
        $incomplete = $this->create(['line_items' => [$this->line('pro-license')]]);
        $ready = $this->create(['line_items' => [$this->line('pro-license')], 'buyer' => ['email' => 'ada@example.org']]);

        foreach ([$incomplete, $ready] as $checkout) {
            $result = $this->service()->cancel($checkout);
            self::assertSame(Spec::STATUS_CANCELED, $result->body['status']);
            self::assertSame([['state' => Spec::STATUS_CANCELED, 'note' => 'Canceled by the platform.']], $result->transitions);
        }
    }

    #[Test]
    public function anExpiredSessionCanNoLongerChangeButCanBeCanceled(): void
    {
        $created = $this->create(['line_items' => [$this->line('pro-license')], 'buyer' => ['email' => 'ada@example.org']]);
        $later = $this->now->add(new \DateInterval('PT7H'));

        $completed = $this->service()->complete($created, $this->payment(), $later, 'ord_1', 'https://shop.example/checkout');

        self::assertSame(409, $completed->status);
        self::assertCount(1, $this->messagesWith($completed->body, 'checkout_expired'));
        self::assertSame(Spec::STATUS_CANCELED, $this->service()->cancel($created)->body['status']);
    }

    #[Test]
    public function theLabelNamesTheTotalAndTheNumberOfItems(): void
    {
        self::assertSame('€49.00 · 1 item', CheckoutService::label($this->create(['line_items' => [$this->line('pro-license')]])));
        self::assertSame('€597.00 · 3 items', CheckoutService::label($this->create(['line_items' => [$this->line('agency-bundle', 2), $this->line('onboarding-addon')]])));
    }

    #[Test]
    public function theResponseEnvelopeNamesTheCapabilityAndTheSandboxHandler(): void
    {
        $ucp = $this->create(['line_items' => [$this->line('pro-license')]])['ucp'];

        self::assertIsArray($ucp);
        self::assertSame(Spec::VERSION, $ucp['version']);
        self::assertSame('success', $ucp['status']);
        self::assertSame([Spec::CAPABILITY_CHECKOUT => [['version' => Spec::VERSION]]], $ucp['capabilities']);
        self::assertArrayHasKey(SandboxPaymentHandler::NAME, $ucp['payment_handlers']);
    }

    #[Test]
    public function linksComeFromTheExtensionConfigurationOnlyWhenTheyAreUrls(): void
    {
        $service = new CheckoutService(new Merchant(), new SandboxPaymentHandler(), new ExtensionSettings(new FixedExtensionConfiguration([
            'ucpTermsOfServiceUrl' => 'https://shop.example/terms',
            'ucpPrivacyPolicyUrl' => 'javascript:alert(1)',
        ])));

        $links = $service->create(['line_items' => [$this->line('pro-license')]], self::ID, $this->now)->body['links'];

        self::assertSame([['type' => 'terms_of_service', 'url' => 'https://shop.example/terms', 'title' => 'Terms of service']], $links);
        self::assertSame([], $this->create(['line_items' => [$this->line('pro-license')]])['links']);
    }

    /**
     * @param list<CompletionGuard> $guards
     */
    private function service(array $guards = []): CheckoutService
    {
        return new CheckoutService(new Merchant(), new SandboxPaymentHandler(), new ExtensionSettings(new FixedExtensionConfiguration()), $guards);
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function create(array $request): array
    {
        $result = $this->service()->create($request, self::ID, $this->now);
        self::assertNotNull($result->checkout);
        return $result->checkout;
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function complete(array $checkout, bool $decline = false): CheckoutResult
    {
        return $this->service()->complete($checkout, $this->payment($decline), $this->now, 'ord_1', 'https://shop.example/checkout');
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(bool $decline = false): array
    {
        return ['payment' => ['instruments' => [(new SandboxPaymentHandler())->instrument('instr_1', $decline)]]];
    }

    /**
     * @return array{item: array{id: string}, quantity: int}
     */
    private function line(string $productId, int $quantity = 1): array
    {
        return ['item' => ['id' => $productId], 'quantity' => $quantity];
    }

    /**
     * @param array<string, mixed> $checkout
     * @return list<array<string, mixed>>
     */
    private function lines(array $checkout): array
    {
        $lines = $checkout['line_items'] ?? null;
        self::assertIsArray($lines);
        return array_values(array_filter($lines, is_array(...)));
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private function messagesOfType(array $body, string $type): array
    {
        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        return array_values(array_filter($messages, static fn(mixed $m): bool => is_array($m) && ($m['type'] ?? null) === $type));
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private function messagesWith(array $body, string $code): array
    {
        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
        return array_values(array_filter($messages, static fn(mixed $m): bool => is_array($m) && ($m['code'] ?? null) === $code));
    }
}
