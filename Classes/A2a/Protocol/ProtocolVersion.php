<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * The A2A versions this agent speaks, as `Major.Minor` (patch versions do not
 * take part in negotiation, specification section 3.6).
 *
 * A client names its version in the `A2A-Version` header or query parameter.
 * A missing or empty value means 0.3 — clients written before 1.0 do not send
 * one — and a version this agent does not speak is a VersionNotSupportedError
 * that lists the ones it does.
 */
enum ProtocolVersion: string
{
    case V1_0 = '1.0';
    case V0_3 = '0.3';

    public const string HEADER = 'A2A-Version';

    public static function negotiate(string $requested): self
    {
        $requested = trim($requested);
        if ($requested === '') {
            return self::V0_3;
        }
        if (preg_match('/^(\d+)\.(\d+)(?:\.\d+)?$/', $requested, $matches) === 1) {
            $version = self::tryFrom((int)$matches[1] . '.' . (int)$matches[2]);
            if ($version !== null) {
                return $version;
            }
        }
        throw self::unsupported($requested);
    }

    public static function unsupported(string $requested, ?self $only = null): A2aException
    {
        $supported = $only !== null ? [$only->value] : self::values();
        return new A2aException(
            A2aError::VersionNotSupported,
            sprintf(
                'A2A version "%s" is not supported here. Send the header %s: %s.',
                $requested === '' ? '0.3' : mb_substr($requested, 0, 20),
                self::HEADER,
                implode(' or ', $supported),
            ),
            [
                'requestedVersion' => $requested === '' ? '0.3' : mb_substr($requested, 0, 20),
                'supportedVersions' => implode(', ', $supported),
            ],
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $version): string => $version->value, self::cases());
    }
}
