<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;
use Webconsulting\AgentNexus\Ucp\Http\InProcessCaller;

/**
 * Where one run of the shopping agent happens: the host it serves, the
 * platform profile it names in UCP-Agent, how its checkout calls are filed
 * and whether a language model may write its explanation.
 */
final readonly class AgentSession
{
    public function __construct(
        public string $origin,
        public string $apiBaseUrl,
        public string $platformProfileUrl,
        public InProcessCaller $caller,
        public ?TrafficCapture $capture = null,
        public bool $modelAllowed = false,
        public bool $redactPersonalData = true,
        public ?ServerRequestInterface $request = null,
    ) {}
}
