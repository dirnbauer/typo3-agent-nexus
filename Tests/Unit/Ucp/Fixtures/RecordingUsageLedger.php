<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures;

use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;

/**
 * A usage ledger that remembers what it was told.
 */
final class RecordingUsageLedger implements UsageLedger
{
    /** @var list<array{protocol: string, source: string, model: string, promptTokens: int, completionTokens: int}> */
    public array $records = [];

    public function record(string $protocol, string $source, string $model, int $promptTokens, int $completionTokens, ?float $cost, int $beUser = 0): void
    {
        $this->records[] = ['protocol' => $protocol, 'source' => $source, 'model' => $model, 'promptTokens' => $promptTokens, 'completionTokens' => $completionTokens];
    }

    public function getTotals(int $from, int $to, ?string $protocol = null): array
    {
        return ['cost' => 0.0, 'requests' => count($this->records), 'tokens' => 0];
    }

    public function getCostToday(?string $protocol = null): float
    {
        return 0.0;
    }

    public function getMonthlyCosts(int $months = 3, ?string $protocol = null): array
    {
        return [];
    }
}
