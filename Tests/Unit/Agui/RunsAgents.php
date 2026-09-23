<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Agui;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Webconsulting\AgentNexus\Agui\Agent\Audience;
use Webconsulting\AgentNexus\Agui\Agent\Scenarios;
use Webconsulting\AgentNexus\Agui\Protocol\RunInput;
use Webconsulting\AgentNexus\Agui\Service\AgentRunner;
use Webconsulting\AgentNexus\Agui\Service\Applier;
use Webconsulting\AgentNexus\Agui\Service\RunTranscript;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;

/**
 * Builds the demo agents without a database or a model, and turns their
 * streams into the run records a thread keeps.
 */
trait RunsAgents
{
    private function runner(?LanguageModel $model = null, bool $reallyApply = false, ?UsageLedger $ledger = null): AgentRunner
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['aguiReallyApply' => $reallyApply ? '1' : '0']);

        return new AgentRunner(
            $model ?? self::createStub(LanguageModel::class),
            $ledger ?? self::createStub(UsageLedger::class),
            new Applier(new ExtensionSettings($configuration)),
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function input(string $threadId, string $runId, array $extra = [], string $question = 'I need a plan for five people.'): RunInput
    {
        return RunInput::fromJson((string)json_encode([
            'threadId' => $threadId,
            'runId' => $runId,
            'protocolVersion' => '1.0',
            'messages' => $question === '' ? [] : [['id' => 'msg-user', 'role' => 'user', 'content' => $question]],
        ] + $extra));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function propose(string $preset = 'plan', string $threadId = 'thread-1', string $runId = 'run-1'): array
    {
        $audience = in_array($preset, ['plan', 'support'], true) ? Audience::Site : Audience::Editor;
        $scenario = (new Scenarios())->resolve($preset, $audience);
        return iterator_to_array($this->runner()->propose(self::input($threadId, $runId), $scenario), false);
    }

    /**
     * The run record a stream leaves behind, as the store keeps it.
     *
     * @param list<array<string, mixed>> $events
     */
    private static function record(array $events, RunInput $input): ProtocolObject
    {
        $transcript = new RunTranscript($input->state);
        foreach ($events as $event) {
            $transcript->apply($event);
        }
        return (new ProtocolObject(ObjectKind::Run, $input->runId, $input->threadId, payload: $transcript->payload($input->toArray())))
            ->withState($transcript->state());
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function first(array $events, string $type): array
    {
        foreach ($events as $event) {
            if ($event['type'] === $type) {
                return $event;
            }
        }
        self::fail('No ' . $type . ' in the stream.');
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<string>
     */
    private static function types(array $events): array
    {
        return array_map(static fn(array $event): string => is_string($event['type'] ?? null) ? $event['type'] : '', $events);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private static function interruptOf(array $events): array
    {
        $finished = self::first($events, 'RUN_FINISHED');
        self::assertIsArray($finished['outcome'] ?? null);
        self::assertSame('interrupt', $finished['outcome']['type'] ?? null);
        self::assertIsArray($finished['outcome']['interrupts'][0] ?? null);
        return $finished['outcome']['interrupts'][0];
    }
}
