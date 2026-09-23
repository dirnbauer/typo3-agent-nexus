<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Llm;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The model stopped because it reached its output budget, before it finished
 * its answer (finish reason "length").
 *
 * A cut-off answer is worse than none: a half JSON object is not a surface, and
 * half a sentence is not an explanation. {@see LanguageModel::completeJson()}
 * and {@see LanguageModel::completeText()} throw this instead of returning it,
 * so every caller falls back to its deterministic script and can say why. The
 * tokens were spent all the same, so the exception carries the usage figures
 * and callers still record them in the {@see UsageLedger}.
 */
#[Exclude]
final class TruncatedAnswer extends \RuntimeException
{
    /** The finish reason nr-llm normalises every provider's "out of tokens" to. */
    public const string FINISH_REASON = 'length';

    /**
     * @param int|null $maxTokens the output budget the call had, null when the provider default applied
     */
    public function __construct(
        public readonly ?int $maxTokens,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly ?float $cost,
        public readonly string $model,
    ) {
        parent::__construct(
            $maxTokens !== null
                ? sprintf('The model answer was cut off at the output limit of %d tokens.', $maxTokens)
                : 'The model answer was cut off at the output limit.',
            1758800001,
        );
    }

    /**
     * The exception for a completion that ended with this finish reason, or
     * null when the model finished its answer.
     */
    public static function fromFinishReason(string $finishReason, ?int $maxTokens, int $promptTokens, int $completionTokens, ?float $cost, string $model): ?self
    {
        return $finishReason === self::FINISH_REASON
            ? new self($maxTokens !== null && $maxTokens > 0 ? $maxTokens : null, $promptTokens, $completionTokens, $cost, $model)
            : null;
    }

    /**
     * The short reason a widget shows next to "Scripted demo".
     */
    public function reason(): string
    {
        return $this->maxTokens !== null
            ? sprintf('the model answer was cut off at %d output tokens', $this->maxTokens)
            : 'the model answer was cut off at its output limit';
    }
}
