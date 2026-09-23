<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The one place a payment authorisation is checked before a checkout completes.
 *
 * UCP requires that a checkout is finalised by the buyer through a trusted,
 * deterministic UI unless the AP2 Mandates extension is negotiated. The
 * sandbox relies on the platform's own approval step (the shopping agent stops
 * and asks the visitor) and registers no guard. A guard that verifies an AP2
 * checkout mandate (`ap2.checkout_mandate` in the complete request) plugs in
 * here: implement this interface and the service is picked up by its tag — no
 * other registration is needed.
 *
 * {@see CheckoutService::complete()} calls every guard after the request and
 * the payment instrument have been validated and before the sandbox charges
 * anything; the first refusal ends the attempt and leaves the checkout as it
 * was.
 */
#[AutoconfigureTag(self::TAG)]
interface CompletionGuard
{
    public const string TAG = 'agentnexus.ucp.completion_guard';

    /**
     * @param array<string, mixed> $checkout the session about to complete (status ready_for_complete), without the `ucp` envelope's runtime additions
     * @param array<string, mixed> $request  the complete request body: payment, signals, attribution and, with AP2, `ap2.checkout_mandate`
     * @return array<string, mixed>|null a UCP error message (e.g. code `mandate_required`, `mandate_invalid_signature`) to refuse, null to allow
     */
    public function check(array $checkout, array $request): ?array;
}
