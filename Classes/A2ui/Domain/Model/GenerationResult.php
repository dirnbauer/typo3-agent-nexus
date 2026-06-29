<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * The outcome of an agent generation: the A2UI surface plus its provenance, so
 * the UI can honestly show whether a real model produced the interface or the
 * built-in deterministic generator stepped in.
 */
final class GenerationResult
{
    public const SOURCE_LLM = 'llm';
    public const SOURCE_BUILTIN = 'builtin';

    /**
     * @param array<int, string> $notes Human-readable warnings (e.g. why the LLM path was skipped)
     */
    public function __construct(
        private readonly Surface $surface,
        private readonly string $source,
        private readonly ?string $model = null,
        private readonly array $notes = [],
    ) {}

    public function getSurface(): Surface
    {
        return $this->surface;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function isLlm(): bool
    {
        return $this->source === self::SOURCE_LLM;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    /**
     * @return array<int, string>
     */
    public function getNotes(): array
    {
        return $this->notes;
    }

    /**
     * A short, human-readable provenance label for the "where did this UI come from" badge.
     */
    public function getProvenanceLabel(): string
    {
        if ($this->isLlm()) {
            return $this->model !== null && $this->model !== ''
                ? 'LLM · ' . $this->model
                : 'LLM';
        }
        return 'Built-in generator';
    }
}
