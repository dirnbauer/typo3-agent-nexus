<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Ucp;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Tests\Functional\Backend\AbstractBackendModuleTestCase;
use Webconsulting\AgentNexus\Ucp\Controller\UcpModuleController;

/**
 * The two UCP screens render for a backend user, with what their scripts and
 * their readers need.
 */
final class UcpModuleTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function theConsolePointsItsScriptAtThePublicEndpoints(): void
    {
        $html = self::body($this->get(UcpModuleController::class)->consoleAction($this->moduleRequest('agentnexus_ucp_console')));

        self::assertStringContainsString('data-agent-url="https://agent-nexus.test/api/agent-nexus/ucp/agent"', $html);
        self::assertStringContainsString('data-rest-endpoint="https://agent-nexus.test/api/agent-nexus/ucp"', $html);
        self::assertStringContainsString('data-platform-profile="https://agent-nexus.test/api/agent-nexus/ucp/platform-profile"', $html);
        self::assertStringContainsString('<h1>Checkout console</h1>', $html);
        self::assertStringContainsString('Approve this order', $html);
        self::assertStringContainsString('Desiderio Pro Licence · €49.00', $html);
        self::assertStringContainsString('Agency Bundle, Onboarding Add-on · €448.00', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringContainsString('No checkout sessions yet', $html, 'An empty installation says so and offers the first action.');
    }

    #[Test]
    public function theConsoleListsRecentSessionsWithLinksToTheInspector(): void
    {
        $this->get(ObjectStore::class)->save((new ProtocolObject(
            ObjectKind::Checkout,
            'chk_0123456789abcdef01234567',
            'thread-1',
            'ready_for_complete',
            'agent',
            '€49.00 · 1 item',
            ['id' => 'chk_0123456789abcdef01234567', 'status' => 'ready_for_complete'],
        ))->withState('ready_for_complete'));

        $html = self::body($this->get(UcpModuleController::class)->consoleAction($this->moduleRequest('agentnexus_ucp_console')));

        self::assertStringContainsString('chk_0123456789abcdef01234567', $html);
        self::assertStringContainsString('€49.00 · 1 item', $html);
        self::assertStringContainsString('Ready to complete', $html);
        self::assertStringContainsString('/module/agent-nexus/inspector/checkouts/detail', $html);
    }

    #[Test]
    public function theProfileScreenShowsTheValidProfileAndTheCatalogue(): void
    {
        $html = self::body($this->get(UcpModuleController::class)->profileAction($this->moduleRequest('agentnexus_ucp_profile')));

        self::assertStringContainsString('<h1>Business profile</h1>', $html);
        self::assertStringContainsString('Valid', $html);
        self::assertStringNotContainsString('Problems found', $html);
        self::assertStringContainsString('https://agent-nexus.test/.well-known/ucp', $html);
        self::assertStringContainsString('dev.ucp.shopping.checkout', $html);
        self::assertStringContainsString('at.webconsulting.sandbox_pay', $html);
        self::assertStringContainsString('sandbox-decline', $html);
        self::assertStringContainsString('Priority Support Pack', $html);
        self::assertStringContainsString('€299.00', $html);
        self::assertStringContainsString('/api/agent-nexus/ucp/checkout-sessions/{id}/complete', $html);
    }
}
