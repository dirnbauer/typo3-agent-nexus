<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Llm;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * Central gate for every *frontend* LLM call.
 *
 * Streamed nr-llm calls skip its own budget middleware, so this guard is the
 * only cost brake on that path: it checks availability, the global frontend
 * switch, the per-protocol toggle and the shared daily budget. Callers that
 * get a deny fall back to their deterministic script and surface the reason
 * as provenance ("Scripted demo — daily LLM budget reached").
 */
final readonly class LlmGuard implements SingletonInterface
{
    public const string DENIED_NOT_INSTALLED = 'not_installed';
    public const string DENIED_FRONTEND_DISABLED = 'frontend_disabled';
    public const string DENIED_PROTOCOL_DISABLED = 'protocol_disabled';
    public const string DENIED_BUDGET_REACHED = 'budget_reached';

    /** `llmMaxOutputTokens`: the budget of a protocol whose own budget is 0. */
    public const int DEFAULT_FALLBACK_BUDGET = 700;

    /**
     * Each protocol's own budget until the extension configuration says
     * otherwise (ext_conf_template.txt). Measured on 2026-09-23 with GPT-5.6
     * Terra, reasoning tokens included: A2UI forms took 532 to 897 output
     * tokens, AG-UI answers about 80, the A2A routing and deliverables up to
     * about 120, the UCP rationale under 50.
     */
    public const array DEFAULT_OUTPUT_BUDGETS = [
        'a2ui' => 1600,
        'agui' => 700,
        'a2a' => 400,
        'ucp' => 160,
        'ap2' => 160,
    ];

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
        private LanguageModel $llmClient,
        private UsageLedger $usageTracker,
    ) {}

    /**
     * Whether a frontend model call is allowed, and if not, why: `reason` in
     * plain English for provenance and logs, `code` (a DENIED_* constant) for
     * screens that translate it.
     *
     * @return array{allowed: bool, reason: string, code: string}
     */
    public function allows(string $protocol): array
    {
        if (!$this->llmClient->isAvailable()) {
            return ['allowed' => false, 'reason' => 'nr-llm not installed', 'code' => self::DENIED_NOT_INSTALLED];
        }

        $config = $this->config();

        if (!(bool)($config['llmFrontendEnabled'] ?? true)) {
            return ['allowed' => false, 'reason' => 'frontend LLM disabled', 'code' => self::DENIED_FRONTEND_DISABLED];
        }

        if (!(bool)($config[$protocol . 'LlmEnabled'] ?? false)) {
            return ['allowed' => false, 'reason' => sprintf('%s LLM disabled', $protocol), 'code' => self::DENIED_PROTOCOL_DISABLED];
        }

        $budget = (float)($config['llmDailyBudget'] ?? 0);
        if ($budget > 0 && $this->usageTracker->getCostToday() >= $budget) {
            return ['allowed' => false, 'reason' => 'daily LLM budget reached', 'code' => self::DENIED_BUDGET_REACHED];
        }

        return ['allowed' => true, 'reason' => '', 'code' => ''];
    }

    /**
     * The output budget of one frontend model call for a protocol.
     *
     * Each protocol has a budget of its own (`<protocol>LlmMaxOutputTokens`),
     * because what it asks for differs by an order of magnitude: an A2UI form
     * is a JSON document of 500 to 900 output tokens, a UCP rationale two
     * sentences. A budget of 0 hands over to `llmMaxOutputTokens`, the
     * fallback for every protocol. A plugin setting may ask for less than the
     * budget, never for more.
     */
    public function maxOutputTokens(Protocol $protocol, ?int $requested = null): int
    {
        $budget = $this->outputBudget($protocol);
        if ($requested === null || $requested <= 0) {
            return $budget;
        }
        return min($requested, $budget);
    }

    /**
     * The protocol's own output budget, or the global fallback when it has none.
     */
    public function outputBudget(Protocol $protocol): int
    {
        $config = $this->config();
        $own = $config[$protocol->value . 'LlmMaxOutputTokens'] ?? null;
        $own = is_numeric($own) ? (int)$own : self::DEFAULT_OUTPUT_BUDGETS[$protocol->value];
        if ($own > 0) {
            return $own;
        }
        $fallback = $config['llmMaxOutputTokens'] ?? null;
        $fallback = is_numeric($fallback) ? (int)$fallback : self::DEFAULT_FALLBACK_BUDGET;
        return $fallback > 0 ? $fallback : self::DEFAULT_FALLBACK_BUDGET;
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
