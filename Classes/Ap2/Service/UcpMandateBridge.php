<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Service;

use Webconsulting\AgentNexus\Ap2\Crypto\Base64Url;
use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Digest;
use Webconsulting\AgentNexus\Ap2\Crypto\Jcs;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Crypto\Jws;
use Webconsulting\AgentNexus\Ap2\Mandate\Check;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckType;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;
use Webconsulting\AgentNexus\Ap2\Sandbox\CredentialProvider;
use Webconsulting\AgentNexus\Ap2\Sandbox\KeyRing;
use Webconsulting\AgentNexus\Ap2\Sandbox\Merchant;
use Webconsulting\AgentNexus\Ap2\Sandbox\Role;

/**
 * AP2 as UCP carries it (dev.ucp.common.payment.ap2_mandate, UCP 2026-08-25):
 * what the UCP checkout needs from the AP2 layer, in one place.
 *
 *  - {@see merchantJwk()}: the business key for the profile's `keys`;
 *  - {@see authorize()}, {@see withAuthorization()}: `ap2.merchant_authorization`,
 *    a detached ES256 JWS over the JCS form of the checkout without `ap2`;
 *  - {@see verifyAuthorization()}: the platform's (and the business's) check of it;
 *  - {@see checkoutJwt()}: that signature re-attached to its payload — a compact
 *    JWS the business signed, which is what an AP2 checkout mandate binds to;
 *  - {@see verifyMandates()}: the `complete` request's `ap2.checkout_mandate`
 *    (and the payment mandate from `payment.instruments[*].credential.token`)
 *    against the session's checkout, answered with a UCP error code.
 */
final readonly class UcpMandateBridge
{
    public function __construct(
        private KeyRing $keys,
        private Merchant $merchant,
        private CredentialProvider $credentialProvider,
    ) {}

    /**
     * The business signing key for `keys` in /.well-known/ucp.
     *
     * @return array{kty: string, crv: string, x: string, y: string, kid: string, use: string, alg: string}
     */
    public function merchantJwk(): array
    {
        return $this->keys->signer(Role::Merchant)->jwk();
    }

    /**
     * `ap2.merchant_authorization` for a checkout response.
     *
     * @param array<string, mixed>|\stdClass $checkout the checkout as it will be sent; any `ap2` member is ignored
     */
    public function authorize(array|\stdClass $checkout): string
    {
        $key = $this->keys->signer(Role::Merchant);
        return Jws::signDetached(['kid' => $key->kid], self::signedBytes($checkout), $key);
    }

    /**
     * The checkout with `ap2.merchant_authorization` set.
     *
     * @param array<string, mixed> $checkout
     * @return array<string, mixed>
     */
    public function withAuthorization(array $checkout): array
    {
        $ap2 = Json::map($checkout['ap2'] ?? null);
        $ap2['merchant_authorization'] = $this->authorize($checkout);
        $checkout['ap2'] = $ap2;
        return $checkout;
    }

    /**
     * @param array<string, mixed> $checkout a checkout with `ap2.merchant_authorization`
     * @param string|null $authorization the detached JWS, when it travels separately
     */
    public function verifyAuthorization(array $checkout, ?string $authorization = null): UcpVerdict
    {
        $authorization ??= Json::string(Json::map($checkout['ap2'] ?? null)['merchant_authorization'] ?? null);
        if ($authorization === '') {
            return UcpVerdict::failure(UcpVerdict::MERCHANT_AUTHORIZATION_MISSING, UcpVerdict::CODES[UcpVerdict::MERCHANT_AUTHORIZATION_MISSING]);
        }
        try {
            $header = Json::decodeObject(Base64Url::decode(explode('.', $authorization)[0]));
            $key = $this->keys->publicKeyFor(Json::string($header['kid'] ?? null));
            if ($key === null || $this->keys->roleOf($key->kid) !== Role::Merchant) {
                throw new CryptoException('The key id is not the business key.', 1758700701);
            }
            Jws::verifyDetached($authorization, self::signedBytes($checkout), $key);
        } catch (CryptoException $e) {
            return UcpVerdict::failure(UcpVerdict::MERCHANT_AUTHORIZATION_INVALID, $e->getMessage());
        }
        return new UcpVerdict(true, null, 'The business signed this checkout.');
    }

    /**
     * The compact JWS the business signed — its detached authorization with
     * the JCS payload put back. AP2's `checkout_jwt`; its hash is
     * `checkout_hash` and the payment mandate's `transaction_id`.
     *
     * @param array<string, mixed> $checkout a checkout with `ap2.merchant_authorization`
     */
    public function checkoutJwt(array $checkout): ?string
    {
        $authorization = Json::string(Json::map($checkout['ap2'] ?? null)['merchant_authorization'] ?? null);
        $parts = explode('..', $authorization);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        return $parts[0] . '.' . Base64Url::encode(self::signedBytes($checkout)) . '.' . $parts[1];
    }

    public static function checkoutHash(string $checkoutJwt): string
    {
        return Digest::of($checkoutJwt);
    }

    /**
     * Verify the mandates of a `complete` request against the session.
     *
     * @param string|null $checkoutMandate `ap2.checkout_mandate`: a closed checkout mandate, alone or chained to its open one
     * @param array<string, mixed> $sessionCheckout the checkout of this session as the business last returned it, with `ap2.merchant_authorization`
     * @param string|null $paymentMandate the payment mandate from `payment.instruments[*].credential.token`, when there is one
     * @param string|null $nonce the challenge the business gave the platform for the checkout mandate; null accepts any it issued
     */
    public function verifyMandates(?string $checkoutMandate, array $sessionCheckout, ?string $paymentMandate = null, ?string $nonce = null): UcpVerdict
    {
        if ($checkoutMandate === null || trim($checkoutMandate) === '') {
            return UcpVerdict::failure(UcpVerdict::MANDATE_REQUIRED, UcpVerdict::CODES[UcpVerdict::MANDATE_REQUIRED]);
        }
        $authorization = $this->verifyAuthorization($sessionCheckout);
        if (!$authorization->valid) {
            return $authorization;
        }
        $sessionJwt = (string)$this->checkoutJwt($sessionCheckout);

        try {
            $checkoutChain = DelegateChain::parse($checkoutMandate);
        } catch (CryptoException $e) {
            return UcpVerdict::failure(UcpVerdict::MANDATE_INVALID_SIGNATURE, 'The checkout mandate cannot be read: ' . $e->getMessage());
        }
        $checkout = $this->merchant->verify($checkoutChain, $nonce, $sessionJwt);
        if (!$checkout->valid()) {
            return UcpVerdict::failure(self::codeOf($checkout), $checkout->errorDescription(), $checkout);
        }

        if ($paymentMandate === null || trim($paymentMandate) === '') {
            return new UcpVerdict(true, null, 'The checkout mandate is valid for this checkout.', $checkout->checks, $checkout);
        }
        try {
            $paymentChain = DelegateChain::parse($paymentMandate);
        } catch (CryptoException $e) {
            return UcpVerdict::failure(UcpVerdict::MANDATE_INVALID_SIGNATURE, 'The payment mandate cannot be read: ' . $e->getMessage(), $checkout);
        }
        $payment = $this->credentialProvider->verify(
            $paymentChain,
            null,
            self::checkoutHash($sessionJwt),
            $checkoutChain->count() > 1 ? $checkoutChain->root()->sdHash() : null,
        );
        if (!$payment->valid()) {
            return UcpVerdict::failure(self::codeOf($payment), $payment->errorDescription(), $checkout, $payment);
        }
        return new UcpVerdict(true, null, 'Both mandates are valid for this checkout.', [...$checkout->checks, ...$payment->checks], $checkout, $payment);
    }

    /**
     * The UCP code for the most serious AP2 failure.
     */
    public static function codeOf(Verdict $verdict): string
    {
        foreach ([CheckType::RootSignature, CheckType::Format, CheckType::AgentSignature, CheckType::Binding, CheckType::SingleMandate, CheckType::Content] as $type) {
            if ($verdict->failed($type)) {
                $unknownKey = $type === CheckType::RootSignature && array_any(
                    $verdict->checks,
                    static fn(Check $check): bool => $check->type === CheckType::RootSignature && str_starts_with($check->detail, 'No trusted key'),
                );
                return $unknownKey ? UcpVerdict::AGENT_MISSING_KEY : UcpVerdict::MANDATE_INVALID_SIGNATURE;
            }
        }
        if ($verdict->failed(CheckType::Lifetime)) {
            return UcpVerdict::MANDATE_EXPIRED;
        }
        return UcpVerdict::MANDATE_SCOPE_MISMATCH;
    }

    /**
     * The bytes `merchant_authorization` signs: JCS of the checkout without `ap2`.
     *
     * @param array<string, mixed>|\stdClass $checkout
     */
    private static function signedBytes(array|\stdClass $checkout): string
    {
        if ($checkout instanceof \stdClass) {
            $checkout = clone $checkout;
            unset($checkout->ap2);
            return Jcs::canonicalize($checkout);
        }
        unset($checkout['ap2']);
        return Jcs::canonicalize($checkout === [] ? new \stdClass() : $checkout);
    }
}
