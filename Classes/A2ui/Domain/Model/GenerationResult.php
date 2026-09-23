<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * A generated surface and where it came from, so every screen can say
 * honestly whether a model wrote the interface or the built-in generator did.
 */
final readonly class GenerationResult
{
    public const string MODE_LLM = 'llm';
    public const string MODE_BUILTIN = 'builtin';

    /**
     * @param list<string> $notes plain-English remarks: why the model was skipped, what was repaired
     */
    public function __construct(
        public Surface $surface,
        public string $mode,
        public ?string $model = null,
        public array $notes = [],
    ) {}

    public function isLlm(): bool
    {
        return $this->mode === self::MODE_LLM;
    }

    /** "Live model · <model>" or "Scripted demo", the wording every widget uses. */
    public function label(): string
    {
        if ($this->isLlm()) {
            return $this->model !== null && $this->model !== '' ? 'Live model · ' . $this->model : 'Live model';
        }
        return 'Scripted demo';
    }

    /**
     * @return array{mode: string, label: string}
     */
    public function provenance(): array
    {
        return ['mode' => $this->mode, 'label' => $this->label()];
    }
}
