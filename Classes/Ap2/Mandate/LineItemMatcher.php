<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Evaluates `checkout.line_items` as the maximum-flow problem AP2 describes.
 *
 * Source → each requirement (capacity: its quantity) → every checkout item it
 * accepts (unbounded) → sink (capacity: that item's quantity in the
 * checkout). The constraint holds when the maximum flow equals both the total
 * quantity the requirements ask for and the total quantity in the checkout:
 * every requirement is filled, nothing extra was bought, no item counts twice.
 *
 * An item matches a requirement only through the acceptable items the agent
 * revealed. A requirement with none revealed matches nothing — the AP2 SDK
 * treats it as a wildcard instead, which would let an agent widen the
 * constraint by withholding disclosures, so this follows the specification.
 */
#[Exclude]
final class LineItemMatcher
{
    /**
     * @param array<string, int> $checkout item id => quantity in the checkout
     * @param list<array{id: string, acceptable: list<string>, quantity: int}> $requirements
     * @return list<string> why the checkout does not fit; empty when it does
     */
    public static function violations(array $checkout, array $requirements): array
    {
        $checkout = array_filter($checkout, static fn(int $quantity): bool => $quantity > 0);
        if ($checkout === []) {
            return ['The checkout has no line items.'];
        }
        if ($requirements === []) {
            return ['The constraint lists no items.'];
        }

        $violations = [];
        foreach (array_keys($checkout) as $item) {
            $accepted = array_any($requirements, static fn(array $requirement): bool => in_array((string)$item, $requirement['acceptable'], true));
            if (!$accepted) {
                $violations[] = sprintf('"%s" is not on the approved list.', $item);
            }
        }
        if ($violations !== []) {
            return $violations;
        }

        $asked = array_sum(array_map(static fn(array $requirement): int => max(0, $requirement['quantity']), $requirements));
        $bought = array_sum($checkout);
        $flow = self::maximumFlow($checkout, $requirements);

        if ($flow === $asked && $flow === $bought) {
            return [];
        }
        if ($bought !== $asked) {
            return [sprintf('The approved list asks for %d %s; the checkout has %d.', $asked, $asked === 1 ? 'item' : 'items', $bought)];
        }
        return [sprintf('%d of %d items cannot be matched to the approved list.', $bought - $flow, $bought)];
    }

    /**
     * Edmonds–Karp over a dense matrix; the graphs here have a handful of nodes.
     *
     * @param array<string, int> $checkout
     * @param list<array{id: string, acceptable: list<string>, quantity: int}> $requirements
     */
    private static function maximumFlow(array $checkout, array $requirements): int
    {
        $items = array_map(strval(...), array_keys($checkout));
        $requirementCount = count($requirements);
        $source = 0;
        $sink = 1 + $requirementCount + count($items);
        $unbounded = PHP_INT_MAX >> 2;

        // Residual capacities; a missing edge has none.
        $capacity = [];
        foreach ($requirements as $r => $requirement) {
            $capacity[$source][1 + $r] = max(0, $requirement['quantity']);
            foreach ($items as $i => $item) {
                if (in_array($item, $requirement['acceptable'], true)) {
                    $capacity[1 + $r][1 + $requirementCount + $i] = $unbounded;
                }
            }
        }
        foreach ($items as $i => $item) {
            $capacity[1 + $requirementCount + $i][$sink] = $checkout[$item];
        }

        $flow = 0;
        while (($path = self::augmentingPath($capacity, $source, $sink, $sink + 1)) !== null) {
            $push = $unbounded;
            foreach ($path as [$from, $to]) {
                $push = min($push, $capacity[$from][$to] ?? 0);
            }
            foreach ($path as [$from, $to]) {
                $capacity[$from][$to] = ($capacity[$from][$to] ?? 0) - $push;
                $capacity[$to][$from] = ($capacity[$to][$from] ?? 0) + $push;
            }
            $flow += $push;
        }
        return $flow;
    }

    /**
     * The shortest path from source to sink with residual capacity left, as
     * its edges, or null when there is none (breadth-first search).
     *
     * @param array<int, array<int, int>> $capacity
     * @return list<array{0: int, 1: int}>|null
     */
    private static function augmentingPath(array $capacity, int $source, int $sink, int $nodes): ?array
    {
        $parent = [$source => $source];
        $queue = [$source];
        while ($queue !== []) {
            $node = array_shift($queue);
            for ($next = 0; $next < $nodes; $next++) {
                if (!isset($parent[$next]) && ($capacity[$node][$next] ?? 0) > 0) {
                    $parent[$next] = $node;
                    $queue[] = $next;
                }
            }
            if (isset($parent[$sink])) {
                $path = [];
                for ($to = $sink; $to !== $source; $to = $from) {
                    $from = $parent[$to] ?? $source;
                    array_unshift($path, [$from, $to]);
                }
                return $path;
            }
        }
        return null;
    }
}
