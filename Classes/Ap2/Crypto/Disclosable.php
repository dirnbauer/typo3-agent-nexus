<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Crypto;

/**
 * Marks a claim, or an array element, as selectively disclosable when an
 * SD-JWT is issued: {@see SdJwtIssuer} replaces it with a digest and hands the
 * value out as a disclosure the holder may reveal or withhold.
 *
 *     ['checkout_jwt' => new Disclosable($jwt)]           // object property
 *     ['allowed' => [new Disclosable($merchant), …]]      // array element
 */
final readonly class Disclosable
{
    public function __construct(
        public mixed $value,
    ) {}
}
