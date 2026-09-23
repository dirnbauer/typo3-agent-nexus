<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

/**
 * The idempotency records could not be read or written. The endpoint answers
 * 503 rather than executing a request it cannot protect against a retry.
 */
final class IdempotencyUnavailable extends \RuntimeException {}
