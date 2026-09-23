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
     * @param string $reason why the built-in generator answered although a model was asked, e.g. "the model answer was cut off at 1600 output tokens"
     */
    public function __construct(
        public Surface $surface,
        public string $mode,
        public ?string $model = null,
        public array $notes = [],
        public string $reason = '',
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
     * The provenance every widget shows; `reason` only when a model was asked
     * and its answer could not be used.
     *
     * @return array{mode: string, label: string, reason?: string}
     */
    public function provenance(): array
    {
        $provenance = ['mode' => $this->mode, 'label' => $this->label()];
        if ($this->reason !== '') {
            $provenance['reason'] = $this->reason;
        }
        return $provenance;
    }
}
