<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Service;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Webconsulting\AgentNexus\Ap2\Mandate\Check;
use Webconsulting\AgentNexus\Ap2\Mandate\Verdict;

/**
 * The answer to a UCP `complete` request that carries AP2 mandates: valid, or
 * the UCP error code (dev.ucp.common.payment.ap2_mandate) with a message, and
 * the AP2 checks behind it.
 */
#[Exclude]
final readonly class UcpVerdict
{
    public const string MANDATE_REQUIRED = 'mandate_required';
    public const string AGENT_MISSING_KEY = 'agent_missing_key';
    public const string MANDATE_INVALID_SIGNATURE = 'mandate_invalid_signature';
    public const string MANDATE_EXPIRED = 'mandate_expired';
    public const string MANDATE_SCOPE_MISMATCH = 'mandate_scope_mismatch';
    public const string MERCHANT_AUTHORIZATION_INVALID = 'merchant_authorization_invalid';
    public const string MERCHANT_AUTHORIZATION_MISSING = 'merchant_authorization_missing';

    /** Every code, with the description the UCP extension gives it. */
    public const array CODES = [
        self::MANDATE_REQUIRED => 'AP2 was negotiated, but the request lacks ap2.checkout_mandate.',
        self::AGENT_MISSING_KEY => 'Platform profile lacks a valid keys entry.',
        self::MANDATE_INVALID_SIGNATURE => 'The mandate signature cannot be verified.',
        self::MANDATE_EXPIRED => 'The mandate exp timestamp has passed.',
        self::MANDATE_SCOPE_MISMATCH => 'The mandate is bound to a different checkout.',
        self::MERCHANT_AUTHORIZATION_INVALID => 'The business authorization signature could not be verified.',
        self::MERCHANT_AUTHORIZATION_MISSING => 'The checkout response omits ap2.merchant_authorization.',
    ];

    /**
     * @param list<Check> $checks
     */
    public function __construct(
        public bool $valid,
        public ?string $code,
        public string $message,
        public array $checks = [],
        public ?Verdict $checkout = null,
        public ?Verdict $payment = null,
    ) {}

    public static function failure(string $code, string $message, ?Verdict $checkout = null, ?Verdict $payment = null): self
    {
        $checks = [...($checkout->checks ?? []), ...($payment->checks ?? [])];
        return new self(false, $code, $message, $checks, $checkout, $payment);
    }

    /**
     * A UCP error message object (types/message_error.json) for the checkout's
     * `messages`.
     *
     * @return array{type: string, code: string, content: string, severity: string}|null
     */
    public function message(): ?array
    {
        if ($this->valid || $this->code === null) {
            return null;
        }
        return [
            'type' => 'error',
            'code' => $this->code,
            'content' => $this->message,
            'severity' => $this->code === self::MANDATE_REQUIRED ? 'requires_buyer_input' : 'unrecoverable',
        ];
    }
}
