<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Agui\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Agui\Agent\Audience;
use Webconsulting\AgentNexus\Agui\Agent\Scenarios;
use Webconsulting\AgentNexus\Agui\Protocol\EventVerifier;
use Webconsulting\AgentNexus\Agui\Service\AgentRunner;
use Webconsulting\AgentNexus\Agui\Service\LlmPlan;
use Webconsulting\AgentNexus\Agui\Service\ThreadState;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Tests\Unit\Agui\RunsAgents;

/**
 * The agents propose, stop for a person, and act only on an approval that
 * answers their interrupt. Every stream here also passes the 1.0 verifier.
 */
final class AgentRunnerTest extends UnitTestCase
{
    use RunsAgents;

    /**
     * @return iterable<string, array{string}>
     */
    public static function presets(): iterable
    {
        foreach (['plan', 'support', 'seo', 'translate', 'news'] as $preset) {
            yield $preset => [$preset];
        }
    }

    #[Test]
    #[DataProvider('presets')]
    public function everyTaskProposesAndStopsWithAnInterrupt(string $preset): void
    {
        $events = $this->propose($preset);

        EventVerifier::verify($events, 'thread-1', 'run-1');
        $toolCall = self::first($events, 'TOOL_CALL_START');
        $interrupt = self::interruptOf($events);
        self::assertSame($toolCall['toolCallId'], $interrupt['toolCallId'], 'The interrupt names the proposed tool call.');
        self::assertSame(AgentRunner::INTERRUPT_REASON, $interrupt['reason']);
        self::assertSame(['at.webconsulting.agentnexus' => ['preset' => $preset]], $interrupt['metadata']);
        self::assertNotContains('TOOL_CALL_RESULT', self::types($events), 'Nothing is carried out before an answer.');
    }

    #[Test]
    public function reasoningIsASpanWithOneReasoningMessageInside(): void
    {
        $events = $this->propose();
        $reasoning = array_values(array_filter(
            $events,
            static fn(array $event): bool => is_string($event['type'] ?? null) && str_starts_with($event['type'], 'REASONING_'),
        ));

        self::assertSame('REASONING_START', $reasoning[0]['type']);
        self::assertSame('REASONING_MESSAGE_START', $reasoning[1]['type']);
        self::assertSame('reasoning', $reasoning[1]['role']);
        $last = $reasoning[array_key_last($reasoning)];
        self::assertSame('REASONING_END', $last['type']);
        self::assertSame($reasoning[0]['messageId'], $last['messageId'], 'The span closes by its own id.');
        self::assertNotSame($reasoning[0]['messageId'], $reasoning[1]['messageId'], 'Span and message are separate namespaces.');
    }

    #[Test]
    public function theProposalBelongsToTheAnswerAndThePlansAreAnActivity(): void
    {
        $events = $this->propose('plan');

        $answer = self::first($events, 'TEXT_MESSAGE_START');
        self::assertSame($answer['messageId'], self::first($events, 'TOOL_CALL_START')['parentMessageId']);
        self::assertCount(1, array_keys(self::types($events), 'TOOL_CALL_START'), 'The plan comparison is not a tool call.');
        $activity = self::first($events, 'ACTIVITY_SNAPSHOT');
        self::assertSame(AgentRunner::PLAN_COMPARISON, $activity['activityType']);
        self::assertIsArray($activity['content']);
        self::assertSame('Team', $activity['content']['recommended']);
    }

    #[Test]
    public function aVisitorsApprovalAsksForNameAndEmail(): void
    {
        $schema = self::interruptOf($this->propose('plan'))['responseSchema'];

        self::assertIsArray($schema);
        self::assertSame(['approved'], $schema['required']);
        self::assertSame(['required' => ['name', 'email']], $schema['then']);
        self::assertIsArray($schema['properties']);
        self::assertArrayNotHasKey('editedArgs', $schema['properties'], 'A visitor cannot edit the offer.');
    }

    #[Test]
    public function anEditorMayEditTheProposalBeforeApproving(): void
    {
        $schema = self::interruptOf($this->propose('seo'))['responseSchema'];

        self::assertIsArray($schema);
        self::assertIsArray($schema['properties']);
        self::assertArrayHasKey('editedArgs', $schema['properties']);
        self::assertArrayNotHasKey('then', $schema);
    }

    #[Test]
    public function aScriptedRunSaysSo(): void
    {
        $scenario = (new Scenarios())->resolve('plan', Audience::Site);
        $events = iterator_to_array($this->runner()->propose(self::input('t', 'r'), $scenario, null, 'daily LLM budget reached'), false);

        self::assertSame(
            ['mode' => 'scripted', 'label' => 'Scripted demo', 'reason' => 'daily LLM budget reached'],
            self::first($events, 'CUSTOM')['value'],
        );
    }

    #[Test]
    public function aLiveModelWordsTheAnswerAndItsUseIsRecorded(): void
    {
        $model = self::createStub(LanguageModel::class);
        $model->method('getConnectionInfo')->willReturn(self::connection());
        $model->method('streamText')->willReturnCallback(static function (): \Generator {
            yield 'The Team plan ';
            yield '';
            yield 'fits.';
        });
        $model->method('estimateTokens')->willReturn(10);
        $ledger = $this->createMock(UsageLedger::class);
        $ledger->expects($this->once())->method('record')->with('agui', UsageLedger::SOURCE_FRONTEND, 'gpt-test');
        $scenario = (new Scenarios())->resolve('plan', Audience::Site);

        $events = iterator_to_array($this->runner($model, ledger: $ledger)->propose(self::input('t', 'r'), $scenario, new LlmPlan('', 300)), false);

        EventVerifier::verify($events, 't', 'r');
        $provenance = self::first($events, 'CUSTOM')['value'];
        self::assertIsArray($provenance);
        self::assertSame('llm', $provenance['mode']);
        self::assertSame('Live model · GPT test', $provenance['label']);
        $deltas = array_column(array_filter($events, static fn(array $event): bool => $event['type'] === 'TEXT_MESSAGE_CONTENT'), 'delta');
        self::assertSame(['The Team plan ', 'fits.'], array_values($deltas), 'Empty chunks are not sent.');
        self::assertSame('[{"provider":"openai","model":"gpt-test"}]', json_encode(self::first($events, 'RUN_FINISHED')['usage'] ?? null));
    }

    #[Test]
    public function aModelThatFailsBeforeItsFirstWordFallsBackToTheScript(): void
    {
        $model = self::createStub(LanguageModel::class);
        $model->method('getConnectionInfo')->willReturn(self::connection());
        $model->method('streamText')->willThrowException(new \RuntimeException('provider down', 1758700500));
        $scenario = (new Scenarios())->resolve('plan', Audience::Site);

        $events = iterator_to_array($this->runner($model)->propose(self::input('t', 'r'), $scenario, new LlmPlan('', 300)), false);

        EventVerifier::verify($events, 't', 'r');
        self::assertSame('For ', self::first($events, 'TEXT_MESSAGE_CONTENT')['delta']);
        self::assertArrayNotHasKey('usage', self::first($events, 'RUN_FINISHED'));
        $provenance = array_column(array_filter($events, static fn(array $event): bool => $event['type'] === 'CUSTOM'), 'value');
        self::assertCount(2, $provenance, 'The label is corrected once the model has failed.');
        self::assertSame('scripted', $provenance[array_key_last($provenance)]['mode'] ?? null);
    }

    #[Test]
    public function anApprovalRunsTheChangeAndAnswersTheOriginalToolCall(): void
    {
        [$events, $interrupt] = $this->resume('plan', ['status' => 'resolved', 'payload' => ['approved' => true, 'name' => ' Ada ', 'email' => 'ada@example.org']]);

        EventVerifier::verify($events, 'thread-1', 'run-2');
        self::assertNotContains('TOOL_CALL_START', self::types($events), 'The call is not proposed again.');
        self::assertSame($interrupt['toolCallId'], self::first($events, 'TOOL_CALL_RESULT')['toolCallId']);
        $finished = self::first($events, 'RUN_FINISHED');
        self::assertSame(['type' => 'success'], $finished['outcome']);
        self::assertIsArray($finished['result']);
        self::assertSame('sent', $finished['result']['status']);
        self::assertTrue($finished['result']['simulated']);
        self::assertSame(['name' => 'Ada', 'email' => 'ada@example.org'], $finished['result']['lead']);
    }

    #[Test]
    public function withReallyApplyOnTheChangeIsNotMarkedSimulated(): void
    {
        [$events] = $this->resume('seo', ['status' => 'resolved', 'payload' => ['approved' => true]], reallyApply: true);

        $result = self::first($events, 'RUN_FINISHED')['result'];
        self::assertIsArray($result);
        self::assertFalse($result['simulated']);
    }

    #[Test]
    public function aDenialAndACancellationChangeNothing(): void
    {
        [$declined] = $this->resume('plan', ['status' => 'resolved', 'payload' => ['approved' => false]]);
        [$cancelled] = $this->resume('plan', ['status' => 'cancelled']);

        foreach (['declined' => $declined, 'cancelled' => $cancelled] as $decision => $events) {
            EventVerifier::verify($events, 'thread-1', 'run-2');
            $result = self::first($events, 'RUN_FINISHED')['result'];
            self::assertIsArray($result);
            self::assertSame('declined', $result['status']);
            self::assertSame($decision, $result['decision']);
            self::assertArrayNotHasKey('lead', $result);
        }
    }

    #[Test]
    public function editedArgumentsReplaceTheProposal(): void
    {
        [$events] = $this->resume('seo', ['status' => 'resolved', 'payload' => ['approved' => true, 'editedArgs' => [
            'table' => 'pages',
            'uid' => 42,
            'page' => 'Pricing',
            'field' => 'description',
            'value' => 'Shorter.',
        ]]]);

        $result = self::first($events, 'RUN_FINISHED')['result'];
        self::assertIsArray($result);
        self::assertIsArray($result['arguments']);
        self::assertSame('Shorter.', $result['arguments']['value']);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function invalidAnswers(): iterable
    {
        yield 'no email' => ['plan', ['approved' => true, 'name' => 'Ada'], 'valid email'];
        yield 'an email that is none' => ['plan', ['approved' => true, 'name' => 'Ada', 'email' => 'ada'], 'valid email'];
        yield 'no preferred time' => ['support', ['approved' => true, 'name' => 'Ada', 'email' => 'ada@example.org'], 'preferred time'];
        yield 'no decision' => ['plan', ['name' => 'Ada'], 'approve'];
        yield 'an edit a visitor may not make' => ['plan', ['approved' => true, 'name' => 'Ada', 'email' => 'ada@example.org', 'editedArgs' => ['price' => 0]], 'cannot be edited'];
        yield 'an edit that adds an argument' => ['seo', ['approved' => true, 'editedArgs' => ['sql' => 'DROP TABLE pages']], 'not add sql'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataProvider('invalidAnswers')]
    public function anInvalidAnswerFailsTheRunAndChangesNothing(string $preset, array $payload, string $message): void
    {
        [$events] = $this->resume($preset, ['status' => 'resolved', 'payload' => $payload]);

        EventVerifier::verify($events, 'thread-1', 'run-2');
        self::assertSame(['RUN_STARTED', 'RUN_ERROR'], self::types($events));
        self::assertSame('invalid_answer', $events[1]['code']);
        self::assertIsString($events[1]['message']);
        self::assertStringContainsString($message, $events[1]['message']);
    }

    /**
     * Propose, then resume the interrupt with `$answer`.
     *
     * @param array<string, mixed> $answer resume entry without its interruptId
     * @return array{list<array<string, mixed>>, array<string, mixed>}
     */
    private function resume(string $preset, array $answer, bool $reallyApply = false): array
    {
        $proposal = $this->propose($preset);
        $interrupt = self::interruptOf($proposal);
        $input = self::input('thread-1', 'run-2', ['resume' => [['interruptId' => $interrupt['id']] + $answer]], '');
        $resumption = (new ThreadState([self::record($proposal, self::input('thread-1', 'run-1'))]))->resume($input->resume);
        $scenario = (new Scenarios())->get($resumption->preset);
        self::assertNotNull($scenario);

        return [iterator_to_array($this->runner(reallyApply: $reallyApply)->resume($input, $scenario, $resumption), false), $interrupt];
    }

    /**
     * @return array{provider: string, adapter: string, endpoint: string, model: string, modelId: string, priceInput: string, priceOutput: string, hasPricing: bool}
     */
    private static function connection(): array
    {
        return [
            'provider' => 'OpenAI',
            'adapter' => 'openai',
            'endpoint' => '',
            'model' => 'GPT test',
            'modelId' => 'gpt-test',
            'priceInput' => '',
            'priceOutput' => '',
            'hasPricing' => false,
        ];
    }
}
