<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2a\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2a\Protocol\Message;
use Webconsulting\AgentNexus\A2a\Protocol\Part;
use Webconsulting\AgentNexus\A2a\Protocol\Role;
use Webconsulting\AgentNexus\A2a\Protocol\Task;
use Webconsulting\AgentNexus\A2a\Protocol\TaskState;
use Webconsulting\AgentNexus\A2a\Protocol\TaskStatus;
use Webconsulting\AgentNexus\A2a\Server\CallContext;
use Webconsulting\AgentNexus\A2a\Service\PlanStep;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\A2a\Service\TaskRunner;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;

/**
 * How the agent decides: which skill a request goes to, what it says and
 * when a model is allowed to change that. Without a model the agent routes by
 * keyword and never picks a skill outside the catalogue.
 */
final class TaskRunnerTest extends UnitTestCase
{
    #[Test]
    #[DataProvider('keywordRoutingProvider')]
    public function keywordRoutingPicksTheSkillWhenNoModelIsAvailable(string $request, string $expectedSkill): void
    {
        $plan = $this->runner()->plan($this->task(), $this->message($request), false, new CallContext());

        self::assertSame($expectedSkill, $plan->skillId);
        self::assertSame('keywords', $plan->taskMetadata['routedBy']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function keywordRoutingProvider(): array
    {
        return [
            'email wording routes to outreach' => ['Write an email to our agency customers', 'draft_outreach'],
            'outreach wording routes to outreach' => ['Some outreach for lapsed users please', 'draft_outreach'],
            'onboarding wording routes to planning' => ['Can you plan the onboarding for a new team?', 'plan_onboarding'],
            'anything else falls back to summarising' => ['Tell me about the pricing page', 'summarize_page'],
            'an empty request still routes somewhere valid' => ['', 'summarize_page'],
        ];
    }

    #[Test]
    public function aSkillPinnedInTheMessageMetadataWinsOverKeywords(): void
    {
        $plan = $this->runner()->plan($this->task(), $this->message('Write an email', ['skill' => 'plan_onboarding']), false, new CallContext());

        self::assertSame('plan_onboarding', $plan->skillId);
        self::assertSame('request', $plan->taskMetadata['routedBy']);
    }

    #[Test]
    public function aPinnedSkillOutsideTheCatalogueIsIgnored(): void
    {
        $plan = $this->runner()->plan($this->task(), $this->message('Write an email', ['skill' => 'delete_everything']), false, new CallContext());

        self::assertSame('draft_outreach', $plan->skillId);
    }

    #[Test]
    public function aSkillThatNeedsInputPlansTheQuestionAndNoArtifact(): void
    {
        $plan = $this->runner()->plan($this->task(), $this->message('Draft an outreach email'), false, new CallContext());

        self::assertSame([TaskState::Working, TaskState::InputRequired], array_map(static fn(PlanStep $step): ?TaskState => $step->state, $plan->steps));
        self::assertSame((new SkillCatalog())->get('draft_outreach')['inputPrompt'], $plan->steps[1]->text);
    }

    #[Test]
    public function theAnswerToTheQuestionContinuesTheRoutedSkill(): void
    {
        $task = $this->task()->withMetadata(['skillId' => 'draft_outreach']);

        $plan = $this->runner()->plan($task, $this->message('Lapsed customers'), true, new CallContext());

        self::assertSame('draft_outreach', $plan->skillId);
        self::assertSame([TaskState::Working, null, TaskState::Completed], array_map(static fn(PlanStep $step): ?TaskState => $step->state, $plan->steps));
        self::assertStringContainsString('(for: Lapsed customers)', $plan->steps[0]->text);
        self::assertTrue($plan->steps[1]->isArtifact());
    }

    #[Test]
    public function withoutAModelTheArtifactIsTheScriptedExampleAndSaysSo(): void
    {
        $plan = $this->runner()->plan($this->task(), $this->message('Summarise the pricing page'), false, new CallContext());
        $artifact = $plan->steps[1]->produceArtifact();

        self::assertSame((new SkillCatalog())->get('summarize_page')['artifactText'], $artifact->text());
        self::assertSame(['writtenBy' => 'script'], $artifact->metadata);
        self::assertSame('text/markdown', $artifact->parts[0]->mediaType);
    }

    #[Test]
    public function aModelMayRouteAndExplainWhy(): void
    {
        $model = self::createStub(LanguageModel::class);
        $model->method('completeJson')->willReturn([
            'data' => ['skill' => 'plan_onboarding', 'rationale' => 'The request is about the first week.'],
            'promptTokens' => 10, 'completionTokens' => 5, 'cost' => null, 'model' => 'test-model',
        ]);
        $ledger = $this->createMock(UsageLedger::class);
        $ledger->expects($this->once())->method('record')->with('a2a', UsageLedger::SOURCE_FRONTEND, 'test-model', 10, 5, null);

        $plan = (new TaskRunner(new SkillCatalog(), $model, $ledger))
            ->plan($this->task(), $this->message('What happens in week one?'), false, new CallContext(Channel::Widget, 0, true));

        self::assertSame('plan_onboarding', $plan->skillId);
        self::assertSame('model', $plan->taskMetadata['routedBy']);
        self::assertStringContainsString('The request is about the first week.', $plan->steps[0]->text);
    }

    #[Test]
    public function theRationaleStaysHiddenWhenTheElementSaysSo(): void
    {
        $model = self::createStub(LanguageModel::class);
        $model->method('completeJson')->willReturn([
            'data' => ['skill' => 'plan_onboarding', 'rationale' => 'Because.'],
            'promptTokens' => 1, 'completionTokens' => 1, 'cost' => null, 'model' => 'm',
        ]);

        $plan = (new TaskRunner(new SkillCatalog(), $model, self::createStub(UsageLedger::class)))
            ->plan($this->task(), $this->message('Week one?'), false, new CallContext(Channel::Widget, 0, true, false));

        self::assertSame((new SkillCatalog())->get('plan_onboarding')['workingText'], $plan->steps[0]->text);
    }

    #[Test]
    public function aModelAnswerOutsideTheCatalogueFallsBackToKeywords(): void
    {
        $model = self::createStub(LanguageModel::class);
        $model->method('completeJson')->willReturn([
            'data' => ['skill' => 'transfer_money'],
            'promptTokens' => 1, 'completionTokens' => 1, 'cost' => null, 'model' => 'm',
        ]);

        $plan = (new TaskRunner(new SkillCatalog(), $model, self::createStub(UsageLedger::class)))
            ->plan($this->task(), $this->message('Write an email'), false, new CallContext(Channel::Widget, 0, true));

        self::assertSame('draft_outreach', $plan->skillId);
        self::assertSame('keywords', $plan->taskMetadata['routedBy']);
    }

    #[Test]
    public function aFailingModelFallsBackToTheScript(): void
    {
        $model = self::createStub(LanguageModel::class);
        $model->method('completeJson')->willThrowException(new \RuntimeException('Provider down'));
        $model->method('completeText')->willThrowException(new \RuntimeException('Provider down'));

        $plan = (new TaskRunner(new SkillCatalog(), $model, self::createStub(UsageLedger::class)))
            ->plan($this->task(), $this->message('Summarise the pricing page'), false, new CallContext(Channel::Widget, 0, true));
        $artifact = $plan->steps[1]->produceArtifact();

        self::assertSame('summarize_page', $plan->skillId);
        self::assertSame(['writtenBy' => 'script'], $artifact->metadata);
    }

    #[Test]
    public function aModelWrittenArtifactNamesTheModel(): void
    {
        $model = self::createStub(LanguageModel::class);
        $model->method('completeJson')->willThrowException(new \RuntimeException('no routing today'));
        $model->method('completeText')->willReturn([
            'text' => "## Summary\n\nShort.", 'promptTokens' => 1, 'completionTokens' => 1, 'cost' => 0.001, 'model' => 'test-model',
        ]);

        $plan = (new TaskRunner(new SkillCatalog(), $model, self::createStub(UsageLedger::class)))
            ->plan($this->task(), $this->message('Summarise the pricing page'), false, new CallContext(Channel::Widget, 0, true));
        $artifact = $plan->steps[1]->produceArtifact();

        self::assertSame("## Summary\n\nShort.", $artifact->text());
        self::assertSame(['writtenBy' => 'model', 'model' => 'test-model'], $artifact->metadata);
    }

    private function runner(): TaskRunner
    {
        return new TaskRunner(new SkillCatalog(), self::createStub(LanguageModel::class), self::createStub(UsageLedger::class));
    }

    private function task(): Task
    {
        return new Task('task-1', 'ctx-1', TaskStatus::now(TaskState::Submitted));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function message(string $text, array $metadata = []): Message
    {
        return new Message('m-1', Role::User, [Part::text($text)], 'ctx-1', 'task-1', $metadata);
    }
}
