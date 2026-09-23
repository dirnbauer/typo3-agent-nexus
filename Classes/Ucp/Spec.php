<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp;

/**
 * The fixed vocabulary of UCP 2026-08-25 this installation implements: the
 * protocol version, the names it registers under and where the specification
 * documents them.
 *
 * Every `dev.ucp.*` entry a business publishes must carry the version of the
 * profile it sits in, so the version appears here once and everything that
 * writes one reads it from here.
 */
final class Spec
{
    public const string VERSION = '2026-08-25';

    public const string SERVICE_SHOPPING = 'dev.ucp.shopping';
    public const string CAPABILITY_CHECKOUT = 'dev.ucp.shopping.checkout';

    public const string SPEC_OVERVIEW = 'https://ucp.dev/2026-08-25/specification/overview';
    public const string SPEC_CHECKOUT = 'https://ucp.dev/2026-08-25/specification/shopping/checkout';
    public const string SCHEMA_REST = 'https://ucp.dev/2026-08-25/services/shopping/rest.openapi.json';
    public const string SCHEMA_CHECKOUT = 'https://ucp.dev/2026-08-25/schemas/shopping/checkout.json';

    /** Where the REST binding lives below the API base path. */
    public const string REST_PATH = '/ucp';

    public const string STATUS_INCOMPLETE = 'incomplete';
    public const string STATUS_REQUIRES_ESCALATION = 'requires_escalation';
    public const string STATUS_READY = 'ready_for_complete';
    public const string STATUS_IN_PROGRESS = 'complete_in_progress';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_CANCELED = 'canceled';

    /** A completed or canceled checkout never changes again. */
    public const array TERMINAL_STATUSES = [self::STATUS_COMPLETED, self::STATUS_CANCELED];

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATUSES, true);
    }
}
