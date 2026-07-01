<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Llm;

use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Thin, optional bridge to netresearch/nr-llm for every Agent Nexus protocol.
 *
 * nr-llm is a *soft* dependency (composer "suggest"), so this adapter never
 * touches its classes unless they exist: {@see isAvailable()} guards every
 * use. When nr-llm is installed and a provider is configured (in this lab:
 * OpenAI keyed via a frontend-accessible nr-vault secret), the protocols get
 * a real model; otherwise every caller falls back to its deterministic demo.
 *
 * Note on streaming: nr-llm's streamChat() bypasses its middleware pipeline
 * (usage, budget, cache), so callers must ledger streamed usage themselves
 * via {@see LlmUsageTracker} — {@see LlmGuard} is the only budget brake on
 * that path.
 */
final class LlmClient implements SingletonInterface
{
    private const COMPLETION_INTERFACE = CompletionServiceInterface::class;

    public function isAvailable(): bool
    {
        return interface_exists(self::COMPLETION_INTERFACE);
    }

    /**
     * Describe the provider/model the agent will use, including pricing, so
     * UIs can show real connection data. Null when nr-llm is unavailable or
     * no model is configured. Best-effort and fully guarded — never fatal.
     *
     * @return array{provider: string, adapter: string, endpoint: string, model: string, modelId: string, priceInput: string, priceOutput: string, hasPricing: bool}|null
     */
    public function getConnectionInfo(): ?array
    {
        $model = $this->defaultModel();
        if ($model === null) {
            return null;
        }

        try {
            $provider = $model->getProvider();
            $priceIn = $model->getCostInputDollars();
            $priceOut = $model->getCostOutputDollars();
            $format = static fn(float $value): string => $value > 0
                ? '$' . rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.')
                : '—';

            return [
                'provider' => $provider?->getName() ?: ($provider?->getIdentifier() ?? 'unknown'),
                'adapter' => $provider?->getAdapterName() ?? '',
                'endpoint' => $provider?->getEffectiveEndpointUrl() ?? '',
                'model' => $model->getDisplayName() ?: $model->getModelId(),
                'modelId' => $model->getModelId(),
                'priceInput' => $format($priceIn),
                'priceOutput' => $format($priceOut),
                'hasPricing' => $priceIn > 0 || $priceOut > 0,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Ask the model for a JSON object and report token usage + cost.
     *
     * @return array{data: array<string, mixed>, promptTokens: int, completionTokens: int, cost: ?float, model: string}
     * @throws \RuntimeException when nr-llm is not installed
     * @throws \JsonException when the model response is not valid JSON
     */
    public function completeJson(string $systemPrompt, string $userPrompt, ?string $model = null, ?int $maxTokens = null): array
    {
        $options = ChatOptions::json()->withSystemPrompt($systemPrompt);
        if ($model !== null && $model !== '') {
            $options = $options->withModel($model);
        }
        if ($maxTokens !== null && $maxTokens > 0) {
            $options = $options->withMaxTokens($maxTokens);
        }

        $response = $this->completionService()->complete($userPrompt, $options);

        $decoded = json_decode($response->getText(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \JsonException('Model response was not a JSON object.');
        }

        $promptTokens = $response->usage->promptTokens;
        $completionTokens = $response->usage->completionTokens;

        return [
            'data' => $decoded,
            'promptTokens' => $promptTokens,
            'completionTokens' => $completionTokens,
            'cost' => $response->usage->getCost() ?? $this->estimateCost($promptTokens, $completionTokens),
            'model' => $model ?? '',
        ];
    }

    /**
     * Ask the model for a short factual text and report token usage + cost.
     *
     * @return array{text: string, promptTokens: int, completionTokens: int, cost: ?float, model: string}
     * @throws \RuntimeException when nr-llm is not installed
     */
    public function completeText(string $systemPrompt, string $userPrompt, ?string $model = null, ?int $maxTokens = null): array
    {
        $options = ChatOptions::factual()->withSystemPrompt($systemPrompt);
        if ($model !== null && $model !== '') {
            $options = $options->withModel($model);
        }
        if ($maxTokens !== null && $maxTokens > 0) {
            $options = $options->withMaxTokens($maxTokens);
        }

        $response = $this->completionService()->complete($userPrompt, $options);

        $promptTokens = $response->usage->promptTokens;
        $completionTokens = $response->usage->completionTokens;

        return [
            'text' => trim($response->getText()),
            'promptTokens' => $promptTokens,
            'completionTokens' => $completionTokens,
            'cost' => $response->usage->getCost() ?? $this->estimateCost($promptTokens, $completionTokens),
            'model' => $model ?? '',
        ];
    }

    /**
     * Stream a text answer chunk by chunk.
     *
     * Uses nr-llm's real streamChat() when the provider supports it; any
     * failure (unsupported feature, transport error) falls back to a single
     * non-streamed completion whose text is re-chunked server-side so the
     * consumer keeps its streamed UX. Callers must record usage themselves —
     * streamed responses carry no usage statistics.
     *
     * @return \Generator<int, string>
     * @throws \RuntimeException when nr-llm is not installed
     */
    public function streamText(string $systemPrompt, string $userPrompt, ?int $maxTokens = null): \Generator
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('netresearch/nr-llm is not installed.', 1751400001);
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];
        $options = ChatOptions::balanced();
        if ($maxTokens !== null && $maxTokens > 0) {
            $options = $options->withMaxTokens($maxTokens);
        }

        try {
            $manager = GeneralUtility::makeInstance(LlmServiceManagerInterface::class);
            yield from $manager->streamChat($messages, $options);
            return;
        } catch (\Throwable) {
            // Streaming unsupported or broken mid-flight — degrade below.
        }

        $result = $this->completeText($systemPrompt, $userPrompt, null, $maxTokens);
        yield from $this->chunk($result['text']);
    }

    /**
     * Split a finished text into word groups so a non-streaming fallback still
     * reads like a live stream.
     *
     * @return \Generator<int, string>
     */
    public function chunk(string $text, int $wordsPerChunk = 4): \Generator
    {
        $words = preg_split('/(?<=\s)/u', $text) ?: [];
        $buffer = [];
        foreach ($words as $word) {
            $buffer[] = $word;
            if (count($buffer) >= $wordsPerChunk) {
                yield implode('', $buffer);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            yield implode('', $buffer);
        }
    }

    /**
     * Estimate a call's cost from token counts × the default model's
     * per-1M-token pricing (used when a response carries no cost).
     */
    public function estimateCost(int $promptTokens, int $completionTokens): ?float
    {
        $model = $this->defaultModel();
        if ($model === null) {
            return null;
        }
        try {
            $inputPerMillion = $model->getCostInputDollars();
            $outputPerMillion = $model->getCostOutputDollars();
            if ($inputPerMillion <= 0 && $outputPerMillion <= 0) {
                return null;
            }
            return $promptTokens / 1_000_000 * $inputPerMillion
                + $completionTokens / 1_000_000 * $outputPerMillion;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Rough token estimate for streamed responses that report no usage.
     */
    public function estimateTokens(string $text): int
    {
        return (int)ceil(mb_strlen($text) / 4);
    }

    /**
     * Instance-wide nr-llm spend for a date range (all consumers, not just
     * Agent Nexus). Null when unavailable.
     */
    public function getInstanceCost(\DateTimeInterface $from, \DateTimeInterface $to): ?float
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $serviceClass = \Netresearch\NrLlm\Service\UsageAnalyticsService::class;
        if (!class_exists($serviceClass)) {
            return null;
        }
        try {
            $analytics = GeneralUtility::makeInstance($serviceClass);
            $totals = $analytics->getKpiTotals($from, $to);
            return is_numeric($totals['cost'] ?? null) ? (float)$totals['cost'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function completionService(): object
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('netresearch/nr-llm is not installed.', 1751400000);
        }
        /** @var CompletionServiceInterface $service */
        $service = GeneralUtility::makeInstance(self::COMPLETION_INTERFACE);
        return $service;
    }

    /**
     * The configured default model (or first chat model), untyped to keep
     * nr-llm a soft dependency. Null when nr-llm is unavailable.
     */
    private function defaultModel(): ?object
    {
        if (!$this->isAvailable()) {
            return null;
        }
        $repositoryClass = \Netresearch\NrLlm\Domain\Repository\ModelRepository::class;
        if (!class_exists($repositoryClass)) {
            return null;
        }
        try {
            $modelRepository = GeneralUtility::makeInstance($repositoryClass);
            $model = $modelRepository->findDefault() ?? $modelRepository->findChatModels()->getFirst();
            return is_object($model) ? $model : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
