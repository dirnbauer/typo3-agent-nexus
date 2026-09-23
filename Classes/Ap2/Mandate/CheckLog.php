<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Collects the outcome of several steps per check type and turns them into
 * one {@see Check} each: failed with every reason when any step failed,
 * passed otherwise. Checks come out in the order {@see CheckType} declares
 * its cases, which is the order a verifier works through them.
 *
 * @internal
 */
#[Exclude]
final class CheckLog
{
    /** @var array<string, array{type: CheckType, failures: list<string>, details: list<string>}> */
    private array $entries = [];

    public function pass(CheckType $type, string $detail): void
    {
        $this->entry($type);
        $this->entries[$type->value]['details'][] = $detail;
    }

    public function fail(CheckType $type, string $reason): void
    {
        $this->entry($type);
        $this->entries[$type->value]['failures'][] = $reason;
    }

    /** Record a pass with this detail unless the type already failed. */
    public function passUnlessFailed(CheckType $type, string $detail): void
    {
        if (($this->entries[$type->value]['failures'] ?? []) === []) {
            $this->pass($type, $detail);
        }
    }

    /**
     * @return list<Check>
     */
    public function checks(): array
    {
        $order = array_flip(array_map(static fn(CheckType $type): string => $type->value, CheckType::cases()));
        $entries = $this->entries;
        uksort($entries, static fn(string $a, string $b): int => ($order[$a] ?? 0) <=> ($order[$b] ?? 0));
        $checks = [];
        foreach ($entries as $entry) {
            $checks[] = $entry['failures'] !== []
                ? Check::fail($entry['type'], implode(' ', array_unique($entry['failures'])))
                : Check::pass($entry['type'], implode('; ', array_unique($entry['details'])));
        }
        return $checks;
    }

    private function entry(CheckType $type): void
    {
        $this->entries[$type->value] ??= ['type' => $type, 'failures' => [], 'details' => []];
    }
}
