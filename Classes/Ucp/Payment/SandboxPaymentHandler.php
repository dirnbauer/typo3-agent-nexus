<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Payment;

use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Ucp\Checkout\Messages;

/**
 * The store's only payment handler, and it never moves money.
 *
 * UCP defines no concrete handler — every business names its own under a
 * reverse-domain name it controls, and `dev.ucp.*` is reserved. This one is
 * `at.webconsulting.sandbox_pay`, instance id `sandbox_pay`. An instrument
 * references it by `handler_id` and carries a token credential; the token
 * decides the outcome:
 *
 *   sandbox-success   the payment is accepted and the checkout completes
 *   sandbox-decline   the payment fails with `payment_failed`
 *
 * The profile declares no `spec` or `schema` URL for it: a declared schema
 * would have to live on webconsulting.at, which hosts none.
 */
final class SandboxPaymentHandler implements SingletonInterface
{
    public const string NAME = 'at.webconsulting.sandbox_pay';
    public const string ID = 'sandbox_pay';
    public const string VERSION = '2026-09-23';
    public const string INSTRUMENT_TYPE = 'sandbox';
    public const string CREDENTIAL_TYPE = 'token';
    public const string TOKEN_SUCCESS = 'sandbox-success';
    public const string TOKEN_DECLINE = 'sandbox-decline';

    /**
     * The registry entry for `ucp.payment_handlers`, keyed by handler name.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function declaration(): array
    {
        return [
            self::NAME => [[
                'id' => self::ID,
                'version' => self::VERSION,
                'config' => ['environment' => 'sandbox'],
                'available_instruments' => [['type' => self::INSTRUMENT_TYPE]],
            ]],
        ];
    }

    /**
     * An instrument for this handler, as a platform would submit it.
     *
     * @return array{id: string, handler_id: string, type: string, credential: array{type: string, token: string}}
     */
    public function instrument(string $instrumentId, bool $decline = false): array
    {
        return [
            'id' => $instrumentId,
            'handler_id' => self::ID,
            'type' => self::INSTRUMENT_TYPE,
            'credential' => [
                'type' => self::CREDENTIAL_TYPE,
                'token' => $decline ? self::TOKEN_DECLINE : self::TOKEN_SUCCESS,
            ],
        ];
    }

    /**
     * Process the one instrument of a complete request. Returns null when the
     * sandbox accepts it, otherwise the UCP error message to answer with.
     *
     * @param array<array-key, mixed> $instrument
     * @return array<string, mixed>|null
     */
    public function charge(array $instrument, string $path): ?array
    {
        if (($instrument['handler_id'] ?? null) !== self::ID) {
            return Messages::error('payment_failed', 'This store accepts only its sandbox payment handler, "sandbox_pay".', Messages::SEVERITY_RECOVERABLE, $path . '.handler_id');
        }
        if (($instrument['type'] ?? null) !== self::INSTRUMENT_TYPE) {
            return Messages::error('payment_failed', 'The sandbox payment handler takes instruments of type "sandbox".', Messages::SEVERITY_RECOVERABLE, $path . '.type');
        }
        $credential = $instrument['credential'] ?? null;
        if (!is_array($credential) || ($credential['type'] ?? null) !== self::CREDENTIAL_TYPE || !is_string($credential['token'] ?? null)) {
            return Messages::error('payment_failed', 'The instrument needs a token credential.', Messages::SEVERITY_RECOVERABLE, $path . '.credential');
        }
        return match ($credential['token']) {
            self::TOKEN_SUCCESS => null,
            self::TOKEN_DECLINE => Messages::error('payment_failed', 'The sandbox declined the payment. Nothing was charged.', Messages::SEVERITY_RECOVERABLE, $path),
            default => Messages::error('payment_failed', 'Unknown sandbox token. Use "sandbox-success" or "sandbox-decline".', Messages::SEVERITY_RECOVERABLE, $path . '.credential'),
        };
    }

    /**
     * The instrument as the business may repeat it: the credential never
     * leaves the request it arrived in.
     *
     * @param array<array-key, mixed> $instrument
     * @return array{id: string, handler_id: string, type: string, selected: true}
     */
    public function receipt(array $instrument): array
    {
        return [
            'id' => is_string($instrument['id'] ?? null) ? $instrument['id'] : '',
            'handler_id' => self::ID,
            'type' => self::INSTRUMENT_TYPE,
            'selected' => true,
        ];
    }
}
