<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Llm;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Central gate for every *frontend* LLM call.
 *
 * Streamed nr-llm calls skip its own budget middleware, so this guard is the
 * only cost brake on that path: it checks availability, the global frontend
 * switch, the per-protocol toggle and the shared daily budget. Callers that
 * get a deny fall back to their deterministic script and surface the reason
 * as provenance ("Scripted demo — daily LLM budget reached").
 */
final class LlmGuard implements SingletonInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LlmClient $llmClient,
        private readonly LlmUsageTracker $usageTracker,
    ) {}

    /**
     * @return array{allowed: bool, reason: string}
     */
    public function allows(string $protocol): array
    {
        if (!$this->llmClient->isAvailable()) {
            return ['allowed' => false, 'reason' => 'nr-llm not installed'];
        }

        $config = $this->config();

        if (!(bool)($config['llmFrontendEnabled'] ?? true)) {
            return ['allowed' => false, 'reason' => 'frontend LLM disabled'];
        }

        if (!(bool)($config[$protocol . 'LlmEnabled'] ?? false)) {
            return ['allowed' => false, 'reason' => sprintf('%s LLM disabled', $protocol)];
        }

        $budget = (float)($config['llmDailyBudget'] ?? 0);
        if ($budget > 0 && $this->usageTracker->getCostToday() >= $budget) {
            return ['allowed' => false, 'reason' => 'daily LLM budget reached'];
        }

        return ['allowed' => true, 'reason' => ''];
    }

    /**
     * The configured output-token ceiling for frontend calls; plugin settings
     * may lower but never exceed it.
     */
    public function maxOutputTokens(?int $requested = null): int
    {
        $ceiling = (int)($this->config()['llmMaxOutputTokens'] ?? 700);
        $ceiling = $ceiling > 0 ? $ceiling : 700;
        if ($requested === null || $requested <= 0) {
            return $ceiling;
        }
        return min($requested, $ceiling);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        try {
            $config = $this->extensionConfiguration->get('agent_nexus');
            return is_array($config) ? $config : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
