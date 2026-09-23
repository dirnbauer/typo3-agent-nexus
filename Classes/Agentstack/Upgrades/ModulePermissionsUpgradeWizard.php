<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Upgrades;

use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;

/**
 * Carries backend module permissions from the 3.x module identifiers over to
 * the 4.0 module set.
 *
 * 3.x had one second-level module per protocol (agentstack_a2a …). In 4.0 the
 * section is agentnexus_a2a and its screens are third-level modules
 * (agentnexus_a2a_console, agentnexus_a2a_card), each with its own permission.
 * The old identifiers remain module aliases, so a group that lists one still
 * reaches the section — but not its screens, which is what this wizard fixes:
 * every old identifier in be_groups.groupMods and be_users.userMods is replaced
 * by the section and all of its screens.
 *
 * The new inspector and traffic modules are not granted: they show what
 * visitors sent, and access to that is an administrator's decision.
 */
#[UpgradeWizard('agentNexusModulePermissions')]
final readonly class ModulePermissionsUpgradeWizard implements UpgradeWizardInterface
{
    /** 3.x identifier => the 4.0 modules it becomes. */
    public const array RENAMES = [
        'agentstack' => ['agentnexus'],
        'agentstack_overview' => ['agentnexus_overview'],
        'agentstack_a2ui' => ['agentnexus_a2ui', 'agentnexus_a2ui_playground', 'agentnexus_a2ui_catalog'],
        'agentstack_agui' => ['agentnexus_agui', 'agentnexus_agui_console', 'agentnexus_agui_events'],
        'agentstack_a2a' => ['agentnexus_a2a', 'agentnexus_a2a_console', 'agentnexus_a2a_card'],
        'agentstack_ucp' => ['agentnexus_ucp', 'agentnexus_ucp_console', 'agentnexus_ucp_profile'],
        'agentstack_ap2' => ['agentnexus_ap2', 'agentnexus_ap2_studio', 'agentnexus_ap2_reference'],
    ];

    /** Table => the field holding its module list. */
    private const array FIELDS = [
        'be_groups' => 'groupMods',
        'be_users' => 'userMods',
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'Agent Nexus: migrate module permissions';
    }

    public function getDescription(): string
    {
        return 'Replaces the Agent Nexus 3.x module identifiers (agentstack_*) in backend user and group '
            . 'permissions with the 4.0 sections and their screens (agentnexus_*).';
    }

    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }

    public function updateNecessary(): bool
    {
        return $this->migrate(true) > 0;
    }

    public function executeUpdate(): bool
    {
        $this->migrate(false);
        return true;
    }

    /**
     * The module list with every 3.x identifier replaced, or null when there
     * is nothing to replace.
     */
    public static function migrateList(string $modules): ?string
    {
        $items = array_values(array_filter(array_map(trim(...), explode(',', $modules)), static fn(string $item): bool => $item !== ''));
        if (!array_any($items, static fn(string $item): bool => isset(self::RENAMES[$item]))) {
            return null;
        }
        $migrated = [];
        foreach ($items as $item) {
            foreach (self::RENAMES[$item] ?? [$item] as $module) {
                $migrated[$module] = $module;
            }
        }
        return implode(',', $migrated);
    }

    /**
     * @return int rows that need (dry run) or received a change
     */
    private function migrate(bool $dryRun): int
    {
        $changed = 0;
        foreach (self::FIELDS as $table => $field) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll();
            $rows = $queryBuilder
                ->select('uid', $field)
                ->from($table)
                ->where($queryBuilder->expr()->like($field, $queryBuilder->createNamedParameter('%agentstack%')))
                ->executeQuery()
                ->fetchAllAssociative();

            foreach ($rows as $row) {
                $migrated = self::migrateList((string)($row[$field] ?? ''));
                if ($migrated === null) {
                    continue;
                }
                $changed++;
                if (!$dryRun) {
                    $this->connectionPool->getConnectionForTable($table)->update(
                        $table,
                        [$field => $migrated],
                        ['uid' => (int)$row['uid']],
                        [$field => Connection::PARAM_STR],
                    );
                }
            }
        }
        return $changed;
    }
}
