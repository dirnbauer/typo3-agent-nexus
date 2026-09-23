<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures;

use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;

/**
 * A language model that answers with a fixed text, fails the way it was told
 * to — or is not there at all. It remembers the output budget it was given.
 */
final class ScriptedLanguageModel implements LanguageModel
{
    public int $calls = 0;

    /** @var list<int|null> */
    public private(set) array $maxTokens = [];

    public function __construct(
        private readonly bool $available = false,
        private readonly string $answer = '',
        private readonly ?\Throwable $failure = null,
    ) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function getConnectionInfo(): ?array
    {
        return $this->available
            ? ['provider' => 'Test provider', 'adapter' => 'test', 'endpoint' => '', 'model' => 'test-model', 'modelId' => 'test-model', 'priceInput' => '—', 'priceOutput' => '—', 'hasPricing' => false]
            : null;
    }

    public function completeJson(string $systemPrompt, string $userPrompt, ?string $model = null, ?int $maxTokens = null): array
    {
        throw new \RuntimeException('Not used by the UCP agent.', 1758709001);
    }

    public function completeText(string $systemPrompt, string $userPrompt, ?string $model = null, ?int $maxTokens = null): array
    {
        if (!$this->available) {
            throw new \RuntimeException('No model.', 1758709002);
        }
        $this->calls++;
        $this->maxTokens[] = $maxTokens;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        return ['text' => $this->answer, 'promptTokens' => 120, 'completionTokens' => 30, 'cost' => null, 'model' => 'test-model'];
    }

    public function streamText(string $systemPrompt, string $userPrompt, ?int $maxTokens = null): \Generator
    {
        throw new \RuntimeException('Not used by the UCP agent.', 1758709003);
    }

    public function estimateCost(int $promptTokens, int $completionTokens): ?float
    {
        return null;
    }

    public function estimateTokens(string $text): int
    {
        return (int)ceil(mb_strlen($text) / 4);
    }

    public function getInstanceCost(\DateTimeInterface $from, \DateTimeInterface $to): ?float
    {
        return null;
    }
}
