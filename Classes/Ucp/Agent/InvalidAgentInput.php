<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

/**
 * The request body is not a RunAgentInput the shopping agent can run. The
 * endpoint rejects it before a run starts.
 */
final class InvalidAgentInput extends \RuntimeException {}
