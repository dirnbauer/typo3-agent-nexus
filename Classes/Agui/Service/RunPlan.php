<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use Webconsulting\AgentNexus\Agui\Agent\Audience;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;

/**
 * How an endpoint wants a run carried out: for which audience, which task it
 * asks for, who called and where the run record belongs.
 */
final readonly class RunPlan
{
    /**
     * @param string $preset the task the caller asked for; unknown tasks fall back to the audience's default
     * @param string $scriptedReason why a site run stays scripted, shown as provenance ("daily LLM budget reached")
     * @param int $delayMs pacing between streamed events, so a scripted run is visible as a stream
     */
    public function __construct(
        public Audience $audience,
        public string $preset,
        public Channel $channel,
        public int $pid = 0,
        public int $beUser = 0,
        public ?LlmPlan $llm = null,
        public string $scriptedReason = '',
        public int $delayMs = 0,
    ) {}
}
