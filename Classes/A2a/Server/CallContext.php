<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Server;

use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;

/**
 * Who is calling and what they may use, worked out once per request.
 *
 * Only the concierge widget can switch the model on — and only when its
 * content element allows it, the LLM guard agrees and the model budget has
 * room. Every other client gets the scripted skills; nothing about the model
 * is ever taken from the request.
 */
final readonly class CallContext
{
    public function __construct(
        public Channel $channel = Channel::Api,
        public int $pid = 0,
        public bool $useModel = false,
        public bool $showRationale = true,
        public int $maxOutputTokens = 400,
        public ?TrafficCapture $capture = null,
    ) {}

    /** Link the traffic log entry of this request to a task. */
    public function correlate(string $taskId): void
    {
        $this->capture?->correlate($taskId);
    }
}
