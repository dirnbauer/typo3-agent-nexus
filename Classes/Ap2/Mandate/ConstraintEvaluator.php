<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Webconsulting\AgentNexus\Ap2\Crypto\Json;

/**
 * Evaluates the constraints of an open mandate against what the closed one
 * asks for — deterministic code, as AP2 requires for every verifier.
 *
 * One check per constraint, in the order the mandate lists them. A constraint
 * type this evaluator does not know fails, and so does one that is not about
 * the mandate at hand (a payment constraint in a checkout mandate).
 */
final class ConstraintEvaluator
{
    /**
     * @param array<string, mixed> $openMandate the open checkout mandate, disclosures applied
     * @param array<string, mixed> $checkout the checkout the merchant signed
     * @return list<Check>
     */
    public function checkout(array $openMandate, array $checkout): array
    {
        $checks = [];
        foreach (Json::objects($openMandate['constraints'] ?? null) as $constraint) {
            $type = ConstraintType::tryFrom(Json::string($constraint['type'] ?? null));
            $checks[] = match ($type) {
                ConstraintType::AllowedMerchants => $this->allowedMerchants($constraint, Json::map($checkout['merchant'] ?? null)),
                ConstraintType::LineItems => $this->lineItems($constraint, $checkout),
                default => $this->unknown($constraint, 'checkout'),
            };
        }
        return $checks;
    }

    /**
     * @param array<string, mixed> $openMandate the open payment mandate, disclosures applied
     * @param array<string, mixed> $payment the closed payment mandate
     * @param string|null $openCheckoutHash digest of the open checkout mandate presented with the payment
     * @return list<Check>
     */
    public function payment(array $openMandate, array $payment, ?string $openCheckoutHash, MandateUsage $usage, int $now): array
    {
        $constraints = Json::objects($openMandate['constraints'] ?? null);
        $types = array_map(static fn(array $constraint): string => Json::string($constraint['type'] ?? null), $constraints);
        $amount = Json::map($payment['payment_amount'] ?? null);

        $checks = [];
        foreach ($constraints as $constraint) {
            $type = ConstraintType::tryFrom(Json::string($constraint['type'] ?? null));
            $checks[] = match ($type) {
                ConstraintType::AmountRange => $this->amountRange($constraint, $amount),
                ConstraintType::AllowedPayees => $this->allowedPayees($constraint, Json::map($payment['payee'] ?? null)),
                ConstraintType::AllowedPaymentInstruments => $this->allowedInstruments($constraint, Json::map($payment['payment_instrument'] ?? null)),
                ConstraintType::AllowedPisps => $this->allowedPisps($constraint, Json::map($payment['pisp'] ?? null)),
                ConstraintType::Budget => $this->budget($constraint, $amount, $usage),
                ConstraintType::AgentRecurrence => $this->recurrence(
                    $constraint,
                    $usage,
                    $now,
                    in_array(ConstraintType::AmountRange->value, $types, true) && in_array(ConstraintType::Budget->value, $types, true),
                ),
                ConstraintType::ExecutionDate => $this->executionDate($constraint, $payment['execution_date'] ?? null),
                ConstraintType::Reference => $this->reference($constraint, $openCheckoutHash),
                default => $this->unknown($constraint, 'payment'),
            };
        }
        return $checks;
    }

    /**
     * The SDK's `merchant_matches`: by id when both sides have one, otherwise
     * by name and website, both present.
     *
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $target
     */
    public static function merchantMatches(array $candidate, array $target): bool
    {
        $candidateId = Json::string($candidate['id'] ?? null);
        $targetId = Json::string($target['id'] ?? null);
        if ($candidateId !== '' && $targetId !== '') {
            return $candidateId === $targetId;
        }
        $name = Json::string($candidate['name'] ?? null);
        $website = Json::string($candidate['website'] ?? null);
        return $name !== '' && $website !== ''
            && $name === Json::string($target['name'] ?? null)
            && $website === Json::string($target['website'] ?? null);
    }

    /**
     * @param array<string, mixed> $merchant
     */
    public static function merchantName(array $merchant): string
    {
        $name = Json::string($merchant['name'] ?? null);
        $id = Json::string($merchant['id'] ?? null);
        return match (true) {
            $name !== '' && $id !== '' => $name . ' (' . $id . ')',
            default => $name . $id,
        };
    }

    /**
     * @param array<string, mixed> $constraint
     * @param array<string, mixed> $merchant
     */
    private function allowedMerchants(array $constraint, array $merchant): Check
    {
        $allowed = Json::objects($constraint['allowed'] ?? null);
        if ($allowed === []) {
            return Check::fail(CheckType::AllowedMerchant, 'No approved merchant was disclosed.');
        }
        if ($merchant === []) {
            return Check::fail(CheckType::AllowedMerchant, 'The checkout names no merchant.');
        }
        foreach ($allowed as $candidate) {
            if (self::merchantMatches($candidate, $merchant)) {
                return Check::pass(CheckType::AllowedMerchant, self::merchantName($merchant));
            }
        }
        return Check::fail(CheckType::AllowedMerchant, self::merchantName($merchant) . ' is not an approved merchant.');
    }

    /**
     * @param array<string, mixed> $constraint
     * @param array<string, mixed> $checkout
     */
    private function lineItems(array $constraint, array $checkout): Check
    {
        $requirements = [];
        foreach (Json::objects($constraint['items'] ?? null) as $requirement) {
            $requirements[] = [
                'id' => Json::string($requirement['id'] ?? null),
                'acceptable' => array_values(array_filter(array_map(
                    static fn(array $item): string => Json::string($item['id'] ?? null),
                    Json::objects($requirement['acceptable_items'] ?? null),
                ), static fn(string $id): bool => $id !== '')),
                'quantity' => Json::int($requirement['quantity'] ?? null) ?? 0,
            ];
        }
        $cart = [];
        foreach (Json::objects($checkout['line_items'] ?? null) as $line) {
            $id = Json::string(Json::map($line['item'] ?? null)['id'] ?? null);
            $cart[$id] = ($cart[$id] ?? 0) + max(0, Json::int($line['quantity'] ?? null) ?? 0);
        }

        $violations = LineItemMatcher::violations($cart, $requirements);
        if ($violations !== []) {
            return Check::fail(CheckType::LineItems, implode(' ', $violations));
        }
        $count = array_sum($cart);
        return Check::pass(CheckType::LineItems, sprintf('%d %s on the approved list', $count, $count === 1 ? 'item' : 'items'));
    }

    /**
     * @param array<string, mixed> $constraint
     * @param array<string, mixed> $amount
     */
    private function amountRange(array $constraint, array $amount): Check
    {
        $max = Json::int($constraint['max'] ?? null);
        $min = Json::int($constraint['min'] ?? null);
        $currency = Json::string($constraint['currency'] ?? null);
        $value = Json::int($amount['amount'] ?? null);
        $paidIn = Json::string($amount['currency'] ?? null);
        if ($max === null || $value === null) {
            return Check::fail(CheckType::SpendingCap, 'The cap or the amount is not an integer in minor units.');
        }
        if ($currency !== '' && $currency !== $paidIn) {
            return Check::fail(CheckType::SpendingCap, sprintf('Paid in %s, but the cap is in %s.', $paidIn, $currency));
        }
        if ($value > $max) {
            return Check::fail(CheckType::SpendingCap, Money::format($value, $paidIn) . ' > ' . Money::format($max, $paidIn));
        }
        if ($min !== null && $value < $min) {
            return Check::fail(CheckType::SpendingCap, Money::format($value, $paidIn) . ' < ' . Money::format($min, $paidIn));
        }
        return Check::pass(CheckType::SpendingCap, Money::format($value, $paidIn) . ' ≤ ' . Money::format($max, $paidIn));
    }

    /**
     * @param array<string, mixed> $constraint
     * @param array<string, mixed> $payee
     */
    private function allowedPayees(array $constraint, array $payee): Check
    {
        $allowed = Json::objects($constraint['allowed'] ?? null);
        if ($allowed === []) {
            return Check::fail(CheckType::AllowedPayee, 'No approved payee was disclosed.');
        }
        foreach ($allowed as $candidate) {
            if (self::merchantMatches($candidate, $payee)) {
                return Check::pass(CheckType::AllowedPayee, self::merchantName($payee));
            }
        }
        return Check::fail(CheckType::AllowedPayee, self::merchantName($payee) . ' is not an approved payee.');
    }

    /**
     * @param array<string, mixed> $constraint
     * @param array<string, mixed> $instrument
     */
    private function allowedInstruments(array $constraint, array $instrument): Check
    {
        $id = Json::string($instrument['id'] ?? null);
        if ($id === '') {
            return Check::fail(CheckType::PaymentMethod, 'The payment mandate names no payment method.');
        }
        foreach (Json::objects($constraint['allowed'] ?? null) as $candidate) {
            if (Json::string($candidate['id'] ?? null) === $id) {
                return Check::pass(CheckType::PaymentMethod, Json::string($instrument['description'] ?? null, $id));
            }
        }
        return Check::fail(CheckType::PaymentMethod, sprintf('The payment method "%s" is not approved.', $id));
    }

    /**
     * @param array<string, mixed> $constraint
     * @param array<string, mixed> $pisp
     */
    private function allowedPisps(array $constraint, array $pisp): Check
    {
        if ($pisp === []) {
            return Check::fail(CheckType::PaymentProvider, 'The payment mandate names no payment initiation service provider.');
        }
        foreach (Json::objects($constraint['allowed'] ?? null) as $candidate) {
            $same = true;
            foreach (['legal_name', 'brand_name', 'domain_name'] as $field) {
                $same = $same && Json::string($candidate[$field] ?? null) === Json::string($pisp[$field] ?? null);
            }
            if ($same) {
                return Check::pass(CheckType::PaymentProvider, Json::string($pisp['brand_name'] ?? null));
            }
        }
        return Check::fail(CheckType::PaymentProvider, Json::string($pisp['brand_name'] ?? null) . ' is not an approved provider.');
    }

    /**
     * The budget's `max` has no unit in the schema; like the AP2 SDK, it is
     * read as major units (×100).
     *
     * @param array<string, mixed> $constraint
     * @param array<string, mixed> $amount
     */
    private function budget(array $constraint, array $amount, MandateUsage $usage): Check
    {
        $currency = Json::string($constraint['currency'] ?? null);
        $paidIn = Json::string($amount['currency'] ?? null);
        $max = $constraint['max'] ?? null;
        $value = Json::int($amount['amount'] ?? null);
        if ((!is_int($max) && !is_float($max)) || $value === null) {
            return Check::fail(CheckType::Budget, 'The budget or the amount is not a number.');
        }
        if ($currency !== $paidIn) {
            return Check::fail(CheckType::Budget, sprintf('Paid in %s, but the budget is in %s.', $paidIn, $currency));
        }
        $limit = (int)round($max * 100);
        $total = $usage->amount + $value;
        $detail = sprintf('%s of %s spent', Money::format($total, $paidIn), Money::format($limit, $paidIn));
        return $total <= $limit ? Check::pass(CheckType::Budget, $detail) : Check::fail(CheckType::Budget, $detail);
    }

    /**
     * @param array<string, mixed> $constraint
     */
    private function recurrence(array $constraint, MandateUsage $usage, int $now, bool $bounded): Check
    {
        if (!$bounded) {
            return Check::fail(CheckType::Recurrence, 'Repeat use needs a payment.amount_range and a payment.budget constraint.');
        }
        $max = Json::int($constraint['max_occurrences'] ?? null);
        if ($max !== null && $usage->uses >= $max) {
            return Check::fail(CheckType::Recurrence, sprintf('Used %d of %d times.', $usage->uses, $max));
        }
        $frequency = Json::string($constraint['frequency'] ?? null);
        if (!in_array($frequency, MandateSchema::FREQUENCIES, true)) {
            return Check::fail(CheckType::Recurrence, sprintf('"%s" is not a frequency.', $frequency));
        }
        $periodStart = self::periodStart($frequency, $now);
        if ($usage->lastUse !== null && $periodStart !== null && $usage->lastUse >= $periodStart) {
            return Check::fail(CheckType::Recurrence, sprintf('Already used in this %s period.', strtolower($frequency)));
        }
        return Check::pass(CheckType::Recurrence, sprintf('Use %d%s, %s', $usage->uses + 1, $max === null ? '' : ' of ' . $max, strtolower($frequency)));
    }

    /**
     * @param array<string, mixed> $constraint
     */
    private function executionDate(array $constraint, mixed $executionDate): Check
    {
        if (!is_string($executionDate) || $executionDate === '') {
            return Check::pass(CheckType::ExecutionDate, 'Immediate payment');
        }
        $date = self::date($executionDate);
        if ($date === null) {
            return Check::fail(CheckType::ExecutionDate, sprintf('"%s" is not a date.', $executionDate));
        }
        $notBefore = self::date(Json::string($constraint['not_before'] ?? null));
        $notAfter = self::date(Json::string($constraint['not_after'] ?? null));
        if ($notBefore !== null && $date < $notBefore) {
            return Check::fail(CheckType::ExecutionDate, sprintf('%s is before %s.', $executionDate, Json::string($constraint['not_before'] ?? null)));
        }
        if ($notAfter !== null && $date > $notAfter) {
            return Check::fail(CheckType::ExecutionDate, sprintf('%s is after %s.', $executionDate, Json::string($constraint['not_after'] ?? null)));
        }
        return Check::pass(CheckType::ExecutionDate, $executionDate);
    }

    /**
     * @param array<string, mixed> $constraint
     */
    private function reference(array $constraint, ?string $openCheckoutHash): Check
    {
        $expected = Json::string($constraint['conditional_transaction_id'] ?? null);
        if ($expected === '') {
            return Check::fail(CheckType::PaymentReference, 'The constraint names no checkout mandate.');
        }
        if ($openCheckoutHash === null) {
            return Check::fail(CheckType::PaymentReference, 'The open checkout mandate this payment belongs to was not presented.');
        }
        return hash_equals($expected, $openCheckoutHash)
            ? Check::pass(CheckType::PaymentReference, 'Open checkout mandate ' . substr($expected, 0, 12) . '…')
            : Check::fail(CheckType::PaymentReference, 'The payment belongs to a different checkout mandate.');
    }

    /**
     * @param array<string, mixed> $constraint
     */
    private function unknown(array $constraint, string $mandate): Check
    {
        $type = Json::string($constraint['type'] ?? null);
        return Check::fail(CheckType::UnknownConstraint, ConstraintType::tryFrom($type) !== null
            ? sprintf('"%s" does not apply to a %s mandate.', $type, $mandate)
            : sprintf('"%s" is not a constraint this verifier knows, so it fails.', $type));
    }

    private static function periodStart(string $frequency, int $now): ?int
    {
        $today = (new \DateTimeImmutable('@' . $now))->setTime(0, 0);
        $start = match ($frequency) {
            'DAILY' => $today,
            'WEEKLY' => $today->modify('monday this week'),
            'BIWEEKLY' => $today->modify('-13 days'),
            'MONTHLY' => $today->modify('first day of this month'),
            'QUARTERLY' => $today->setDate((int)$today->format('Y'), 3 * intdiv((int)$today->format('n') - 1, 3) + 1, 1),
            'ANNUALLY' => $today->setDate((int)$today->format('Y'), 1, 1),
            default => null,
        };
        return $start instanceof \DateTimeImmutable ? $start->getTimestamp() : null;
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
