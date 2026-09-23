<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

/**
 * The constraint types AP2 v0.2 defines for open mandates.
 *
 * A verifier MUST treat a constraint it does not know as failing, so every
 * type here is evaluated ({@see ConstraintEvaluator}); anything else fails
 * with `unresolved_constraint`.
 */
enum ConstraintType: string
{
    case AllowedMerchants = 'checkout.allowed_merchants';
    case LineItems = 'checkout.line_items';
    case AmountRange = 'payment.amount_range';
    case AllowedPayees = 'payment.allowed_payees';
    case AllowedPaymentInstruments = 'payment.allowed_payment_instruments';
    case AllowedPisps = 'payment.allowed_pisps';
    case Budget = 'payment.budget';
    case AgentRecurrence = 'payment.agent_recurrence';
    case ExecutionDate = 'payment.execution_date';
    case Reference = 'payment.reference';

    /** The open mandate type the constraint belongs to. */
    public function mandate(): MandateType
    {
        return str_starts_with($this->value, 'checkout.') ? MandateType::OpenCheckout : MandateType::OpenPayment;
    }

    /** Key for labels: the type with dots and underscores turned into dashes. */
    public function key(): string
    {
        return str_replace(['.', '_'], '-', $this->value);
    }

    /** The check that reports this constraint's evaluation. */
    public function check(): CheckType
    {
        return match ($this) {
            self::AllowedMerchants => CheckType::AllowedMerchant,
            self::LineItems => CheckType::LineItems,
            self::AmountRange => CheckType::SpendingCap,
            self::AllowedPayees => CheckType::AllowedPayee,
            self::AllowedPaymentInstruments => CheckType::PaymentMethod,
            self::AllowedPisps => CheckType::PaymentProvider,
            self::Budget => CheckType::Budget,
            self::AgentRecurrence => CheckType::Recurrence,
            self::ExecutionDate => CheckType::ExecutionDate,
            self::Reference => CheckType::PaymentReference,
        };
    }

    /**
     * @return list<self>
     */
    public static function forMandate(MandateType $type): array
    {
        return array_values(array_filter(self::cases(), static fn(self $constraint): bool => $constraint->mandate() === $type->open()));
    }
}
