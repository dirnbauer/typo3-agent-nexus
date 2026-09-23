<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Agui;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Agui\Controller\AguiModuleController;
use Webconsulting\AgentNexus\Agui\Protocol\EventType;
use Webconsulting\AgentNexus\Agui\Protocol\OrderingRule;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Tests\Functional\Backend\AbstractBackendModuleTestCase;

/**
 * The two AG-UI screens as a backend admin sees them.
 */
final class AguiModuleTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function theConsoleOffersBothAgentsAndPointsAtBothEndpoints(): void
    {
        $html = self::body($this->get(AguiModuleController::class)->consoleAction($this->moduleRequest('agentnexus_agui_console')));

        self::assertStringContainsString('<h1>Run console</h1>', $html);
        foreach (['seo', 'translate', 'news', 'plan', 'support'] as $preset) {
            self::assertMatchesRegularExpression('/<option value="' . $preset . '" data-audience="(editor|site)"/', $html);
        }
        self::assertStringContainsString('data-endpoint="https://agent-nexus.test/api/agent-nexus/ag-ui"', $html);
        self::assertMatchesRegularExpression('/data-run-url="[^"]*agentnexus[^"]*agui[^"]*run/', $html);
        self::assertStringContainsString('for="agui-preset"', $html, 'Every control is labelled.');
        self::assertStringContainsString('for="agui-message"', $html);
        self::assertStringContainsString('No runs yet', $html);
    }

    #[Test]
    public function recentRunsLinkToTheInspector(): void
    {
        $this->get(ObjectStore::class)->save((new ProtocolObject(
            kind: ObjectKind::Run,
            objectId: 'run-console-1',
            contextId: 'thread-console',
            source: 'backend',
            label: 'seo · Write a meta description',
            payload: ['input' => ['threadId' => 'thread-console', 'runId' => 'run-console-1', 'messages' => []], 'eventCount' => 68],
        ))->withState('interrupted'));

        $html = self::body($this->get(AguiModuleController::class)->consoleAction($this->moduleRequest('agentnexus_agui_console')));

        self::assertStringContainsString('run-console-1', $html);
        self::assertStringContainsString('Waiting for approval', $html);
        self::assertMatchesRegularExpression('#href="[^"]*inspector/runs/detail[^"]*"#', $html);
    }

    #[Test]
    public function theEventReferencePrintsTheWholeCatalogue(): void
    {
        $html = self::body($this->get(AguiModuleController::class)->eventsAction($this->moduleRequest('agentnexus_agui_events')));

        self::assertStringContainsString('<h1>AG-UI 1.0 events</h1>', $html);
        foreach (EventType::cases() as $type) {
            self::assertStringContainsString('<code>' . $type->value . '</code>', $html);
        }
        foreach (OrderingRule::cases() as $rule) {
            self::assertStringContainsString('<code>' . $rule->value . '</code>', $html);
        }
        self::assertStringContainsString('curl -N', $html);
        self::assertStringContainsString('https://agent-nexus.test/api/agent-nexus/ag-ui', $html);
    }
}
