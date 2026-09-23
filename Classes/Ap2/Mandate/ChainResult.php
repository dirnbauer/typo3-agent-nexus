<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The outcome of verifying a delegate SD-JWT chain: the checks, and the
 * mandate each token discloses (open first). `readable` is false when a token
 * could not be processed at all, so there is nothing to evaluate further.
 */
#[Exclude]
final readonly class ChainResult
{
    /**
     * @param list<Check> $checks
     * @param list<array<string, mixed>> $mandates
     */
    public function __construct(
        public array $checks,
        public array $mandates,
        public bool $readable,
    ) {}
}
