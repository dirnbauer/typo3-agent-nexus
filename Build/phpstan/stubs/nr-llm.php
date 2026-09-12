<?php

/**
 * Analysis-only stubs for netresearch/nr-llm.
 *
 * nr-llm is a soft dependency (composer "suggest"): Agent Nexus degrades to
 * deterministic demos when it is absent, so it must never become a hard
 * require — not even a dev one, because CI has to prove the extension is
 * analysable without it. These stubs give PHPStan the exact signatures the
 * {@see \Webconsulting\AgentNexus\Shared\Llm\LlmClient} bridge relies on.
 *
 * Only the surface Agent Nexus actually touches is declared here. Keep it in
 * sync with nr-llm whenever the bridge starts using something new.
 */

namespace Netresearch\NrLlm\Domain\Model {
    class UsageStatistics
    {
        public int $promptTokens;
        public int $completionTokens;
        public int $totalTokens;
        public ?float $estimatedCost;

        public function getCost(): ?float {}
    }

    class CompletionResponse
    {
        public string $content;
        public string $model;
        public UsageStatistics $usage;
        public string $finishReason;
        public string $provider;

        public function getText(): string {}
    }

    class Provider
    {
        public function getIdentifier(): string {}

        public function getName(): string {}

        public function getAdapterName(): string {}

        public function getEffectiveEndpointUrl(): string {}
    }

    class Model
    {
        public function getProvider(): ?Provider {}

        public function getModelId(): string {}

        public function getDisplayName(): string {}

        public function getCostInputDollars(): float {}

        public function getCostOutputDollars(): float {}
    }
}

namespace Netresearch\NrLlm\Domain\Repository {
    use Netresearch\NrLlm\Domain\Model\Model;
    use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;

    class ModelRepository
    {
        public function findDefault(): ?Model {}

        /**
         * @return QueryResultInterface<int, Model>
         */
        public function findChatModels(): QueryResultInterface {}
    }
}

namespace Netresearch\NrLlm\Service\Option {
    class ChatOptions
    {
        public static function factual(): static {}

        public static function balanced(): static {}

        public static function json(): static {}

        public function withMaxTokens(int $maxTokens): static {}

        public function withSystemPrompt(string $systemPrompt): static {}

        public function withModel(string $model): static {}
    }
}

namespace Netresearch\NrLlm\Service\Feature {
    use Netresearch\NrLlm\Domain\Model\CompletionResponse;
    use Netresearch\NrLlm\Service\Option\ChatOptions;

    interface CompletionServiceInterface
    {
        public function complete(string $prompt, ?ChatOptions $options = null): CompletionResponse;
    }
}

namespace Netresearch\NrLlm\Service {
    use Netresearch\NrLlm\Service\Option\ChatOptions;

    interface LlmServiceManagerInterface
    {
        /**
         * @param list<array<string, mixed>> $messages
         *
         * @return \Generator<int, string, mixed, void>
         */
        public function streamChat(array $messages, ?ChatOptions $options = null): \Generator;
    }

    class UsageAnalyticsService
    {
        /**
         * @return array<string, mixed>
         */
        public function getKpiTotals(\DateTimeInterface $from, \DateTimeInterface $to): array {}
    }
}
