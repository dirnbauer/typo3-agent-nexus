<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Fixtures;

use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;

/**
 * A usage ledger that remembers what was recorded and has spent nothing.
 */
final class RecordingUsageLedger implements UsageLedger
{
    /** @var list<array{protocol: string, source: string, model: string}> */
    public private(set) array $records = [];

    public function record(string $protocol, string $source, string $model, int $promptTokens, int $completionTokens, ?float $cost, int $beUser = 0): void
    {
        $this->records[] = ['protocol' => $protocol, 'source' => $source, 'model' => $model];
    }

    public function getTotals(int $from, int $to, ?string $protocol = null): array
    {
        return ['cost' => 0.0, 'requests' => 0, 'tokens' => 0];
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
