<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Service;

use Webconsulting\AgentNexus\Ap2\Mandate\MandateContent;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateLedger;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateUsage;
use Webconsulting\AgentNexus\Ap2\Mandate\PaymentVerifier;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Ap2\Mandate\VerificationContext;
use Webconsulting\AgentNexus\Ap2\Sandbox\DemoCatalogue;
use Webconsulting\AgentNexus\Ap2\Sandbox\KeyRing;
use Webconsulting\AgentNexus\Ap2\Sandbox\Merchant;
use Webconsulting\AgentNexus\Ap2\Sandbox\Parties;
use Webconsulting\AgentNexus\Ap2\Sandbox\Role;
use Webconsulting\AgentNexus\Ap2\Sandbox\ShoppingAgent;
use Webconsulting\AgentNexus\Ap2\Sandbox\TrustedSurface;

/**
 * A real payment-mandate verification run on the side, for pages that list
 * what a verifier checks: the checks come from verifying an actual chain, not
 * from a description of one. Nothing is recorded, nothing is used up.
 */
final readonly class SampleVerification
{
    public function __construct(
        private KeyRing $keys,
        private DemoCatalogue $catalogue,
    ) {}

    public function verdict(int $capCents = 50000): Verdict
    {
        $now = time();
        $agent = new ShoppingAgent($this->keys);
        $trustedSurface = new TrustedSurface($this->keys);
        $list = $this->catalogue->shoppingList();
        $lines = array_map(static fn(array $line): array => [
            'id' => $line['id'],
            'acceptable' => array_map(static fn(array $option): array => ['id' => $option['id'], 'title' => $option['title']], $line['options']),
            'quantity' => $line['quantity'],
        ], $list);

        $openCheckout = $trustedSurface->sign(MandateContent::openCheckout($lines, [Parties::MERCHANT], $agent->publicJwk(), $now, $now + 600));
        $openPayment = $trustedSurface->sign(MandateContent::openPayment(
            $capCents,
            Parties::CURRENCY,
            [Parties::MERCHANT],
            [Parties::INSTRUMENT],
            $openCheckout->root()->sdHash(),
            $agent->publicJwk(),
            $now,
            $now + 600,
        ));
        $cart = $agent->chooseCart($list, $capCents, false);
        $checkout = Merchant::signCheckout($cart['lines'], $this->keys->signer(Role::Merchant), $now);
        $nonce = bin2hex(random_bytes(16));
        $closed = $agent->close(
            $openPayment,
            MandateContent::closedPayment($checkout->hash, Parties::MERCHANT, $checkout->total, Parties::CURRENCY, Parties::INSTRUMENT, $now, $now + 600),
            MandateType::Payment->audience(),
            $nonce,
            $now,
        );

        return new PaymentVerifier()->verify($closed, new VerificationContext(
            rootKey: $this->keys->trustedRoots(),
            ledger: new class implements MandateLedger {
                public function issuedCheckout(string $checkoutHash): ?string
                {
                    return null;
                }

                public function usage(string $openReference): MandateUsage
                {
                    return new MandateUsage();
                }

                public function wasAccepted(string $closedReference): bool
                {
                    return false;
                }
            },
            audience: MandateType::Payment->audience(),
            nonce: $nonce,
            transactionId: $checkout->hash,
            openCheckoutHash: $openCheckout->root()->sdHash(),
            singleUse: false,
            now: $now,
        ));
    }
}
