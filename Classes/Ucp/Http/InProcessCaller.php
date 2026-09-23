<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Http;

use Webconsulting\AgentNexus\Shared\Traffic\Channel;

/**
 * Marks a request the shopping agent dispatched to the REST binding inside
 * the same PHP process.
 *
 * It travels as a request attribute, which a caller on the network cannot
 * set. The endpoint uses it to file the checkout under the agent's
 * conversation and the widget's storage page, and to leave the agent's calls
 * to the agent's own rate limit.
 */
final readonly class InProcessCaller
{
    public const string ATTRIBUTE = 'agentnexus.ucp.caller';

    public function __construct(
        public Channel $channel = Channel::Agent,
        public int $pid = 0,
        public string $contextId = '',
    ) {}
}
