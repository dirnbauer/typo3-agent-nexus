<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2a\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\A2a\Service\TaskRunner;
use Webconsulting\AgentNexus\Shared\Llm\LlmClient;
use Webconsulting\AgentNexus\Shared\Llm\LlmUsageTracker;

/**
 * The catalogue is the single source of truth behind the Agent Card, the
 * presets and the deterministic agent, so its shape is contractual. The routing
 * tests cover the path that runs when no model is available: keyword matching,
 * then a safe default — a request must never route to a skill that is not in
 * the catalogue.
 */
final class SkillCatalogTest extends UnitTestCase
{
    private SkillCatalog $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SkillCatalog();
    }

    #[Test]
    public function everySkillCarriesTheFieldsTheAgentCardAndTheRunnerNeed(): void
    {
        foreach ($this->subject->all() as $id => $skill) {
            self::assertSame($id, $skill['id'], 'The array key and the id must agree.');
            foreach (['name', 'description', 'tags', 'examples', 'workingText', 'artifactName', 'artifactText', 'completedText'] as $field) {
                self::assertArrayHasKey($field, $skill, $id . ' is missing ' . $field);
                self::assertNotEmpty($skill[$field], $id . '.' . $field . ' must not be empty');
            }
            self::assertArrayHasKey('inputPrompt', $skill);
        }
    }

    #[Test]
    public function anUnknownSkillIdFallsBackToSummarisingRatherThanFailing(): void
    {
        self::assertSame('summarize_page', $this->subject->get('no_such_skill')['id']);
    }

    #[Test]
    public function aSkillThatAsksForMoreDetailDeclaresAResumeText(): void
    {
        foreach ($this->subject->all() as $id => $skill) {
            if ($skill['inputPrompt'] !== null) {
                self::assertArrayHasKey('resumeText', $skill, $id . ' pauses for input but never says what happens next');
            }
        }
    }

    #[Test]
    #[DataProvider('keywordRoutingProvider')]
    public function keywordRoutingPicksTheSkillWhenNoModelIsAvailable(string $request, string $expectedSkill): void
    {
        $runner = new TaskRunner(
            $this->subject,
            $this->createMock(LlmClient::class),
            $this->createMock(LlmUsageTracker::class),
        );

        $frames = iterator_to_array($runner->run([
            'message' => ['parts' => [['kind' => 'text', 'text' => $request]]],
            '_llm' => false,
        ], 'test'), false);

        self::assertSame($expectedSkill, $this->routedSkill($frames));
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
    public function anExplicitSkillInTheMessageMetadataWinsOverKeywords(): void
    {
        $runner = new TaskRunner(
            $this->subject,
            $this->createMock(LlmClient::class),
            $this->createMock(LlmUsageTracker::class),
        );

        $frames = iterator_to_array($runner->run([
            'message' => [
                'parts' => [['kind' => 'text', 'text' => 'Write an email']],
                'metadata' => ['skill' => 'plan_onboarding'],
            ],
            '_llm' => false,
        ], 'test'), false);

        self::assertSame('plan_onboarding', $this->routedSkill($frames));
    }

    /**
     * The runner reports where it routed in the metadata of the first working
     * status, so a client can resume a server-routed task.
     *
     * @param list<array<string, mixed>> $frames
     */
    private function routedSkill(array $frames): string
    {
        foreach ($frames as $frame) {
            $metadata = $frame['result']['status']['message']['metadata'] ?? null;
            if (is_array($metadata) && isset($metadata['skill'])) {
                return (string)$metadata['skill'];
            }
        }
        self::fail('No frame reported a routed skill.');
    }
}
