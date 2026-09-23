<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

/**
 * One SD-JWT disclosure (RFC 9901 section 4.2): the base64url encoding of
 * `[salt, name, value]` for an object property or `[salt, value]` for an array
 * element, and its digest.
 *
 * The digest is taken over the encoded string exactly as it travels, never
 * over a re-serialisation, so a disclosure keeps the bytes it was received
 * with.
 */
final readonly class Disclosure
{
    /** 128 bits of salt, the minimum RFC 9901 section 9.3 recommends. */
    private const int SALT_BYTES = 16;

    private function __construct(
        public string $encoded,
        public string $digest,
        public string $salt,
        public ?string $name,
        public mixed $value,
    ) {}

    public static function create(mixed $value, ?string $name = null, string $algorithm = Digest::DEFAULT_ALGORITHM): self
    {
        $salt = Base64Url::encode(random_bytes(self::SALT_BYTES));
        $encoded = Base64Url::encode(Json::encode($name === null ? [$salt, $value] : [$salt, $name, $value]));
        return new self($encoded, Digest::of($encoded, $algorithm), $salt, $name, $value);
    }

    /**
     * @throws CryptoException when the string is not a disclosure
     */
    public static function decode(string $encoded, string $algorithm = Digest::DEFAULT_ALGORITHM): self
    {
        $array = Json::decodeArray(Base64Url::decode($encoded));
        $count = count($array);
        if (($count !== 2 && $count !== 3) || !is_string($array[0])) {
            throw new CryptoException('A disclosure is an array of a salt, an optional claim name and a value.', 1758700201);
        }
        if ($count === 3 && !is_string($array[1])) {
            throw new CryptoException('A disclosure names its claim with a string.', 1758700202);
        }
        return new self(
            $encoded,
            Digest::of($encoded, $algorithm),
            $array[0],
            $count === 3 && is_string($array[1]) ? $array[1] : null,
            $array[$count - 1],
        );
    }

    public function isArrayElement(): bool
    {
        return $this->name === null;
    }

    /**
     * @return array{digest: string, salt: string, name?: string, value: mixed}
     */
    public function toArray(): array
    {
        $array = ['digest' => $this->digest, 'salt' => $this->salt];
        if ($this->name !== null) {
            $array['name'] = $this->name;
        }
        $array['value'] = $this->value;
        return $array;
    }
}
