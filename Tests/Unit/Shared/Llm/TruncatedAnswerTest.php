<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Shared\Llm;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Shared\Llm\TruncatedAnswer;

/**
 * nr-llm normalises every provider's "ran out of output tokens" to the finish
 * reason "length"; only that reason may turn an answer into a fallback.
 */
final class TruncatedAnswerTest extends UnitTestCase
{
    #[Test]
    public function theFinishReasonLengthMeansTheAnswerWasCutOff(): void
    {
        $truncated = TruncatedAnswer::fromFinishReason('length', 1600, 1750, 1600, 0.021, 'gpt-test');

        self::assertInstanceOf(TruncatedAnswer::class, $truncated);
        self::assertSame(1600, $truncated->maxTokens);
        self::assertSame(1750, $truncated->promptTokens);
        self::assertSame(1600, $truncated->completionTokens);
        self::assertSame(0.021, $truncated->cost);
        self::assertSame('gpt-test', $truncated->model);
        self::assertSame('the model answer was cut off at 1600 output tokens', $truncated->reason());
        self::assertSame('The model answer was cut off at the output limit of 1600 tokens.', $truncated->getMessage());
    }

    #[Test]
    public function everyOtherFinishReasonIsAFinishedAnswer(): void
    {
        foreach (['stop', 'tool_calls', 'content_filter', ''] as $reason) {
            self::assertNull(TruncatedAnswer::fromFinishReason($reason, 1600, 1, 1, null, ''), $reason);
        }
    }

    #[Test]
    public function withoutABudgetOfItsOwnTheLimitIsNotNamed(): void
    {
        $truncated = TruncatedAnswer::fromFinishReason('length', 0, 1, 1, null, '');

        self::assertNotNull($truncated);
        self::assertNull($truncated->maxTokens);
        self::assertSame('the model answer was cut off at its output limit', $truncated->reason());
    }
}
