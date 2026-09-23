<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Llm;

/**
 * Agent Nexus' own record of model spend.
 *
 * Streamed calls bypass nr-llm's usage middleware, so this ledger is the only
 * record of their cost and the figure the daily frontend budget is checked
 * against ({@see LlmGuard}).
 */
interface UsageLedger
{
    public const string SOURCE_BACKEND = 'backend';
    public const string SOURCE_FRONTEND = 'frontend';

    public function record(
        string $protocol,
        string $source,
        string $model,
        int $promptTokens,
        int $completionTokens,
        ?float $cost,
        int $beUser = 0,
    ): void;

    /**
     * @return array{cost: float, requests: int, tokens: int}
     */
    public function getTotals(int $from, int $to, ?string $protocol = null): array;

    public function getCostToday(?string $protocol = null): float;

    /**
     * @return list<array{label: string, year: int, month: int, cost: float, requests: int}>
     */
    public function getMonthlyCosts(int $months = 3, ?string $protocol = null): array;
}
