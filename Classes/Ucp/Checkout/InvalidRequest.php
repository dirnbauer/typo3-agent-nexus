<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

/**
 * A request body the REST binding cannot even read as a checkout request: not
 * JSON, not an object, a line item without an item id, a quantity that is not
 * a whole number. The endpoint answers 400 with the message.
 *
 * Requests that are well formed but cannot be served — an item the store does
 * not sell, a missing email address — are business outcomes instead, answered
 * with UCP messages.
 */
final class InvalidRequest extends \RuntimeException {}
