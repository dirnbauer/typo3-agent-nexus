<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared;

/**
 * The five protocols Agent Nexus implements.
 *
 * The backed value is the key used everywhere a protocol is named in storage,
 * URLs, CSS accent scopes and icon identifiers (agentnexus-module-<value>), so
 * it must never change.
 */
enum Protocol: string
{
    case A2ui = 'a2ui';
    case Agui = 'agui';
    case A2a = 'a2a';
    case Ucp = 'ucp';
    case Ap2 = 'ap2';

    /** The name as the specifications spell it. */
    public function label(): string
    {
        return match ($this) {
            self::A2ui => 'A2UI',
            self::Agui => 'AG-UI',
            self::A2a => 'A2A',
            self::Ucp => 'UCP',
            self::Ap2 => 'AP2',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $protocol): string => $protocol->value, self::cases());
    }
}
