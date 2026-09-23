<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Llm;

/**
 * What the protocols need from a language model — and nothing more.
 *
 * Every protocol treats the model as optional: it asks {@see isAvailable()}
 * first and falls back to its deterministic script on any failure. Keeping that
 * contract behind an interface is what lets the protocol services be tested
 * without a provider, and keeps netresearch/nr-llm a soft dependency: the only
 * implementation that touches it is {@see LlmClient}.
 */
interface LanguageModel
{
    public function isAvailable(): bool;

    /**
     * Provider, model and pricing of the default model, or null when no model
     * is configured.
     *
     * @return array{provider: string, adapter: string, endpoint: string, model: string, modelId: string, priceInput: string, priceOutput: string, hasPricing: bool}|null
     */
    public function getConnectionInfo(): ?array;

    /**
     * @return array{data: array<string, mixed>, promptTokens: int, completionTokens: int, cost: ?float, model: string}
     * @throws \RuntimeException when no model is available
     * @throws TruncatedAnswer when the answer stopped at the output budget (finish reason "length")
     * @throws \JsonException when the answer is not a JSON object
     */
    public function completeJson(string $systemPrompt, string $userPrompt, ?string $model = null, ?int $maxTokens = null): array;

    /**
     * @return array{text: string, promptTokens: int, completionTokens: int, cost: ?float, model: string}
     * @throws \RuntimeException when no model is available
     * @throws TruncatedAnswer when the answer stopped at the output budget (finish reason "length")
     */
    public function completeText(string $systemPrompt, string $userPrompt, ?string $model = null, ?int $maxTokens = null): array;

    /**
     * Stream an answer chunk by chunk. Streamed answers carry no usage figures
     * and no finish reason, so the caller records their usage through
     * {@see UsageLedger} and judges for itself whether the answer is complete.
     *
     * @return \Generator<int, string>
     * @throws \RuntimeException when no model is available
     */
    public function streamText(string $systemPrompt, string $userPrompt, ?int $maxTokens = null): \Generator;

    /** Cost of a call from its token counts, or null when the model has no pricing. */
    public function estimateCost(int $promptTokens, int $completionTokens): ?float;

    /** Rough token count for text a streamed answer did not report usage for. */
    public function estimateTokens(string $text): int;

    /** Instance-wide model spend in a date range (every consumer), or null when unknown. */
    public function getInstanceCost(\DateTimeInterface $from, \DateTimeInterface $to): ?float;
}
