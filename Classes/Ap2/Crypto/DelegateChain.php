<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

/**
 * A delegation chain of SD-JWTs (draft-gco-oauth-delegate-sd-jwt, as the AP2
 * SDK serialises it): the root SD-JWT the Trusted Surface signed, then each
 * KB-SD-JWT signed with the key the previous token names in `cnf`.
 *
 *     <root JWT>~<d1>~<d2>~~<KB-SD-JWT>~<d3>~
 *
 * Tokens are joined by `~~`: the tilde that ends one SD-JWT plus one empty
 * component. The last token keeps its trailing tilde — AP2 never appends a
 * separate KB-JWT, the closing KB-SD-JWT already is one — so a trailing
 * key-binding JWT is refused. A human-present mandate is a chain of one.
 */
final readonly class DelegateChain
{
    /** AP2 v0.2 flows use one or two tokens; deeper delegation is refused. */
    public const int MAX_TOKENS = 4;

    /**
     * @param non-empty-list<SdJwt> $tokens root first
     */
    public function __construct(
        public array $tokens,
    ) {}

    /**
     * @throws CryptoException when the string is not a chain of ES256 SD-JWTs
     */
    public static function parse(string $serialized): self
    {
        $serialized = trim($serialized);
        if ($serialized === '' || strlen($serialized) > Json::MAX_BYTES) {
            throw new CryptoException('The mandate is empty or too large.', 1758700261);
        }
        $segments = explode('~~', $serialized);
        if (count($segments) > self::MAX_TOKENS) {
            throw new CryptoException(sprintf('The chain has more than %d tokens.', self::MAX_TOKENS), 1758700262);
        }
        $last = count($segments) - 1;
        $tokens = [];
        foreach ($segments as $index => $segment) {
            $canonical = self::canonical($segment, $index === $last);
            $token = SdJwt::parse($canonical);
            if ($token->keyBindingJwt !== null) {
                throw new CryptoException('AP2 chains end with a tilde; a separate key-binding JWT is not part of them.', 1758700263);
            }
            $tokens[] = $token;
        }
        return new self($tokens);
    }

    public static function of(SdJwt $root): self
    {
        return new self([$root]);
    }

    public function append(SdJwt $token): self
    {
        return new self([...$this->tokens, $token]);
    }

    public function root(): SdJwt
    {
        return $this->tokens[0];
    }

    public function leaf(): SdJwt
    {
        return $this->tokens[count($this->tokens) - 1];
    }

    public function count(): int
    {
        return count($this->tokens);
    }

    /**
     * The receipt reference of the chain: the digest of its closing token
     * (see {@see SdJwt::reference()}).
     */
    public function reference(): string
    {
        return $this->leaf()->reference();
    }

    public function serialize(): string
    {
        $parts = [];
        $last = count($this->tokens) - 1;
        foreach ($this->tokens as $index => $token) {
            $serialized = $token->withoutKeyBinding();
            $parts[] = $index === $last ? $serialized : substr($serialized, 0, -1);
        }
        return implode('~~', $parts);
    }

    /**
     * Put back the tilde the `~~` join swallowed (the SDK's
     * `_canonical_chain_segment`).
     */
    private static function canonical(string $segment, bool $isLast): string
    {
        if ($segment === '') {
            throw new CryptoException('The chain has an empty token.', 1758700264);
        }
        if ($isLast || str_ends_with($segment, '~')) {
            return str_contains($segment, '~') ? $segment : $segment . '~';
        }
        return $segment . '~';
    }
}
