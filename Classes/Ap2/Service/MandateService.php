<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Service;

use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Digest;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Mandate\ChainVerifier;
use Webconsulting\AgentNexus\Ap2\Mandate\Check;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckType;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateChecks;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateContent;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateLedger;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateSchema;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\Money;
use Webconsulting\AgentNexus\Ap2\Mandate\Receipt;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Ap2\Sandbox\Artefact;
use Webconsulting\AgentNexus\Ap2\Sandbox\CredentialProvider;
use Webconsulting\AgentNexus\Ap2\Sandbox\DemoCatalogue;
use Webconsulting\AgentNexus\Ap2\Sandbox\KeyRing;
use Webconsulting\AgentNexus\Ap2\Sandbox\MandateRecorder;
use Webconsulting\AgentNexus\Ap2\Sandbox\Merchant;
use Webconsulting\AgentNexus\Ap2\Sandbox\Parties;
use Webconsulting\AgentNexus\Ap2\Sandbox\RecordingContext;
use Webconsulting\AgentNexus\Ap2\Sandbox\Role;
use Webconsulting\AgentNexus\Ap2\Sandbox\ShoppingAgent;
use Webconsulting\AgentNexus\Ap2\Sandbox\TrustedSurface;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;

/**
 * The mandate studio: sign any of the four mandate types from a form, and
 * verify whatever someone pastes — a mandate, a chain, a receipt, a signed
 * checkout — the way the verifying role would.
 *
 * Verifying here is a dry run. It reports "Not used before" but never marks
 * anything as used; only the flow's verifiers accept mandates.
 */
final readonly class MandateService
{
    public const int MAX_TOKENS = 4;

    public function __construct(
        private TrustedSurface $trustedSurface,
        private ShoppingAgent $agent,
        private Merchant $merchant,
        private CredentialProvider $credentialProvider,
        private DemoCatalogue $catalogue,
        private MandateRecorder $recorder,
        private MandateLedger $ledger,
        private KeyRing $keys,
        private ChainVerifier $chains,
        private ObjectStore $objects,
    ) {}

    /**
     * Sign one mandate as the studio form describes it.
     *
     * @param array<string, mixed> $input type, signer, cap, merchant, items, expires, linked, transaction
     * @return array{artefact: array<string, mixed>, related: list<array<string, mixed>>, chainId: string}
     * @throws StudioException when the form asks for something that cannot be signed
     */
    public function mint(array $input, RecordingContext $context): array
    {
        $type = MandateType::fromKey(Json::string($input['type'] ?? null))
            ?? throw new StudioException('type', 'Choose a mandate type.');
        $merchant = Parties::merchant(Json::string($input['merchant'] ?? null, Parties::MERCHANT['id']))
            ?? throw new StudioException('merchant', 'Choose a merchant.');
        $minutes = is_numeric($input['expires'] ?? null) ? (int)$input['expires'] : 60;
        if ($minutes < 1 || $minutes > 1440) {
            throw new StudioException('expires', 'The lifetime must be between 1 and 1440 minutes.');
        }
        $now = time();
        $expires = $now + 60 * $minutes;
        $byAgent = ($input['signer'] ?? null) === Role::ShoppingAgent->value && !$type->isOpen();
        $linked = trim(Json::string($input['linked'] ?? null));
        $items = $this->items($input['items'] ?? null);

        return match ($type) {
            MandateType::OpenCheckout => $this->openCheckout($items, $merchant, $now, $expires, $context),
            MandateType::OpenPayment => $this->openPayment(Json::string($input['cap'] ?? null), $merchant, $linked, $now, $expires, $context),
            MandateType::Checkout => $this->closedCheckout($byAgent, $items, $linked, $now, $expires, $context),
            MandateType::Payment => $this->closedPayment($byAgent, $items, $merchant, $linked, trim(Json::string($input['transaction'] ?? null)), $now, $expires, $context),
        };
    }

    /**
     * Verify what was pasted: up to four tokens separated by white space. A
     * checkout chain pasted together with a payment chain lets the payment's
     * `payment.reference` be checked against it.
     *
     * @return list<array<string, mixed>>
     * @throws StudioException when there is nothing to verify or too much
     */
    public function verify(string $input): array
    {
        $tokens = preg_split('/\s+/', trim($input), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            throw new StudioException('empty', 'Paste a mandate, a chain or a receipt.');
        }
        if (count($tokens) > self::MAX_TOKENS) {
            throw new StudioException('tooMany', sprintf('Paste at most %d tokens at once.', self::MAX_TOKENS));
        }

        $chains = [];
        $openCheckoutHash = null;
        foreach ($tokens as $index => $token) {
            if (!str_contains($token, '~')) {
                continue;
            }
            try {
                $chains[$index] = DelegateChain::parse($token);
            } catch (CryptoException) {
                continue;
            }
            if ($chains[$index]->count() > 1 && (Artefact::mandateOf($chains[$index]->leaf())['vct'] ?? null) === MandateType::Checkout->value) {
                $openCheckoutHash = $chains[$index]->root()->sdHash();
            }
        }

        $results = [];
        foreach ($tokens as $index => $token) {
            $results[] = str_contains($token, '~')
                ? $this->verifyMandate($token, $chains[$index] ?? null, $openCheckoutHash)
                : $this->verifyJwt($token);
        }
        return $results;
    }

    /**
     * @param list<array{id: string, title: string, price: int}> $items
     * @param array{id: string, name: string, website: string} $merchant
     * @return array{artefact: array<string, mixed>, related: list<array<string, mixed>>, chainId: string}
     */
    private function openCheckout(array $items, array $merchant, int $now, int $expires, RecordingContext $context): array
    {
        if ($items === []) {
            throw new StudioException('items', 'Pick at least one item.');
        }
        $lines = [];
        foreach ($items as $index => $item) {
            $lines[] = ['id' => 'line-' . ($index + 1), 'acceptable' => [['id' => $item['id'], 'title' => $item['title']]], 'quantity' => 1];
        }
        $chain = $this->trustedSurface->sign(MandateContent::openCheckout($lines, [$merchant], $this->agent->publicJwk(), $now, $expires));
        $artefact = Artefact::mandate($chain, Role::TrustedSurface, sprintf(
            '%s · %d %s · %s',
            MandateType::OpenCheckout->value,
            count($lines),
            count($lines) === 1 ? 'line' : 'lines',
            $merchant['name'],
        ));
        $this->recorder->record($artefact, $chain->reference(), $context);
        return ['artefact' => $artefact->toArray(), 'related' => [], 'chainId' => $chain->reference()];
    }

    /**
     * @param array{id: string, name: string, website: string} $merchant
     * @return array{artefact: array<string, mixed>, related: list<array<string, mixed>>, chainId: string}
     */
    private function openPayment(string $capInput, array $merchant, string $linked, int $now, int $expires, RecordingContext $context): array
    {
        $cap = Money::parse($capInput);
        if ($cap === null || $cap < 1) {
            throw new StudioException('cap', 'Enter the spending cap in euros, for example 500 or 499.90.');
        }
        $openCheckout = $this->openMandate($linked, MandateType::OpenCheckout)
            ?? throw new StudioException('linkedCheckout', 'Paste the open checkout mandate this payment belongs to. Sign one first.');
        $chainId = $this->chainIdOf($openCheckout);
        $chain = $this->trustedSurface->sign(MandateContent::openPayment(
            $cap,
            Parties::CURRENCY,
            [$merchant],
            $this->credentialProvider->instruments(),
            $openCheckout->root()->sdHash(),
            $this->agent->publicJwk(),
            $now,
            $expires,
        ));
        $artefact = Artefact::mandate($chain, Role::TrustedSurface, MandateType::OpenPayment->value . ' · up to ' . Money::format($cap));
        $this->recorder->record($artefact, $chainId, $context);
        return ['artefact' => $artefact->toArray(), 'related' => [], 'chainId' => $chainId];
    }

    /**
     * @param list<array{id: string, title: string, price: int}> $items
     * @return array{artefact: array<string, mixed>, related: list<array<string, mixed>>, chainId: string}
     */
    private function closedCheckout(bool $byAgent, array $items, string $linked, int $now, int $expires, RecordingContext $context): array
    {
        if ($items === []) {
            throw new StudioException('items', 'Pick at least one item.');
        }
        $open = $byAgent
            ? ($this->openMandate($linked, MandateType::OpenCheckout)
                ?? throw new StudioException('linkedOpen', 'Paste the open checkout mandate the agent closes.'))
            : null;

        $checkout = $this->merchant->checkout(array_map(static fn(array $item): array => $item + ['quantity' => 1], $items), $now);
        $content = MandateContent::closedCheckout($checkout->jwt, $checkout->hash, $now, $expires);
        $chain = $open === null
            ? $this->trustedSurface->sign($content)
            : $this->agent->close($open, $content, MandateType::Checkout->audience(), $this->merchant->challenge(), $now);
        $chainId = $open === null ? $chain->reference() : $this->chainIdOf($open);

        $checkoutArtefact = Artefact::jwt(Artefact::CHECKOUT_JWT, $checkout->jwt, Role::Merchant, 'checkout_jwt · ' . Money::format($checkout->total), $checkout->hash);
        $artefact = Artefact::mandate($chain, $open === null ? Role::TrustedSurface : Role::ShoppingAgent, MandateType::Checkout->value . ' · ' . Money::format($checkout->total));
        $this->recorder->record($checkoutArtefact, $chainId, $context);
        $this->recorder->record($artefact, $chainId, $context);
        return ['artefact' => $artefact->toArray(), 'related' => [$checkoutArtefact->toArray()], 'chainId' => $chainId];
    }

    /**
     * @param list<array{id: string, title: string, price: int}> $items
     * @param array{id: string, name: string, website: string} $payee
     * @return array{artefact: array<string, mixed>, related: list<array<string, mixed>>, chainId: string}
     */
    private function closedPayment(bool $byAgent, array $items, array $payee, string $linked, string $transaction, int $now, int $expires, RecordingContext $context): array
    {
        $open = $byAgent
            ? ($this->openMandate($linked, MandateType::OpenPayment)
                ?? throw new StudioException('linkedOpen', 'Paste the open payment mandate the agent closes.'))
            : null;

        $related = [];
        if ($transaction !== '') {
            $checkoutJwt = $this->ledger->issuedCheckout($transaction)
                ?? throw new StudioException('transaction', 'No checkout with this hash was issued. Leave the field empty to create one.');
            $total = self::totalOf(Jws::decode($checkoutJwt)->payload());
            $recorded = null;
        } else {
            if ($items === []) {
                throw new StudioException('items', 'Pick at least one item, or enter the hash of a checkout.');
            }
            $checkout = $this->merchant->checkout(array_map(static fn(array $item): array => $item + ['quantity' => 1], $items), $now);
            $transaction = $checkout->hash;
            $total = $checkout->total;
            $recorded = Artefact::jwt(Artefact::CHECKOUT_JWT, $checkout->jwt, Role::Merchant, 'checkout_jwt · ' . Money::format($total), $checkout->hash);
            $related[] = $recorded->toArray();
        }

        $content = MandateContent::closedPayment($transaction, $payee, $total, Parties::CURRENCY, Parties::INSTRUMENT, $now, $expires);
        $chain = $open === null
            ? $this->trustedSurface->sign($content)
            : $this->agent->close($open, $content, MandateType::Payment->audience(), $this->credentialProvider->challenge(), $now);
        $chainId = $open === null ? $chain->reference() : $this->chainIdOf($open);

        $artefact = Artefact::mandate($chain, $open === null ? Role::TrustedSurface : Role::ShoppingAgent, MandateType::Payment->value . ' · ' . Money::format($total));
        if ($recorded !== null) {
            $this->recorder->record($recorded, $chainId, $context);
        }
        $this->recorder->record($artefact, $chainId, $context);
        return ['artefact' => $artefact->toArray(), 'related' => $related, 'chainId' => $chainId];
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyMandate(string $raw, ?DelegateChain $chain, ?string $openCheckoutHash): array
    {
        if ($chain === null) {
            try {
                $chain = DelegateChain::parse($raw);
            } catch (CryptoException $e) {
                return self::report('Mandate', 'unknown', new Verdict([Check::fail(CheckType::Format, $e->getMessage())]), null);
            }
        }
        $mandate = Artefact::mandateOf($chain->leaf());
        $type = MandateType::tryFrom(Json::string($mandate['vct'] ?? null));
        $artefact = Artefact::mandate($chain, $chain->count() > 1 ? Role::ShoppingAgent : Role::TrustedSurface, Json::string($mandate['vct'] ?? null, 'mandate'));

        if ($type === null || $type->isOpen()) {
            $result = $this->chains->verify($chain, $this->keys->trustedRoots(), null, null);
            $verdict = new Verdict($result->checks, $chain, $result->mandates);
            if ($result->readable) {
                $verdict = $type === null
                    ? $verdict->with(Check::fail(CheckType::MandateType, sprintf('"%s" is not an AP2 v0.2 mandate type.', Json::string($mandate['vct'] ?? null))))
                    : $verdict->with(
                        $chain->count() === 1
                            ? Check::pass(CheckType::MandateType, $type->value . ' (open: the agent has not closed it yet)')
                            : Check::fail(CheckType::MandateType, 'An open mandate is the first token of a chain, never a later one.'),
                        MandateChecks::content($type, $mandate, null),
                    );
            }
            return self::report($type?->title() ?? 'Mandate', $type->value ?? 'unknown', $verdict, $artefact);
        }

        $verdict = $type->isCheckout()
            ? $this->merchant->verify($chain)
            : $this->credentialProvider->verify($chain, null, null, $openCheckoutHash);
        return self::report($type->title(), $type->value, $verdict, $artefact);
    }

    /**
     * A receipt or a signed checkout: whose key signed it, whether it is
     * complete, and whether it refers to something on record.
     *
     * @return array<string, mixed>
     */
    private function verifyJwt(string $token): array
    {
        try {
            $jws = Jws::decode($token);
            $claims = $jws->payload();
        } catch (CryptoException $e) {
            return self::report('Token', 'unknown', new Verdict([Check::fail(CheckType::Format, $e->getMessage())]), null);
        }
        $kind = match (true) {
            isset($claims['status'], $claims['reference']) && array_key_exists('payment_id', $claims) => Receipt::PAYMENT,
            isset($claims['status'], $claims['reference']) => Receipt::CHECKOUT,
            isset($claims['line_items']) => Artefact::CHECKOUT_JWT,
            default => 'jwt',
        };
        $role = $this->keys->roleOf($jws->kid());
        $key = $this->keys->publicKeyFor($jws->kid());
        $expected = match ($kind) {
            Receipt::CHECKOUT, Artefact::CHECKOUT_JWT => [Role::Merchant],
            Receipt::PAYMENT => [Role::PaymentProcessor, Role::CredentialProvider],
            default => Role::cases(),
        };

        $checks = [match (true) {
            $key === null || $role === null => Check::fail(CheckType::Signature, sprintf('No sandbox role has the key id "%s".', $jws->kid())),
            !$jws->verifiesWith($key) => Check::fail(CheckType::Signature, sprintf('The signature does not verify with %s.', $key->kid)),
            !in_array($role, $expected, true) => Check::fail(CheckType::Signature, sprintf('Signed by the %s, which does not issue this.', strtolower($role->label()))),
            default => Check::pass(CheckType::Signature, sprintf('Signed by the %s (%s)', strtolower($role->label()), $key->kid)),
        }];

        if ($kind === Receipt::CHECKOUT || $kind === Receipt::PAYMENT) {
            $violations = MandateSchema::receiptViolations($kind, $claims);
            $checks[] = $violations === []
                ? Check::pass(CheckType::Content, 'Status ' . Json::string($claims['status']))
                : Check::fail(CheckType::Content, implode(' ', $violations));
            $reference = Json::string($claims['reference']);
            $mandate = $reference === '' ? null : $this->objects->find(ObjectKind::Mandate, $reference);
            $checks[] = $mandate !== null
                ? Check::pass(CheckType::ReceiptReference, $mandate->label)
                : Check::fail(CheckType::ReceiptReference, 'No mandate with this reference is on record.');
        } elseif ($kind === Artefact::CHECKOUT_JWT) {
            $issued = $this->ledger->issuedCheckout(Digest::of($token));
            $checks[] = $issued !== null && hash_equals($issued, $token)
                ? Check::pass(CheckType::MerchantCheckout, Json::string($claims['id'] ?? null, 'checkout') . ' · ' . Money::format(self::totalOf($claims), Json::string($claims['currency'] ?? null, 'EUR')))
                : Check::fail(CheckType::MerchantCheckout, 'This merchant did not issue this checkout.');
            $exp = $claims['exp'] ?? null;
            $checks[] = is_int($exp) && time() > $exp + ChainVerifier::CLOCK_SKEW
                ? Check::fail(CheckType::Lifetime, 'The checkout expired on ' . gmdate('j M Y, H:i', $exp) . ' UTC.')
                : Check::pass(CheckType::Lifetime, is_int($exp) ? 'Valid until ' . gmdate('j M Y, H:i', $exp) . ' UTC' : 'No expiry set');
        }

        $title = match ($kind) {
            Receipt::CHECKOUT => 'Checkout receipt',
            Receipt::PAYMENT => 'Payment receipt',
            Artefact::CHECKOUT_JWT => 'Signed checkout',
            default => 'Signed token',
        };
        return self::report($title, $kind, new Verdict($checks), Artefact::jwt($kind, $token, $role ?? Role::Merchant, $title));
    }

    /**
     * @return list<array{id: string, title: string, price: int}>
     */
    private function items(mixed $ids): array
    {
        $items = [];
        foreach (Json::list($ids) as $id) {
            $item = is_string($id) ? $this->catalogue->item($id) : null;
            if ($item !== null && !in_array($item, $items, true)) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * An open mandate pasted into the form, or null when the field is empty.
     */
    private function openMandate(string $linked, MandateType $type): ?DelegateChain
    {
        if ($linked === '') {
            return null;
        }
        try {
            $chain = DelegateChain::parse($linked);
        } catch (CryptoException $e) {
            throw new StudioException('linkedInvalid', 'The pasted mandate cannot be read: ' . $e->getMessage());
        }
        $vct = Json::string(Artefact::mandateOf($chain->root())['vct'] ?? null);
        if ($chain->count() !== 1 || $vct !== $type->value) {
            throw new StudioException('linkedInvalid', sprintf('Paste a single %s (%s), not %s.', strtolower($type->title()), $type->value, $vct !== '' ? $vct : 'this token'));
        }
        return $chain;
    }

    /** The chain an open mandate belongs to: where it was recorded, or itself. */
    private function chainIdOf(DelegateChain $open): string
    {
        $recorded = $this->objects->find(ObjectKind::Mandate, $open->root()->reference());
        return $recorded !== null && $recorded->contextId !== '' ? $recorded->contextId : $open->root()->reference();
    }

    /**
     * @param array<string, mixed> $checkout
     */
    private static function totalOf(array $checkout): int
    {
        foreach (Json::objects($checkout['totals'] ?? null) as $total) {
            if (($total['type'] ?? null) === 'total' && is_int($total['amount'] ?? null)) {
                return $total['amount'];
            }
        }
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function report(string $title, string $kind, Verdict $verdict, ?Artefact $artefact): array
    {
        return [
            'title' => $title,
            'kind' => $kind,
            'reference' => $artefact->reference ?? '',
            'artefact' => $artefact?->toArray(),
        ] + $verdict->toArray();
    }
}
