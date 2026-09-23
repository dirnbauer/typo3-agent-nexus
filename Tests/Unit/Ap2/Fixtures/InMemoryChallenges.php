<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Fixtures;

use Webconsulting\AgentNexus\Ap2\Sandbox\Challenges;

final class InMemoryChallenges implements Challenges
{
    /** @var array<string, true> */
    private array $issued = [];

    public function issue(string $audience): string
    {
        $nonce = bin2hex(random_bytes(16));
        $this->issued[$audience . '|' . $nonce] = true;
        return $nonce;
    }

    public function issued(string $audience, string $nonce): bool
    {
        return isset($this->issued[$audience . '|' . $nonce]);
    }
}
