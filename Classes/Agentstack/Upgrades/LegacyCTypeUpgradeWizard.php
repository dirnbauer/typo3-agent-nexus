<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Upgrades;

use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\ChattyInterface;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;

/**
 * Rewrites content elements left over from the five per-protocol extensions.
 *
 * Before Agent Nexus existed, each protocol shipped as its own extension with
 * its own CType: a2uiintegration_inquiry, aguiintegration_assistant,
 * a2aintegration_concierge, ucpintegration_checkout and
 * ap2integration_trustedsurface. Agent Nexus still renders those records
 * through TypoScript aliases, but the aliases are deprecated in 3.0 and go away
 * in 4.0 — after which an unmigrated record renders nothing.
 *
 * This wizard does the one-line rename, in place, on live and deleted records
 * alike so nothing breaks when an editor restores something from the recycler.
 * FlexForm settings, headers and relations are untouched: only the CType
 * changes, and the new CType's FlexForm is a superset of the old one.
 */
#[UpgradeWizard('agentNexusLegacyCTypes')]
final class LegacyCTypeUpgradeWizard implements UpgradeWizardInterface, ChattyInterface
{
    /** Legacy CType => the 3.0 CType that replaces it. */
    public const MIGRATIONS = [
        'a2uiintegration_inquiry' => 'agentnexus_inquiry',
        'aguiintegration_assistant' => 'agentnexus_assistant',
        'a2aintegration_concierge' => 'agentnexus_concierge',
        'ucpintegration_checkout' => 'agentnexus_checkout',
        'ap2integration_trustedsurface' => 'agentnexus_trustedsurface',
    ];

    /** Set by the Install Tool / CLI; absent when the wizard runs headless. */
    private ?OutputInterface $output = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function getTitle(): string
    {
        return 'Agent Nexus: migrate legacy content element types';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Rewrites content elements that still use a CType from one of the five per-protocol '
            . 'extensions Agent Nexus replaced (%s) to their Agent Nexus equivalent. The legacy '
            . 'types are deprecated in 3.0 and stop rendering in 4.0. Only the CType changes; '
            . 'plugin settings are preserved.',
            implode(', ', array_keys(self::MIGRATIONS)),
        );
    }

    public function updateNecessary(): bool
    {
        return $this->countLegacyRecords() > 0;
    }

    public function executeUpdate(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');

        foreach (self::MIGRATIONS as $legacy => $replacement) {
            $affected = $connection->update(
                'tt_content',
                ['CType' => $replacement],
                ['CType' => $legacy],
            );

            if ($affected > 0 && $this->output !== null) {
                $this->output->writeln(sprintf('  %s -> %s: %d record(s)', $legacy, $replacement, $affected));
            }
        }

        return true;
    }

    /**
     * @return list<class-string>
     */
    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }

    /**
     * Counts deleted rows too: a record restored from the recycler after the
     * aliases are gone would otherwise render nothing.
     */
    private function countLegacyRecords(): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();

        return (int)$queryBuilder
            ->count('uid')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'CType',
                    $queryBuilder->createNamedParameter(array_keys(self::MIGRATIONS), Connection::PARAM_STR_ARRAY),
                ),
            )
            ->executeQuery()
            ->fetchOne();
    }
}
