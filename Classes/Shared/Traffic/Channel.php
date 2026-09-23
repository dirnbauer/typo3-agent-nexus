<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Traffic;

/**
 * Who was on the other end of an exchange.
 */
enum Channel: string
{
    /** A spec binding another agent can call: JSON-RPC, REST, a discovery document. */
    case Api = 'api';
    /** A frontend widget on a page talking to its own endpoint. */
    case Widget = 'widget';
    /** A backend console, authenticated as a backend user. */
    case Backend = 'backend';
    /** A call one of the demo agents made on a visitor's behalf, in-process. */
    case Agent = 'agent';
}
