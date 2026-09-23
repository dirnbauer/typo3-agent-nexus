<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\DecodedJws;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\EcKey;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwt;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwtProcessor;

/**
 * Verifies a delegate SD-JWT chain the way the AP2 SDK's `verify_chain` and
 * `kb_sd_jwt.verify` do:
 *
 *  1. the root SD-JWT verifies with a trusted key, found by its `kid`;
 *  2. every following KB-SD-JWT verifies with the key the token before it
 *     names in `cnf.jwk`, has the right `typ`, and binds to that token with
 *     exactly one of `sd_hash` (over it as presented) or `issuer_jwt_hash`;
 *  3. the closing token carries `iat` and the verifier's `aud` and `nonce`,
 *     and no `cnf` of its own;
 *  4. every token discloses exactly one mandate in `delegate_payload`;
 *  5. `exp` and `iat` hold with 300 seconds of clock skew.
 *
 * Everything that can be checked is checked and reported, so a failure shows
 * next to what still held.
 */
final class ChainVerifier
{
    public const int CLOCK_SKEW = 300;
    public const array TERMINAL_TYPES = ['kb+sd-jwt', 'kb-sd-jwt'];
    public const array INTERMEDIATE_TYPES = ['kb+sd-jwt+kb', 'kb-sd-jwt+kb'];

    /**
     * @param \Closure(DecodedJws): ?EcKey $rootKey the trusted key for the root token's header, or null
     * @param string|null $audience the `aud` the closing token must carry; null skips the check
     * @param (\Closure(string): bool)|string|null $nonce the expected nonce, a test for it, or null to skip
     */
    public function verify(DelegateChain $chain, \Closure $rootKey, ?string $audience, \Closure|string|null $nonce, ?int $now = null): ChainResult
    {
        $now ??= time();
        $log = new CheckLog();
        $mandates = [];
        $count = $chain->count();
        $root = $chain->root();
        $expiries = [];

        if (!self::isRootType($root->typ())) {
            $log->fail(CheckType::Format, sprintf('The first token has the type "%s"; a mandate starts with an SD-JWT such as dc+sd-jwt.', $root->typ()));
        }
        $key = $rootKey($root->jws);
        if ($key === null) {
            $log->fail(CheckType::RootSignature, sprintf('No trusted key has the id "%s".', $root->jws->kid()));
        } elseif (!$root->jws->verifiesWith($key)) {
            $log->fail(CheckType::RootSignature, sprintf('The signature does not verify with the key %s.', $key->kid));
        } else {
            $log->pass(CheckType::RootSignature, 'Verified with the key ' . $key->kid);
        }

        $processed = $this->process($root, 1, $log);
        if ($processed === null) {
            return new ChainResult($log->checks(), $mandates, false);
        }
        [$payload, $item] = $processed;
        $this->times([$payload, $item], 1, $now, $log, $expiries);
        $mandates[] = $item;

        for ($index = 1; $index < $count; $index++) {
            $token = $chain->tokens[$index];
            $previous = $chain->tokens[$index - 1];
            $isLast = $index === $count - 1;
            $position = $index + 1;

            $allowed = $isLast ? self::TERMINAL_TYPES : self::INTERMEDIATE_TYPES;
            if (!in_array($token->typ(), $allowed, true)) {
                $log->fail(CheckType::Format, sprintf('Token %d has the type "%s"; expected %s.', $position, $token->typ(), implode(' or ', $allowed)));
            }
            $this->agentSignature($token, $mandates[$index - 1], $position, $log);

            $processed = $this->process($token, $position, $log);
            if ($processed === null) {
                return new ChainResult($log->checks(), $mandates, false);
            }
            [$payload, $item] = $processed;
            $this->binding($payload, $previous, $position, $log);
            if ($isLast) {
                $this->audience($payload, $audience, $nonce, $log);
                if (!array_key_exists('iat', $payload)) {
                    $log->fail(CheckType::Lifetime, 'The closing token has no iat.');
                }
            }
            $delegates = Json::isObject($item['cnf'] ?? null);
            if ($isLast && $delegates) {
                $log->fail(CheckType::Format, 'The closed mandate carries a cnf key; only an open mandate may.');
            } elseif (!$isLast && !$delegates) {
                $log->fail(CheckType::Format, sprintf('Token %d delegates further but names no key in cnf.', $position));
            }
            $this->times([$payload, $item], $position, $now, $log, $expiries);
            $mandates[] = $item;
        }

        $log->passUnlessFailed(CheckType::Format, sprintf(
            '%s of %d %s (%s)',
            $count === 1 ? 'SD-JWT' : 'Delegate SD-JWT chain',
            $count,
            $count === 1 ? 'token' : 'tokens',
            implode(', ', array_map(static fn(SdJwt $token): string => $token->typ(), $chain->tokens)),
        ));
        $log->passUnlessFailed(CheckType::SingleMandate, $count === 1 ? 'One mandate disclosed' : 'One mandate disclosed in each token');
        $log->passUnlessFailed(CheckType::Lifetime, $expiries === []
            ? 'No expiry set'
            : 'Valid until ' . gmdate('j M Y, H:i', min($expiries)) . ' UTC');

        return new ChainResult($log->checks(), $mandates, true);
    }

    public static function isRootType(string $typ): bool
    {
        return str_ends_with($typ, 'sd-jwt') && !in_array($typ, [...self::TERMINAL_TYPES, ...self::INTERMEDIATE_TYPES], true);
    }

    /**
     * The token's payload with its disclosures applied, and the one mandate it
     * discloses — or null, with the failure logged.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}|null
     */
    private function process(SdJwt $token, int $position, CheckLog $log): ?array
    {
        try {
            $payload = SdJwtProcessor::process($token);
        } catch (CryptoException $e) {
            $log->fail(CheckType::Format, sprintf('Token %d: %s', $position, $e->getMessage()));
            return null;
        }
        if (!is_array($payload['delegate_payload'] ?? null) || !array_is_list($payload['delegate_payload'])) {
            $log->fail(CheckType::SingleMandate, sprintf('Token %d has no delegate_payload.', $position));
            return null;
        }
        $items = Json::objects($payload['delegate_payload']);
        if (count($items) !== 1) {
            $log->fail(CheckType::SingleMandate, sprintf('Token %d discloses %d mandates; exactly one is required.', $position, count($items)));
            return null;
        }
        return [$payload, $items[0]];
    }

    /**
     * @param array<string, mixed> $previousMandate
     */
    private function agentSignature(SdJwt $token, array $previousMandate, int $position, CheckLog $log): void
    {
        try {
            $key = EcKey::fromJwk(Json::map(Json::map($previousMandate['cnf'] ?? null)['jwk'] ?? null));
        } catch (CryptoException $e) {
            $log->fail(CheckType::AgentSignature, sprintf('The mandate before token %d names no usable key: %s', $position, $e->getMessage()));
            return;
        }
        if (!$token->jws->verifiesWith($key)) {
            $log->fail(CheckType::AgentSignature, sprintf('Token %d is not signed with the key the mandate before it names.', $position));
            return;
        }
        $log->pass(CheckType::AgentSignature, 'Verified with the key in cnf.jwk (thumbprint ' . substr($key->thumbprint(), 0, 12) . '…)');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function binding(array $payload, SdJwt $previous, int $position, CheckLog $log): void
    {
        $hasSdHash = array_key_exists('sd_hash', $payload);
        $hasJwtHash = array_key_exists('issuer_jwt_hash', $payload);
        if ($hasSdHash === $hasJwtHash) {
            $log->fail(CheckType::Binding, sprintf('Token %d must carry exactly one of sd_hash and issuer_jwt_hash.', $position));
            return;
        }
        $claim = $hasSdHash ? 'sd_hash' : 'issuer_jwt_hash';
        $expected = $hasSdHash ? $previous->sdHash() : $previous->issuerJwtHash();
        $actual = Json::string($payload[$claim]);
        if (!hash_equals($expected, $actual)) {
            $log->fail(CheckType::Binding, sprintf('The %s of token %d does not match the mandate it closes.', $claim, $position));
            return;
        }
        $log->pass(CheckType::Binding, $claim . ' ' . substr($actual, 0, 12) . '… matches the approved mandate');
    }

    /**
     * @param array<string, mixed> $payload
     * @param (\Closure(string): bool)|string|null $nonce
     */
    private function audience(array $payload, ?string $audience, \Closure|string|null $nonce, CheckLog $log): void
    {
        $problems = [];
        $details = [];
        $aud = Json::string($payload['aud'] ?? null);
        $tokenNonce = Json::string($payload['nonce'] ?? null);
        if ($aud === '' || $tokenNonce === '') {
            $problems[] = 'The closing token must carry aud and nonce.';
        }
        if ($audience !== null) {
            if ($aud !== $audience) {
                $problems[] = sprintf('aud is "%s", expected "%s".', $aud, $audience);
            } else {
                $details[] = 'aud ' . $aud;
            }
        }
        if (is_string($nonce)) {
            if (!hash_equals($nonce, $tokenNonce)) {
                $problems[] = 'The nonce does not match the challenge.';
            } else {
                $details[] = 'nonce matches the challenge';
            }
        } elseif ($nonce instanceof \Closure) {
            if ($tokenNonce === '' || !$nonce($tokenNonce)) {
                $problems[] = 'The nonce was not issued by this verifier.';
            } else {
                $details[] = 'nonce was issued by this verifier';
            }
        }
        if ($problems !== []) {
            $log->fail(CheckType::Audience, implode(' ', $problems));
            return;
        }
        $log->pass(CheckType::Audience, $details === [] ? 'aud ' . $aud . ', nonce ' . $tokenNonce : implode(', ', $details));
    }

    /**
     * @param list<array<string, mixed>> $claimSets
     * @param list<int> $expiries
     */
    private function times(array $claimSets, int $position, int $now, CheckLog $log, array &$expiries): void
    {
        foreach ($claimSets as $claims) {
            if (array_key_exists('exp', $claims)) {
                $exp = $claims['exp'];
                if (!is_int($exp) && !is_float($exp)) {
                    $log->fail(CheckType::Lifetime, sprintf('Token %d has an exp that is not a number.', $position));
                } elseif ($now > $exp + self::CLOCK_SKEW) {
                    $log->fail(CheckType::Lifetime, sprintf('Token %d expired on %s UTC.', $position, gmdate('j M Y, H:i', (int)$exp)));
                } else {
                    $expiries[] = (int)$exp;
                }
            }
            if (array_key_exists('iat', $claims)) {
                $iat = $claims['iat'];
                if (!is_int($iat) && !is_float($iat)) {
                    $log->fail(CheckType::Lifetime, sprintf('Token %d has an iat that is not a number.', $position));
                } elseif ($iat > $now + self::CLOCK_SKEW) {
                    $log->fail(CheckType::Lifetime, sprintf('Token %d claims to be issued in the future.', $position));
                }
            }
        }
    }
}
