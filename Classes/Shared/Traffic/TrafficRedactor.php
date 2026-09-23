<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Traffic;

/**
 * Decides what of an exchange may be written to the traffic log.
 *
 * Three rules, applied to everything the recorder stores:
 *
 *  - Headers are allow-listed. Only headers that carry protocol meaning are
 *    kept; cookies, authorisation and anything unknown never reach the table.
 *  - Personal data is masked. The demos ask visitors for names, email
 *    addresses and phone numbers (AG-UI leads, UCP buyers); with redaction on,
 *    every JSON value under a key that names such data is replaced before it is
 *    stored. The protocol structure stays readable, the person does not.
 *  - Bodies are capped, so one runaway exchange cannot fill the table.
 */
final class TrafficRedactor
{
    public const int MAX_BODY_BYTES = 65536;
    public const string MASK = '[redacted]';

    /** Request and response headers worth keeping, lower-case. */
    private const array HEADERS = [
        'accept',
        'content-type',
        'cache-control',
        'location',
        'retry-after',
        'user-agent',
        'origin',
        'a2a-version',
        'a2a-extensions',
        'x-a2a-extensions',
        'ucp-agent',
        'idempotency-key',
        'request-id',
        'mcp-protocol-version',
        'x-agent-nexus-version',
    ];

    /** JSON keys whose values are personal data (matched case-insensitively). */
    private const string PERSONAL_KEYS = '/^(e-?mail(_address)?|phone(_number)?|telephone|mobile|first_?name|last_?name|full_?name|given_?name|family_?name|payer_(name|email|phone)|address(_line[s]?)?|street(_address)?|postal_code|zip|contact|buyer_name|recipient)$/i';

    /**
     * @param array<array-key, array<string>|string> $headers as PSR-7 getHeaders() returns them
     * @return array<string, string>
     */
    public function headers(array $headers): array
    {
        $kept = [];
        foreach ($headers as $name => $value) {
            $lower = strtolower((string)$name);
            if (!in_array($lower, self::HEADERS, true)) {
                continue;
            }
            $kept[$lower] = mb_substr(is_array($value) ? implode(', ', $value) : $value, 0, 512);
        }
        ksort($kept);
        return $kept;
    }

    /**
     * Mask personal data in a body and cap its size. Non-JSON bodies are only
     * capped.
     */
    public function body(string $body, bool $redact): string
    {
        if ($body === '') {
            return '';
        }
        if ($redact) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $body = (string)json_encode($this->value($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        return $this->cap($body);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function data(array $data, bool $redact): array
    {
        return $redact ? $this->value($data) : $data;
    }

    public function cap(string $text, int $bytes = self::MAX_BODY_BYTES): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }
        return mb_strcut($text, 0, $bytes) . "\n[truncated]";
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function value(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PERSONAL_KEYS, $key) === 1 && $value !== null && $value !== '' && $value !== []) {
                $data[$key] = self::MASK;
            } elseif (is_array($value)) {
                $data[$key] = $this->value($value);
            } elseif (is_string($value)) {
                $data[$key] = $this->embedded($value);
            }
        }
        return $data;
    }

    /**
     * Protocols carry JSON inside strings — AG-UI tool-call arguments and
     * results, A2A data parts serialised by a client — so a string that is a
     * JSON object or array is masked the same way and written back.
     */
    private function embedded(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return $value;
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return $value;
        }
        $masked = $this->value($decoded);
        if ($masked === $decoded) {
            return $value;
        }
        return (string)json_encode($masked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
