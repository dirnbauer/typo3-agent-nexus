<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

/**
 * Every check an AP2 verifier here runs, with the plain label people see and
 * the error code a failure turns into.
 *
 * The labels are English because the API and the frontend widget speak
 * English; the backend translates them by the check id
 * (`check.<id>` in locallang_ap2.xlf).
 */
enum CheckType: string
{
    // The delegate SD-JWT chain (draft-gco-oauth-delegate-sd-jwt, AP2 agent_authorization.md).
    case Format = 'format';
    case RootSignature = 'root_signature';
    case AgentSignature = 'agent_signature';
    case Binding = 'binding';
    case Audience = 'audience';
    case SingleMandate = 'single_mandate';
    case Lifetime = 'lifetime';
    // The mandate content.
    case MandateType = 'mandate_type';
    case Content = 'content';
    case PresetValues = 'preset_values';
    // Merchant: the checkout it signed.
    case MerchantCheckout = 'merchant_checkout';
    case CheckoutHash = 'checkout_hash';
    // Credential provider and payment processor: the checkout it pays for.
    case Transaction = 'transaction';
    // Constraints of the open mandates.
    case AllowedMerchant = 'allowed_merchant';
    case LineItems = 'line_items';
    case PaymentReference = 'payment_reference';
    case SpendingCap = 'spending_cap';
    case AllowedPayee = 'allowed_payee';
    case PaymentMethod = 'payment_method';
    case PaymentProvider = 'payment_provider';
    case Budget = 'budget';
    case Recurrence = 'recurrence';
    case ExecutionDate = 'execution_date';
    case UnknownConstraint = 'unknown_constraint';
    // Replay protection, which the AP2 SDK leaves to the verifier.
    case SingleUse = 'single_use';
    // Plain JWTs: receipts and checkout JWTs pasted into the studio.
    case Signature = 'signature';
    case ReceiptReference = 'receipt_reference';

    public function label(): string
    {
        return match ($this) {
            self::Format => 'Well-formed mandate',
            self::RootSignature => 'Signed by the Trusted Surface',
            self::AgentSignature => "Closed with the agent's key",
            self::Binding => 'Bound to the approved mandate',
            self::Audience => 'Meant for this verifier',
            self::SingleMandate => 'One mandate per token',
            self::Lifetime => 'Not expired',
            self::MandateType => 'Expected mandate type',
            self::Content => 'Complete mandate content',
            self::PresetValues => 'Approved values unchanged',
            self::MerchantCheckout => "The merchant's own checkout",
            self::CheckoutHash => 'Checkout hash matches',
            self::Transaction => 'Paid for this checkout',
            self::AllowedMerchant => 'Same approved merchant',
            self::LineItems => 'Only approved items',
            self::PaymentReference => 'Linked to the approved checkout',
            self::SpendingCap => 'Within the spending cap',
            self::AllowedPayee => 'Paid to an approved merchant',
            self::PaymentMethod => 'Approved payment method',
            self::PaymentProvider => 'Approved payment provider',
            self::Budget => 'Within the budget',
            self::Recurrence => 'Repeat use allowed',
            self::ExecutionDate => 'Within the payment dates',
            self::UnknownConstraint => 'Every constraint understood',
            self::SingleUse => 'Not used before',
            self::Signature => 'Valid signature',
            self::ReceiptReference => 'Refers to a known mandate',
        };
    }

    public function failure(): ErrorCode
    {
        return match ($this) {
            self::Format, self::RootSignature, self::AgentSignature, self::Binding, self::Audience,
            self::SingleMandate, self::Lifetime, self::Content, self::Signature => ErrorCode::InvalidCredential,
            self::MandateType, self::PresetValues, self::MerchantCheckout, self::CheckoutHash,
            self::Transaction, self::SingleUse, self::ReceiptReference => ErrorCode::InvalidMandate,
            self::AllowedMerchant, self::LineItems, self::PaymentReference, self::SpendingCap, self::AllowedPayee,
            self::PaymentMethod, self::PaymentProvider, self::Budget, self::Recurrence, self::ExecutionDate,
            self::UnknownConstraint => ErrorCode::UnresolvedConstraint,
        };
    }
}
