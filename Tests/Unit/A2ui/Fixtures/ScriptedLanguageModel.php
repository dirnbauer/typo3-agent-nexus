<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Fixtures;

use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;

/**
 * A language model that answers every JSON request with the same data, or
 * throws what it was given; null means "no model installed".
 */
final readonly class ScriptedLanguageModel implements LanguageModel
{
    /**
     * @param array<array-key, mixed>|\Throwable|null $answer
     */
    public function __construct(
        private array|\Throwable|null $answer,
    ) {}

    public function isAvailable(): bool
    {
        return $this->answer !== null;
    }

    public function getConnectionInfo(): array
    {
        return [
            'provider' => 'test',
            'adapter' => 'test',
            'endpoint' => '',
            'model' => 'test-model',
            'modelId' => 'test-model',
            'priceInput' => '',
            'priceOutput' => '',
            'hasPricing' => false,
        ];
    }

    public function completeJson(string $systemPrompt, string $userPrompt, ?string $model = null, ?int $maxTokens = null): array
    {
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }
        $data = [];
        foreach ($this->answer ?? [] as $key => $value) {
            $data[(string)$key] = $value;
        }
        return ['data' => $data, 'promptTokens' => 100, 'completionTokens' => 50, 'cost' => 0.001, 'model' => $model ?? ''];
    }

    public function completeText(string $systemPrompt, string $userPrompt, ?string $model = null, ?int $maxTokens = null): array
    {
        throw new \LogicException('A2UI never asks for text.', 1758632101);
    }

    public function streamText(string $systemPrompt, string $userPrompt, ?int $maxTokens = null): \Generator
    {
        throw new \LogicException('A2UI never streams.', 1758632102);
    }

    public function estimateCost(int $promptTokens, int $completionTokens): ?float
    {
        return null;
    }

    public function estimateTokens(string $text): int
    {
        return 0;
    }

    public function getInstanceCost(\DateTimeInterface $from, \DateTimeInterface $to): ?float
    {
        return null;
    }
}
