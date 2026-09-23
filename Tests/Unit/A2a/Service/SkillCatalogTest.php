<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2a\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;

/**
 * The catalogue is the single source of truth behind the Agent Card, the
 * console presets, the concierge's chips and the deterministic agent, so its
 * shape is contractual.
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
                self::assertNotEmpty($skill[$field], $id . '.' . $field . ' must not be empty');
            }
            self::assertArrayHasKey('inputPrompt', $skill);
        }
    }

    #[Test]
    public function anUnknownSkillIdFallsBackToSummarisingRatherThanFailing(): void
    {
        self::assertSame('summarize_page', $this->subject->get('no_such_skill')['id']);
        self::assertFalse($this->subject->has('no_such_skill'));
        self::assertTrue($this->subject->has('draft_outreach'));
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
    public function exactlyOneSkillPausesForInput(): void
    {
        $pausing = array_filter($this->subject->all(), static fn(array $skill): bool => $skill['inputPrompt'] !== null);

        self::assertSame(['draft_outreach'], array_keys($pausing), 'The input-required state must not be theoretical.');
    }
}
