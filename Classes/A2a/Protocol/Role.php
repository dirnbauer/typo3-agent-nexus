<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * Who sent a message. A2A 0.3 spelt these `user` and `agent`.
 */
enum Role: string
{
    case User = 'ROLE_USER';
    case Agent = 'ROLE_AGENT';

    public function legacyValue(): string
    {
        return $this === self::User ? 'user' : 'agent';
    }

    public static function fromLegacy(string $value): ?self
    {
        return match ($value) {
            'user' => self::User,
            'agent' => self::Agent,
            default => null,
        };
    }
}
