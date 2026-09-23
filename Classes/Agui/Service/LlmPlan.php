<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

/**
 * Permission for one run to stream its answer from the live model, with the
 * content element's own limits. Only the site assistant's endpoint creates
 * one, after the LLM guard, the element's settings and the model rate limit
 * all agreed.
 */
final readonly class LlmPlan
{
    /**
     * @param string $systemPrompt the element's system prompt; empty for the built-in one
     * @param int $maxTokens output ceiling, already capped by the guard
     */
    public function __construct(
        public string $systemPrompt,
        public int $maxTokens,
    ) {}
}
