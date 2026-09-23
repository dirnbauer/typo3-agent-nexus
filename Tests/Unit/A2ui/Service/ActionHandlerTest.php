<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\RendererMessage;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\A2ui\Http\A2uiProblem;
use Webconsulting\AgentNexus\A2ui\Service\ActionHandler;
use Webconsulting\AgentNexus\A2ui\Service\MessageBuilder;
use Webconsulting\AgentNexus\A2ui\Service\RendererMessageParser;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceGenerator;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceSanitizer;

final class ActionHandlerTest extends UnitTestCase
{
    private ActionHandler $subject;

    /** @var list<array<string, mixed>> */
    private array $sent;

    protected function setUp(): void
    {
        parent::setUp();
        $sanitizer = new SurfaceSanitizer(new ComponentRegistry());
        $this->subject = new ActionHandler(new MessageBuilder(), $sanitizer);
        $surface = (new SurfaceGenerator())->example('quote')->withSurfaceId('quote-1');
        $this->sent = (new MessageBuilder())->surface($surface, A2uiVersion::V0_9_1);
    }

    #[Test]
    public function theMainActionIsAnsweredWithAConfirmationView(): void
    {
        $outcome = $this->subject->answer($this->action('requestQuote', 'submit', [
            'name' => 'Ada',
            'email' => 'ada@example.org',
            'projectType' => ['implementation'],
            'budget' => ['10to50k'],
            'details' => '',
        ]), $this->sent, 'Thanks, we will call you.');

        self::assertSame('submitted', $outcome->state);
        self::assertCount(2, $outcome->messages);
        self::assertArrayHasKey('updateComponents', $outcome->messages[0]);
        $components = $outcome->messages[0]['updateComponents']['components'];
        $root = array_values(array_filter($components, static fn(array $c): bool => $c['id'] === 'root'))[0];
        self::assertSame(['id' => 'root', 'component' => 'Card', 'child' => 'confirmation'], $root);
        self::assertContains('Thanks, we will call you.', array_column($components, 'text'));

        $update = $outcome->messages[1]['updateDataModel'];
        self::assertSame('/confirmation', $update['path']);
        self::assertMatchesRegularExpression('/^A2UI-[0-9A-F]{6}$/', $update['value']['reference']);
        self::assertSame([
            ['label' => 'Your name', 'value' => 'Ada'],
            ['label' => 'Email', 'value' => 'ada@example.org'],
            ['label' => 'What do you need?', 'value' => 'Implementation'],
            ['label' => 'Indicative budget', 'value' => '€10k to €50k'],
        ], $update['value']['summary'], 'Labels and option labels come from the surface; empty values are left out.');
    }

    #[Test]
    public function withoutAConfiguredTextTheDefaultConfirmationIsUsed(): void
    {
        $outcome = $this->subject->answer($this->action('requestQuote', 'submit', []), $this->sent);

        self::assertContains(ActionHandler::DEFAULT_CONFIRMATION, array_column($outcome->messages[0]['updateComponents']['components'], 'text'));
    }

    #[Test]
    public function discardIsAnsweredWithDeleteSurface(): void
    {
        $outcome = $this->subject->answer($this->action('discard', 'discard', []), $this->sent);

        self::assertSame('deleted', $outcome->state);
        self::assertSame([['version' => 'v0.9.1', 'deleteSurface' => ['surfaceId' => 'quote-1']]], $outcome->messages);
    }

    #[Test]
    public function aModalTriggerNeedsNoAnswer(): void
    {
        $surface = (new SurfaceGenerator())->example('newsletter')->withSurfaceId('news-1');
        $sent = (new MessageBuilder())->surface($surface, A2uiVersion::V0_9_1);

        $outcome = $this->subject->answer($this->action('openPrivacyNote', 'privacy_open', [], 'news-1'), $sent);

        self::assertSame([], $outcome->messages);
        self::assertNull($outcome->state);
    }

    #[Test]
    public function anActionFromAComponentTheSurfaceDoesNotHaveIsRefused(): void
    {
        try {
            $this->subject->answer($this->action('requestQuote', 'elsewhere', []), $this->sent);
            self::fail('Expected a refusal.');
        } catch (A2uiProblem $problem) {
            self::assertSame(A2uiProblem::VALIDATION_FAILED, $problem->errorCode);
            self::assertSame('/action/sourceComponentId', $problem->path);
            self::assertSame('quote-1', $problem->surfaceId);
        }
    }

    #[Test]
    public function anActionTheComponentDoesNotSendIsRefused(): void
    {
        try {
            $this->subject->answer($this->action('deleteEverything', 'submit', []), $this->sent);
            self::fail('Expected a refusal.');
        } catch (A2uiProblem $problem) {
            self::assertSame('/action/name', $problem->path);
        }
    }

    #[Test]
    public function aReportedErrorIsOnlyRecorded(): void
    {
        $message = (new RendererMessageParser())->parse([
            'version' => 'v0.9.1',
            'error' => ['code' => 'VALIDATION_FAILED', 'surfaceId' => 'quote-1', 'path' => '/components/0', 'message' => 'x'],
        ]);

        $outcome = $this->subject->answer($message, $this->sent);

        self::assertSame([], $outcome->messages);
        self::assertNull($outcome->state);
        self::assertStringContainsString('VALIDATION_FAILED', $outcome->note);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function action(string $name, string $source, array $context, string $surfaceId = 'quote-1'): RendererMessage
    {
        return (new RendererMessageParser())->parse(['version' => 'v0.9.1', 'action' => [
            'name' => $name,
            'surfaceId' => $surfaceId,
            'sourceComponentId' => $source,
            'timestamp' => '2026-09-23T10:15:00Z',
            'context' => $context,
        ]]);
    }
}
