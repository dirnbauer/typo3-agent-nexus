<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

/**
 * Bookkeeping while {@see SdJwtProcessor} walks a payload: which disclosures
 * exist, which were used, which digests were seen.
 *
 * @internal
 */
final class ProcessingState
{
    /** @var array<string, true> */
    public private(set) array $used = [];

    /** @var array<string, true> */
    private array $seen = [];

    /**
     * @param array<string, Disclosure> $byDigest
     */
    public function __construct(
        private readonly array $byDigest,
    ) {}

    /**
     * The disclosure for a digest met in the payload, or null when the holder
     * did not disclose it (or it is a decoy). A digest met twice is an error.
     */
    public function take(string $digest): ?Disclosure
    {
        if (isset($this->seen[$digest])) {
            throw new CryptoException('A digest appears more than once in the SD-JWT.', 1758700251);
        }
        $this->seen[$digest] = true;
        $disclosure = $this->byDigest[$digest] ?? null;
        if ($disclosure !== null) {
            $this->used[$digest] = true;
        }
        return $disclosure;
    }
}
