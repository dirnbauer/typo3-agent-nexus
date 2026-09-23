<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;

/**
 * What a verifier decided about a presented mandate: every check it ran, and
 * — when the chain could be read — the chain and its decoded mandates.
 *
 * Valid means every check passed. Otherwise the error code is the most
 * serious one among the failures: a broken credential outranks a mandate for
 * the wrong action, which outranks an unmet constraint.
 */
#[Exclude]
final readonly class Verdict
{
    /**
     * @param list<Check> $checks
     * @param list<array<string, mixed>> $mandates the decoded mandates, open first
     */
    public function __construct(
        public array $checks,
        public ?DelegateChain $chain = null,
        public array $mandates = [],
    ) {}

    public function valid(): bool
    {
        return $this->checks !== [] && array_all($this->checks, static fn(Check $check): bool => $check->pass);
    }

    public function error(): ?ErrorCode
    {
        return $this->firstFailure()?->type->failure();
    }

    public function errorDescription(): string
    {
        $failure = $this->firstFailure();
        return $failure === null ? '' : $failure->label() . ': ' . $failure->detail;
    }

    /**
     * The closed mandate: the last one in the chain.
     *
     * @return array<string, mixed>
     */
    public function closed(): array
    {
        return $this->mandates === [] ? [] : $this->mandates[count($this->mandates) - 1];
    }

    /**
     * The open mandate, when the closed one was presented with it.
     *
     * @return array<string, mixed>
     */
    public function open(): array
    {
        return count($this->mandates) > 1 ? $this->mandates[0] : [];
    }

    /** Receipt reference of the closed mandate; empty when the chain was unreadable. */
    public function reference(): string
    {
        return $this->chain?->reference() ?? '';
    }

    /** Reference of the open mandate the closed one was bound to, if any. */
    public function openReference(): string
    {
        return $this->chain !== null && $this->chain->count() > 1 ? $this->chain->root()->reference() : '';
    }

    public function failed(CheckType $type): bool
    {
        return array_any($this->checks, static fn(Check $check): bool => $check->type === $type && !$check->pass);
    }

    public function with(Check ...$checks): self
    {
        return new self([...$this->checks, ...array_values($checks)], $this->chain, $this->mandates);
    }

    /**
     * @return array{valid: bool, error: ?string, errorDescription: string, checks: list<array{id: string, label: string, pass: bool, detail: string}>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid(),
            'error' => $this->error()?->value,
            'errorDescription' => $this->errorDescription(),
            'checks' => array_map(static fn(Check $check): array => $check->toArray(), $this->checks),
        ];
    }

    private function firstFailure(): ?Check
    {
        $failure = null;
        foreach ($this->checks as $check) {
            if ($check->pass) {
                continue;
            }
            if ($failure === null || $check->type->failure()->precedence() < $failure->type->failure()->precedence()) {
                $failure = $check;
            }
        }
        return $failure;
    }
}
