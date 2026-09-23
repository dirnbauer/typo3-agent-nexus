<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\A2a;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\A2a\Controller\A2aModuleController;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Tests\Functional\Backend\AbstractBackendModuleTestCase;

/**
 * The two A2A screens, rendered for a backend admin the way the backend does.
 */
final class A2aModuleTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function theConsoleCallsThePublicEndpointOfThisHost(): void
    {
        $html = self::body($this->get(A2aModuleController::class)->consoleAction($this->moduleRequest('agentnexus_a2a_console')));

        self::assertStringContainsString('<h1>Task console</h1>', $html);
        self::assertSame(1, substr_count($html, '<h1'), 'One h1 per screen.');
        self::assertStringContainsString('data-jsonrpc-url="https://agent-nexus.test/api/agent-nexus/a2a/jsonrpc"', $html);
        self::assertStringContainsString('data-card-url="https://agent-nexus.test/.well-known/agent-card.json"', $html);
        foreach (['summarize_page', 'draft_outreach', 'plan_onboarding'] as $skill) {
            self::assertStringContainsString('id="anx-a2a-skill-' . $skill . '"', $html, 'A skill preset per catalogue skill.');
        }
        self::assertStringContainsString('<label class="form-label" for="anx-a2a-message">', $html, 'Every control has a label.');
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('No tasks yet', $html, 'An empty store shows the empty state.');
    }

    #[Test]
    public function theConsoleListsRecentTasksWithInspectorLinks(): void
    {
        $this->get(ObjectStore::class)->save(new ProtocolObject(
            ObjectKind::Task,
            'task-recent-1',
            'ctx-1',
            'TASK_STATE_INPUT_REQUIRED',
            'widget',
            'Draft an outreach email',
            ['id' => 'task-recent-1', 'contextId' => 'ctx-1', 'status' => ['state' => 'TASK_STATE_INPUT_REQUIRED']],
        ));

        $html = self::body($this->get(A2aModuleController::class)->consoleAction($this->moduleRequest('agentnexus_a2a_console')));

        self::assertStringContainsString('task-recent-1', $html);
        self::assertStringContainsString('Input required', $html, 'The state reads as words, not only as a colour.');
        self::assertStringContainsString('Draft an outreach email', $html);
        self::assertMatchesRegularExpression('#href="[^"]*inspector/tasks/detail[^"]*uid=\d+#', $html);
    }

    #[Test]
    public function theCardScreenShowsThePublishedCardAndItsTables(): void
    {
        $html = self::body($this->get(A2aModuleController::class)->cardAction($this->moduleRequest('agentnexus_a2a_card')));

        self::assertStringContainsString('<h1>Agent Card</h1>', $html);
        self::assertStringContainsString('https://agent-nexus.test/api/agent-nexus/a2a/rest', $html, 'The HTTP+JSON interface.');
        self::assertSame(2, substr_count($html, '<code>JSONRPC</code>'), 'JSON-RPC is listed for 1.0 and 0.3.');
        self::assertStringContainsString('&quot;supportedInterfaces&quot;', $html, 'The card JSON, escaped.');
        foreach (['SubscribeToTask', 'tasks/resubscribe', 'TASK_STATE_INPUT_REQUIRED', 'input-required', 'VersionNotSupportedError', '-32009', 'Who is the audience?'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertStringContainsString('/api/agent-nexus/a2a/rest/tasks/{id}:cancel', $html, 'The endpoints come from the routes.');
    }
}
