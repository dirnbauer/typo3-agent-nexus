<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * Timestamps as A2A writes them: ISO 8601 in UTC with milliseconds and a `Z`
 * (`2026-09-23T10:00:02.000Z`, specification section 5.6.1).
 */
final class Timestamp
{
    private const string FORMAT = 'Y-m-d\TH:i:s.v\Z';

    public static function now(): string
    {
        return self::format(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    public static function format(\DateTimeInterface $time): string
    {
        return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format(self::FORMAT);
    }

    /**
     * Read an ISO 8601 date-time as a client sends it: with or without
     * fractional seconds, with `Z` or an offset. Null when it is not one.
     */
    public static function parse(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/i', $value, $matches) !== 1) {
            return null;
        }
        // PHP reads at most microseconds; drop what is finer.
        if ($matches[1] !== '') {
            $value = str_replace($matches[1], substr($matches[1], 0, 7), $value);
        }
        try {
            return new \DateTimeImmutable(strtoupper($value));
        } catch (\Exception) {
            return null;
        }
    }
}
