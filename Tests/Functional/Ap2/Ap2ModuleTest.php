<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Ap2;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Webconsulting\AgentNexus\Ap2\Controller\Ap2ModuleController;
use Webconsulting\AgentNexus\Ap2\Mandate\CheckType;
use Webconsulting\AgentNexus\Ap2\Mandate\ConstraintType;
use Webconsulting\AgentNexus\Ap2\Mandate\MandateType;
use Webconsulting\AgentNexus\Ap2\Sandbox\AutonomousFlow;
use Webconsulting\AgentNexus\Ap2\Sandbox\KeyRing;
use Webconsulting\AgentNexus\Ap2\Sandbox\RecordingContext;
use Webconsulting\AgentNexus\Ap2\Sandbox\Role;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Tests\Functional\Backend\AbstractBackendModuleTestCase;

/**
 * The two AP2 screens render as a backend admin sees them.
 */
final class Ap2ModuleTest extends AbstractBackendModuleTestCase
{
    #[Test]
    public function theStudioOffersTheFormsTheKeysAndAnEmptyState(): void
    {
        $html = self::body($this->get(Ap2ModuleController::class)->studioAction($this->moduleRequest('agentnexus_ap2_studio')));

        self::assertStringContainsString('<h1>Mandate studio</h1>', $html);
        self::assertStringContainsString('data-ap2-studio', $html);
        self::assertStringContainsString('data-authorize-url="https://agent-nexus.test/api/agent-nexus/ap2/authorize"', $html);
        self::assertStringContainsString('href="https://agent-nexus.test/api/agent-nexus/ap2/jwks.json"', $html);
        foreach (MandateType::cases() as $type) {
            self::assertStringContainsString('value="' . $type->key() . '"', $html);
        }
        foreach (Role::cases() as $role) {
            self::assertStringContainsString($this->get(KeyRing::class)->signer($role)->kid, $html);
        }
        self::assertStringContainsString('No mandates yet.', $html);
        self::assertStringContainsString('aria-live="polite"', $html);
        self::assertStringNotContainsString('ddev.site', $html, 'No hard-coded lab URLs.');
    }

    #[Test]
    public function theStudioListsWhatTheFlowSigned(): void
    {
        $this->get(AutonomousFlow::class)->run(50000, AutonomousFlow::WITHIN, 'desiderio-store', new RecordingContext(Channel::Backend));

        $html = self::body($this->get(Ap2ModuleController::class)->studioAction($this->moduleRequest('agentnexus_ap2_studio')));

        self::assertStringContainsString('mandate.payment.open.1 · up to €500.00', $html);
        self::assertStringContainsString('payment_receipt · Success', $html);
        self::assertStringContainsString('badge badge-success', $html);
        self::assertStringNotContainsString('No mandates yet.', $html);
    }

    #[Test]
    public function theReferenceIsBuiltFromTheCatalogue(): void
    {
        $html = self::body($this->get(Ap2ModuleController::class)->referenceAction($this->moduleRequest('agentnexus_ap2_reference')));

        self::assertStringContainsString('<h1>Mandate reference</h1>', $html);
        foreach (MandateType::cases() as $type) {
            self::assertStringContainsString('<code>' . $type->value . '</code>', $html);
            self::assertStringContainsString($type->schemaId(), $html);
        }
        foreach (ConstraintType::cases() as $constraint) {
            self::assertStringContainsString('<code>' . $constraint->value . '</code>', $html);
        }
        self::assertStringContainsString('Same approved merchant', $html);
        self::assertStringContainsString('Within the spending cap', $html);
        self::assertSame(count(CheckType::cases()), substr_count($html, 'id="check-row-'), 'One row per check.');
        foreach (['unresolved_constraint', 'mandate_scope_mismatch', 'checkout_hash', 'network_confirmation_id'] as $term) {
            self::assertStringContainsString($term, $html);
        }
    }

    #[Test]
    public function theReferenceIsTranslated(): void
    {
        $GLOBALS['BE_USER']->user['lang'] = 'de';
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('de');

        $html = self::body($this->get(Ap2ModuleController::class)->referenceAction($this->moduleRequest('agentnexus_ap2_reference')));

        self::assertStringContainsString('<h1>Mandatsreferenz</h1>', $html);
        self::assertStringContainsString('Derselbe genehmigte Händler', $html);
    }
}
