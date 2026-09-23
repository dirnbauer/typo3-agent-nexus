<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Ucp\Payment\SandboxPaymentHandler;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * The checkout capability (dev.ucp.shopping.checkout) as a state machine over
 * plain checkout JSON.
 *
 *     incomplete ⇄ ready_for_complete → complete_in_progress → completed
 *     any state that is not final → canceled
 *
 * The store sells digital goods, so there is no fulfilment: a session is
 * `incomplete` until it knows the buyer's email address, then
 * `ready_for_complete`. It never escalates to `requires_escalation` — there
 * is no page a buyer could continue on — and it completes synchronously, so
 * `complete_in_progress` only shows up in the state history.
 *
 * Every price comes from {@see Merchant}; the platform sends item ids and
 * quantities, never amounts. Everything here is pure: no storage, no clock, no
 * randomness beyond new line ids, so the rules can be tested on their own.
 * {@see \Webconsulting\AgentNexus\Ucp\Http\CheckoutEndpoint} does the HTTP and
 * the persistence around it.
 */
final readonly class CheckoutService
{
    public const int MAX_LINE_ITEMS = 20;
    public const int MAX_QUANTITY = 100;

    /** A session lives six hours unless the platform finishes it — UCP's default TTL. */
    public const int TTL_SECONDS = 21600;

    /**
     * @param iterable<CompletionGuard> $guards
     */
    public function __construct(
        private Merchant $merchant,
        private SandboxPaymentHandler $payments,
        private ExtensionSettings $settings,
        #[AutowireIterator(CompletionGuard::TAG)]
        private iterable $guards = [],
    ) {}

    /**
     * POST /checkout-sessions.
     *
     * @param array<array-key, mixed> $request the decoded request body
     * @throws InvalidRequest
     */
    public function create(array $request, string $checkoutId, \DateTimeImmutable $now): CheckoutResult
    {
        ['lines' => $lines, 'errors' => $errors] = $this->lineItems($request, [], Messages::SEVERITY_UNRECOVERABLE);
        if ($errors !== []) {
            // No session is created for a cart the store cannot sell.
            return CheckoutResult::failure(200, $errors);
        }
        ['buyer' => $buyer, 'messages' => $messages] = $this->buyer($request);

        $checkout = $this->assemble(
            $checkoutId,
            $lines,
            $buyer,
            $this->context($request['context'] ?? null),
            $this->attribution($request['attribution'] ?? null),
            self::timestamp($now->add(new \DateInterval('PT' . self::TTL_SECONDS . 'S'))),
            $messages,
        );

        return new CheckoutResult(201, $checkout, $checkout, [['state' => $this->status($checkout), 'note' => 'Session created.']]);
    }

    /**
     * PUT /checkout-sessions/{id}: a full replacement. Line items, buyer,
     * context and attribution become exactly what the request says.
     *
     * @param array<string, mixed> $checkout the stored session
     * @param array<array-key, mixed> $request
     * @throws InvalidRequest
     */
    public function update(array $checkout, array $request, \DateTimeImmutable $now): CheckoutResult
    {
        $refusal = $this->refuseChange($checkout, $now);
        if ($refusal !== null) {
            return $refusal;
        }
        ['lines' => $lines, 'errors' => $errors] = $this->lineItems($request, $this->lineIds($checkout), Messages::SEVERITY_RECOVERABLE);
        if ($errors !== []) {
            // The update is rejected as a whole; the session stays as it was.
            return CheckoutResult::unchanged($this->withMessages($checkout, $errors));
        }
        ['buyer' => $buyer, 'messages' => $messages] = $this->buyer($request);

        $next = $this->assemble(
            $this->string($checkout['id'] ?? ''),
            $lines,
            $buyer,
            $this->context($request['context'] ?? null),
            $this->attribution($request['attribution'] ?? null),
            $this->string($checkout['expires_at'] ?? ''),
            $messages,
        );
        $status = $this->status($next);
        $transitions = $status !== $this->status($checkout)
            ? [['state' => $status, 'note' => $status === Spec::STATUS_READY ? 'Buyer details complete.' : 'Session updated.']]
            : [];

        return new CheckoutResult(200, $next, $next, $transitions);
    }

    /**
     * POST /checkout-sessions/{id}/complete: place the order.
     *
     * The request must carry exactly one payment instrument. Every
     * {@see CompletionGuard} is asked before the sandbox handler charges it; a
     * refusal or a declined payment leaves the session unchanged.
     *
     * @param array<string, mixed> $checkout
     * @param array<array-key, mixed> $request
     */
    public function complete(array $checkout, array $request, \DateTimeImmutable $now, string $orderId, string $permalinkUrl): CheckoutResult
    {
        $refusal = $this->refuseChange($checkout, $now);
        if ($refusal !== null) {
            return $refusal;
        }
        $payment = $request['payment'] ?? null;
        if (!is_array($payment)) {
            return CheckoutResult::unchanged($this->withMessages($checkout, [
                Messages::error('field_required', 'Add a payment with one instrument.', Messages::SEVERITY_RECOVERABLE, '$.payment'),
            ]));
        }
        $instruments = $payment['instruments'] ?? null;
        if (!is_array($instruments) || !array_is_list($instruments) || count($instruments) !== 1 || !is_array($instruments[0])) {
            return CheckoutResult::unchanged($this->withMessages($checkout, [
                Messages::error('payment_failed', 'Submit exactly one payment instrument.', Messages::SEVERITY_RECOVERABLE, '$.payment.instruments'),
            ]));
        }
        if ($this->status($checkout) !== Spec::STATUS_READY) {
            // Its messages already say what is missing (the buyer's email).
            return CheckoutResult::unchanged($checkout);
        }
        foreach ($this->guards as $guard) {
            $refused = $guard->check($checkout, $this->stringKeys($request));
            if ($refused !== null) {
                return CheckoutResult::unchanged($this->withMessages($checkout, [$refused]));
            }
        }
        $declined = $this->payments->charge($instruments[0], '$.payment.instruments[0]');
        if ($declined !== null) {
            return CheckoutResult::unchanged($this->withMessages($checkout, [$declined]));
        }

        $completed = $checkout;
        $completed['status'] = Spec::STATUS_COMPLETED;
        $completed['payment'] = ['instruments' => [$this->payments->receipt($instruments[0])]];
        $completed['order'] = [
            'id' => $orderId,
            'permalink_url' => $permalinkUrl,
            'label' => 'Sandbox order ' . strtoupper(substr($orderId, -8)),
        ];
        $completed['messages'] = [
            Messages::info('Sandbox order: no payment was taken and nothing will be delivered.', 'sandbox'),
            ...$this->billingNotes($checkout),
        ];
        unset($completed['continue_url']);

        return new CheckoutResult(200, $completed, $completed, [
            ['state' => Spec::STATUS_IN_PROGRESS, 'note' => 'Payment submitted.'],
            ['state' => Spec::STATUS_COMPLETED, 'note' => 'Order ' . $orderId . ' placed in the sandbox.'],
        ]);
    }

    /**
     * POST /checkout-sessions/{id}/cancel. Any session that is not final can
     * be canceled, an expired one included.
     *
     * @param array<string, mixed> $checkout
     */
    public function cancel(array $checkout): CheckoutResult
    {
        $refusal = $this->refuseFinal($checkout);
        if ($refusal !== null) {
            return $refusal;
        }
        $canceled = $checkout;
        $canceled['status'] = Spec::STATUS_CANCELED;
        $canceled['messages'] = [Messages::info('Checkout canceled. Nothing was ordered.', 'canceled'), ...$this->billingNotes($checkout)];
        unset($canceled['continue_url']);

        return new CheckoutResult(200, $canceled, $canceled, [['state' => Spec::STATUS_CANCELED, 'note' => 'Canceled by the platform.']]);
    }

    /**
     * The grand total in minor units.
     *
     * @param array<string, mixed> $checkout
     */
    public static function total(array $checkout): int
    {
        foreach (is_array($checkout['totals'] ?? null) ? $checkout['totals'] : [] as $total) {
            if (is_array($total) && ($total['type'] ?? null) === 'total' && is_int($total['amount'] ?? null)) {
                return $total['amount'];
            }
        }
        return 0;
    }

    /**
     * One line for lists: "€49.00 · 1 item".
     *
     * @param array<string, mixed> $checkout
     */
    public static function label(array $checkout): string
    {
        $count = 0;
        foreach (is_array($checkout['line_items'] ?? null) ? $checkout['line_items'] : [] as $line) {
            $count += is_array($line) && is_int($line['quantity'] ?? null) ? $line['quantity'] : 0;
        }
        $currency = is_string($checkout['currency'] ?? null) ? $checkout['currency'] : Merchant::CURRENCY;
        return Money::format(self::total($checkout), $currency) . ' · ' . $count . ($count === 1 ? ' item' : ' items');
    }

    public static function timestamp(\DateTimeImmutable $time): string
    {
        return $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param array<string, mixed> $checkout
     */
    public static function isExpired(array $checkout, \DateTimeImmutable $now): bool
    {
        $expiresAt = $checkout['expires_at'] ?? null;
        if (!is_string($expiresAt) || $expiresAt === '') {
            return false;
        }
        try {
            return new \DateTimeImmutable($expiresAt) < $now;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * The response `ucp` member: the version that processed the request, the
     * capability in use and the handler an instrument must name.
     *
     * @return array<string, mixed>
     */
    private function envelope(): array
    {
        return [
            'version' => Spec::VERSION,
            'status' => 'success',
            'capabilities' => [Spec::CAPABILITY_CHECKOUT => [['version' => Spec::VERSION]]],
            'payment_handlers' => $this->payments->declaration(),
        ];
    }

    /**
     * A full checkout from its parts: totals, status and messages are always
     * derived here, never taken from a request.
     *
     * @param list<array<string, mixed>> $lines
     * @param array<string, string> $buyer
     * @param array<string, mixed> $context
     * @param array<string, string> $attribution
     * @param list<array<string, mixed>> $messages what the request itself got wrong
     * @return array<string, mixed>
     */
    private function assemble(string $id, array $lines, array $buyer, array $context, array $attribution, string $expiresAt, array $messages): array
    {
        $subtotal = 0;
        foreach ($lines as $index => $line) {
            $subtotal += self::lineTotal($line);
            if ($this->merchant->isMonthly($this->itemId($line))) {
                $messages[] = Messages::info('Price per month.', 'billing_period', '$.line_items[' . $index . ']');
            }
        }
        $hasEmail = isset($buyer['email']);
        if (!$hasEmail && !$this->mentions($messages, '$.buyer.email')) {
            $messages[] = Messages::error('field_required', 'Add the buyer\'s email address. The licence is sent there.', Messages::SEVERITY_RECOVERABLE, '$.buyer.email');
        }
        $messages[] = Messages::warning('sandbox', 'Sandbox store: no payment is taken and nothing is delivered.');
        $messages[] = Messages::info('Your platform profile was not fetched, so every capability of this store is active.', 'profile_not_fetched');

        $checkout = [
            'ucp' => $this->envelope(),
            'id' => $id,
            'line_items' => $lines,
        ];
        if ($buyer !== []) {
            $checkout['buyer'] = $buyer;
        }
        if ($context !== []) {
            $checkout['context'] = $context;
        }
        if ($attribution !== []) {
            $checkout['attribution'] = $attribution;
        }
        $checkout['status'] = $hasEmail ? Spec::STATUS_READY : Spec::STATUS_INCOMPLETE;
        $checkout['currency'] = Merchant::CURRENCY;
        $checkout['totals'] = [
            ['type' => 'subtotal', 'display_text' => 'Subtotal', 'amount' => $subtotal],
            ['type' => 'total', 'display_text' => 'Total', 'amount' => $subtotal],
        ];
        $checkout['messages'] = Messages::ordered($messages);
        $checkout['links'] = $this->links();
        if ($expiresAt !== '') {
            $checkout['expires_at'] = $expiresAt;
        }
        return $checkout;
    }

    /**
     * Read and price the requested line items.
     *
     * Malformed entries make the request invalid; well-formed entries the
     * store cannot sell become error messages.
     *
     * @param array<array-key, mixed> $request
     * @param array<string, true> $knownIds line ids the session already has
     * @return array{lines: list<array<string, mixed>>, errors: list<array<string, mixed>>}
     * @throws InvalidRequest
     */
    private function lineItems(array $request, array $knownIds, string $severity): array
    {
        $entries = $request['line_items'] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
            throw new InvalidRequest('line_items must be a non-empty array.', 1758700001);
        }
        if (count($entries) > self::MAX_LINE_ITEMS) {
            throw new InvalidRequest(sprintf('A checkout takes at most %d line items.', self::MAX_LINE_ITEMS), 1758700002);
        }

        $lines = [];
        $errors = [];
        $usedIds = [];
        foreach ($entries as $index => $entry) {
            $path = '$.line_items[' . $index . ']';
            if (!is_array($entry)) {
                throw new InvalidRequest(sprintf('line_items[%d] must be an object.', $index), 1758700003);
            }
            $item = $entry['item'] ?? null;
            $itemId = is_array($item) ? ($item['id'] ?? null) : null;
            if (!is_string($itemId) || trim($itemId) === '') {
                throw new InvalidRequest(sprintf('line_items[%d].item.id is required.', $index), 1758700004);
            }
            $quantity = $entry['quantity'] ?? null;
            if (!is_int($quantity) || $quantity < 1) {
                throw new InvalidRequest(sprintf('line_items[%d].quantity must be a whole number of at least 1.', $index), 1758700005);
            }
            $product = $this->merchant->product($itemId);
            if ($product === []) {
                $errors[] = Messages::error('item_unavailable', sprintf('This store does not sell "%s".', mb_substr($itemId, 0, 64)), $severity, $path . '.item.id');
                continue;
            }
            if ($quantity > self::MAX_QUANTITY) {
                $errors[] = Messages::error('quantity_limit', sprintf('The sandbox sells at most %d of an item in one checkout.', self::MAX_QUANTITY), Messages::SEVERITY_RECOVERABLE, $path . '.quantity');
                continue;
            }
            if (!$this->isSoldPerPiece(is_array($item) ? ($item['quantity_unit'] ?? null) : null)) {
                $errors[] = Messages::error('quantity_unit_mismatch', 'Every item in this store is sold per piece (unit C62).', Messages::SEVERITY_RECOVERABLE, $path . '.item.quantity_unit');
                continue;
            }

            $requestedId = is_string($entry['id'] ?? null) ? $entry['id'] : '';
            $lineId = isset($knownIds[$requestedId]) && !isset($usedIds[$requestedId]) ? $requestedId : self::newLineId();
            $usedIds[$lineId] = true;
            $amount = $product['price'] * $quantity;
            $lines[] = [
                'id' => $lineId,
                'item' => ['id' => $product['id'], 'title' => $product['name'], 'price' => $product['price']],
                'quantity' => $quantity,
                'totals' => [
                    ['type' => 'subtotal', 'amount' => $amount],
                    ['type' => 'total', 'amount' => $amount],
                ],
            ];
        }
        return ['lines' => $lines, 'errors' => $errors];
    }

    /**
     * No descriptor means "whatever the store sells in"; a descriptor must
     * name the store's own basis, one piece (UN/CEFACT C62, scale 0).
     */
    private function isSoldPerPiece(mixed $quantityUnit): bool
    {
        if ($quantityUnit === null) {
            return true;
        }
        if (!is_array($quantityUnit)) {
            return false;
        }
        return ($quantityUnit['unit'] ?? null) === 'C62' && ($quantityUnit['scale'] ?? 0) === 0;
    }

    /**
     * @param array<array-key, mixed> $request
     * @return array{buyer: array<string, string>, messages: list<array<string, mixed>>}
     * @throws InvalidRequest
     */
    private function buyer(array $request): array
    {
        $buyer = $request['buyer'] ?? null;
        if ($buyer === null) {
            return ['buyer' => [], 'messages' => []];
        }
        if (!is_array($buyer) || ($buyer !== [] && array_is_list($buyer))) {
            throw new InvalidRequest('buyer must be an object.', 1758700006);
        }
        $result = [];
        $messages = [];
        foreach (['first_name', 'last_name', 'email', 'phone_number'] as $field) {
            $value = $buyer[$field] ?? null;
            if ($value === null) {
                continue;
            }
            if (!is_string($value) || mb_strlen($value) > 254) {
                throw new InvalidRequest(sprintf('buyer.%s must be a string of at most 254 characters.', $field), 1758700007);
            }
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            if ($field === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $messages[] = Messages::error('field_invalid', 'This email address is not valid.', Messages::SEVERITY_RECOVERABLE, '$.buyer.email');
                continue;
            }
            $result[$field] = $value;
        }
        return ['buyer' => $result, 'messages' => $messages];
    }

    /**
     * Provisional buyer signals are kept as sent, when they are an object.
     *
     * @return array<string, mixed>
     */
    private function context(mixed $context): array
    {
        return is_array($context) && !array_is_list($context) ? $this->stringKeys($context) : [];
    }

    /**
     * Attribution values are URL-style strings; anything else is dropped.
     *
     * @return array<string, string>
     */
    private function attribution(mixed $attribution): array
    {
        if (!is_array($attribution)) {
            return [];
        }
        $kept = [];
        foreach ($attribution as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $kept[$key] = mb_substr($value, 0, 512);
            }
        }
        return $kept;
    }

    /**
     * Legal links the platform must show. The sandbox has none of its own;
     * an installation can name its pages in the extension configuration.
     *
     * @return list<array{type: string, url: string, title: string}>
     */
    private function links(): array
    {
        $links = [];
        foreach ([
            'terms_of_service' => ['ucpTermsOfServiceUrl', 'Terms of service'],
            'privacy_policy' => ['ucpPrivacyPolicyUrl', 'Privacy policy'],
        ] as $type => [$setting, $title]) {
            $url = trim($this->settings->string($setting, ''));
            if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with($url, 'http')) {
                $links[] = ['type' => $type, 'url' => $url, 'title' => $title];
            }
        }
        return $links;
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function refuseChange(array $checkout, \DateTimeImmutable $now): ?CheckoutResult
    {
        $refusal = $this->refuseFinal($checkout);
        if ($refusal !== null) {
            return $refusal;
        }
        if (self::isExpired($checkout, $now)) {
            return CheckoutResult::failure(409, [
                Messages::error('checkout_expired', 'This checkout has expired. Start a new checkout.', Messages::SEVERITY_UNRECOVERABLE),
            ]);
        }
        return null;
    }

    /**
     * A completed checkout is immutable and a canceled one is over: every
     * change is a conflict (409), which is also what the official conformance
     * suite expects.
     *
     * @param array<string, mixed> $checkout
     */
    private function refuseFinal(array $checkout): ?CheckoutResult
    {
        $status = $this->status($checkout);
        if (!Spec::isTerminal($status)) {
            return null;
        }
        return CheckoutResult::failure(409, [
            Messages::error(
                'checkout_not_modifiable',
                $status === Spec::STATUS_COMPLETED
                    ? 'This checkout is completed and cannot change. Start a new checkout.'
                    : 'This checkout was canceled and cannot change. Start a new checkout.',
                Messages::SEVERITY_UNRECOVERABLE,
            ),
        ]);
    }

    /**
     * The stored session with this attempt's messages on top.
     *
     * @param array<string, mixed> $checkout
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function withMessages(array $checkout, array $messages): array
    {
        $existing = [];
        foreach (is_array($checkout['messages'] ?? null) ? $checkout['messages'] : [] as $message) {
            if (is_array($message)) {
                $existing[] = $this->stringKeys($message);
            }
        }
        $checkout['messages'] = Messages::ordered([...$messages, ...$existing]);
        return $checkout;
    }

    /**
     * The notes on how lines are billed, which stay true after the session
     * is over.
     *
     * @param array<string, mixed> $checkout
     * @return list<array<string, mixed>>
     */
    private function billingNotes(array $checkout): array
    {
        $notes = [];
        foreach (is_array($checkout['messages'] ?? null) ? $checkout['messages'] : [] as $message) {
            if (is_array($message) && ($message['code'] ?? null) === 'billing_period') {
                $notes[] = $this->stringKeys($message);
            }
        }
        return $notes;
    }

    /**
     * @param array<string, mixed> $checkout
     * @return array<string, true>
     */
    private function lineIds(array $checkout): array
    {
        $ids = [];
        foreach (is_array($checkout['line_items'] ?? null) ? $checkout['line_items'] : [] as $line) {
            if (is_array($line) && is_string($line['id'] ?? null)) {
                $ids[$line['id']] = true;
            }
        }
        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function mentions(array $messages, string $path): bool
    {
        return array_any($messages, static fn(array $message): bool => ($message['path'] ?? null) === $path);
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private function status(array $checkout): string
    {
        return $this->string($checkout['status'] ?? '');
    }

    /**
     * @param array<string, mixed> $line
     */
    private function itemId(array $line): string
    {
        return is_array($line['item'] ?? null) ? $this->string($line['item']['id'] ?? '') : '';
    }

    /**
     * @param array<string, mixed> $line
     */
    private static function lineTotal(array $line): int
    {
        foreach (is_array($line['totals'] ?? null) ? $line['totals'] : [] as $total) {
            if (is_array($total) && ($total['type'] ?? null) === 'total' && is_int($total['amount'] ?? null)) {
                return $total['amount'];
            }
        }
        return 0;
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private function stringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string)$key] = $value;
        }
        return $result;
    }

    private static function newLineId(): string
    {
        return 'li_' . bin2hex(random_bytes(6));
    }
}
