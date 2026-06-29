<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Netresearch\NrLlm\Service\Feature\CompletionServiceInterface;
use Netresearch\NrLlm\Service\Option\ChatOptions;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Thin, optional bridge to the netresearch/nr-llm completion service.
 *
 * nr-llm is a *soft* dependency (declared under composer "suggest"), so this
 * adapter never references its classes unless they are actually present:
 * {@see isAvailable()} guards every use. When nr-llm is installed and a text
 * provider is configured (in this lab: OpenAI keyed via nr-vault), a real model
 * generates the A2UI surface; otherwise the agent falls back to its built-in
 * deterministic generator.
 */
final class LlmClient implements SingletonInterface
{
    private const COMPLETION_INTERFACE = CompletionServiceInterface::class;

    public function isAvailable(): bool
    {
        return interface_exists(self::COMPLETION_INTERFACE);
    }

    /**
     * Describe the provider/model the agent will use, including pricing, so the
     * UI can show real connection data. Returns null when nr-llm is unavailable
     * or no model is configured. Best-effort and fully guarded — never fatal.
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
     * The configured default model (or first chat model). Returned untyped to
     * keep nr-llm a soft dependency. Null when nr-llm is unavailable.
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

    /**
     * Estimate a call's cost from token counts × the default model's per-1M-token
     * pricing. Used when the provider response carries no estimatedCost.
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
     * Instance-wide nr-llm spend for a date range (all consumers, not just A2UI),
     * used as a secondary "share of total" figure. Null when unavailable.
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

    /**
     * Ask the model to return a JSON object, and report the token usage and cost
     * of the call so A2UI can ledger its own spend.
     *
     * @return array{data: array<string, mixed>, promptTokens: int, completionTokens: int, cost: ?float, model: string}
     * @throws \RuntimeException when nr-llm is not installed
     * @throws \JsonException when the model response is not valid JSON
     */
    public function completeSurface(string $systemPrompt, string $userPrompt, ?string $model = null): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('netresearch/nr-llm is not installed.', 1749900000);
        }

        /** @var CompletionServiceInterface $service */
        $service = GeneralUtility::makeInstance(self::COMPLETION_INTERFACE);

        $options = ChatOptions::json()->withSystemPrompt($systemPrompt);
        if ($model !== null && $model !== '') {
            $options = $options->withModel($model);
        }

        // complete() (unlike completeJson()) returns the full response, so we can
        // read token usage + cost in addition to the JSON content.
        $response = $service->complete($userPrompt, $options);

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
            // Fall back to our own estimate when the provider omits a cost.
            'cost' => $response->usage->getCost() ?? $this->estimateCost($promptTokens, $completionTokens),
            'model' => $model ?? '',
        ];
    }
}
