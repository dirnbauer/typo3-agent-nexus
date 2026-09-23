<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Upgrades;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleRegistry;
use Webconsulting\AgentNexus\Agentstack\Upgrades\ModulePermissionsUpgradeWizard;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

final class ModulePermissionsUpgradeWizardTest extends AbstractAgentNexusTestCase
{
    private ModulePermissionsUpgradeWizard $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(ModulePermissionsUpgradeWizard::class);
    }

    #[Test]
    public function everyTargetModuleExists(): void
    {
        $registry = $this->get(ModuleRegistry::class);
        foreach (ModulePermissionsUpgradeWizard::RENAMES as $old => $new) {
            self::assertTrue($registry->hasModule($old), $old . ' must stay reachable as an alias');
            foreach ($new as $module) {
                self::assertTrue($registry->hasModule($module), $module . ' is not a module');
            }
        }
    }

    #[Test]
    public function itIsNotNecessaryWithoutOldIdentifiers(): void
    {
        $this->group(1, 'web_layout,agentnexus_overview');

        self::assertFalse($this->subject->updateNecessary());
    }

    #[Test]
    public function aProtocolModuleBecomesItsSectionAndEveryScreen(): void
    {
        $this->group(1, 'web_layout,agentstack_a2a,agentstack_overview');
        $this->user(2, 'agentstack_ucp');

        self::assertTrue($this->subject->updateNecessary());
        self::assertTrue($this->subject->executeUpdate());

        self::assertSame('web_layout,agentnexus_a2a,agentnexus_a2a_console,agentnexus_a2a_card,agentnexus_overview', $this->field('be_groups', 'groupMods', 1));
        self::assertSame('agentnexus_ucp,agentnexus_ucp_console,agentnexus_ucp_profile', $this->field('be_users', 'userMods', 2));
        self::assertFalse($this->subject->updateNecessary(), 'A second run has nothing to do.');
    }

    #[Test]
    public function itDoesNotGrantTheInspectorOrTheTrafficLog(): void
    {
        self::assertStringNotContainsString('inspector', (string)ModulePermissionsUpgradeWizard::migrateList('agentstack,agentstack_overview,agentstack_a2ui,agentstack_agui,agentstack_a2a,agentstack_ucp,agentstack_ap2'));
        self::assertStringNotContainsString('traffic', (string)ModulePermissionsUpgradeWizard::migrateList('agentstack_ap2'));
    }

    #[Test]
    public function duplicatesCollapse(): void
    {
        self::assertSame('agentnexus_overview', ModulePermissionsUpgradeWizard::migrateList('agentstack_overview,agentnexus_overview'));
    }

    private function group(int $uid, string $modules): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => $uid, 'pid' => 0, 'title' => 'Editors ' . $uid, 'groupMods' => $modules,
        ]);
    }

    private function user(int $uid, string $modules): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => $uid, 'pid' => 0, 'username' => 'editor' . $uid, 'password' => 'never-used', 'userMods' => $modules,
        ]);
    }

    private function field(string $table, string $field, int $uid): string
    {
        $row = $this->getConnectionPool()->getConnectionForTable($table)->select([$field], $table, ['uid' => $uid])->fetchAssociative();
        return is_array($row) ? (string)$row[$field] : '';
    }
}
