<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Upgrades;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\Connection;
use Webconsulting\AgentNexus\Agentstack\Upgrades\LegacyCTypeUpgradeWizard;
use Webconsulting\AgentNexus\Tests\Functional\AbstractAgentNexusTestCase;

final class LegacyCTypeUpgradeWizardTest extends AbstractAgentNexusTestCase
{
    private LegacyCTypeUpgradeWizard $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = $this->get(LegacyCTypeUpgradeWizard::class);
    }

    #[Test]
    public function itIsNotNecessaryOnAnInstallationWithNoLegacyRecords(): void
    {
        $this->content(1, 'agentnexus_inquiry');
        $this->content(2, 'textmedia');

        self::assertFalse($this->subject->updateNecessary());
    }

    #[Test]
    public function itIsNecessaryAsSoonAsOneLegacyRecordExists(): void
    {
        $this->content(1, 'a2uiintegration_inquiry');

        self::assertTrue($this->subject->updateNecessary());
    }

    #[Test]
    public function itRewritesEveryLegacyTypeToItsReplacement(): void
    {
        $uid = 0;
        foreach (LegacyCTypeUpgradeWizard::MIGRATIONS as $legacy => $replacement) {
            $this->content(++$uid, $legacy);
        }

        self::assertTrue($this->subject->executeUpdate());

        $uid = 0;
        foreach (LegacyCTypeUpgradeWizard::MIGRATIONS as $legacy => $replacement) {
            self::assertSame($replacement, $this->cTypeOf(++$uid), $legacy . ' was not migrated');
        }
        self::assertFalse($this->subject->updateNecessary(), 'and nothing is left to do afterwards.');
    }

    #[Test]
    public function itLeavesEveryOtherContentElementAlone(): void
    {
        $this->content(1, 'textmedia');
        $this->content(2, 'agentnexus_checkout');
        $this->content(3, 'ucpintegration_checkout');

        $this->subject->executeUpdate();

        self::assertSame('textmedia', $this->cTypeOf(1));
        self::assertSame('agentnexus_checkout', $this->cTypeOf(2));
        self::assertSame('agentnexus_checkout', $this->cTypeOf(3));
    }

    #[Test]
    public function aDeletedRecordIsMigratedTooSoTheRecyclerStaysUsable(): void
    {
        $this->content(1, 'a2aintegration_concierge', deleted: true);

        self::assertTrue($this->subject->updateNecessary(), 'A deleted record still counts.');
        $this->subject->executeUpdate();

        self::assertSame('agentnexus_concierge', $this->cTypeOf(1));
    }

    #[Test]
    public function pluginSettingsSurviveTheMigration(): void
    {
        $flexForm = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data>'
            . '<sheet index="sDEF"><language index="lDEF">'
            . '<field index="settings.intro"><value index="vDEF">Written by an editor</value></field>'
            . '</language></sheet></data></T3FlexForms>';
        $this->content(1, 'aguiintegration_assistant', flexForm: $flexForm);

        $this->subject->executeUpdate();

        $row = $this->row(1);
        self::assertSame('agentnexus_assistant', $row['CType']);
        self::assertSame($flexForm, $row['pi_flexform'], 'Only the CType changes.');
    }

    #[Test]
    public function runningItTwiceIsHarmless(): void
    {
        $this->content(1, 'a2uiintegration_inquiry');

        $this->subject->executeUpdate();
        $this->subject->executeUpdate();

        self::assertSame('agentnexus_inquiry', $this->cTypeOf(1));
    }

    #[Test]
    public function itWaitsForTheDatabaseToBeUpToDate(): void
    {
        self::assertContains(
            \TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite::class,
            $this->subject->getPrerequisites(),
        );
    }

    private function content(int $uid, string $cType, bool $deleted = false, string $flexForm = ''): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => $uid,
            'pid' => 1,
            'CType' => $cType,
            'header' => 'Element ' . $uid,
            'pi_flexform' => $flexForm,
            'deleted' => $deleted ? 1 : 0,
        ]);
    }

    private function cTypeOf(int $uid): string
    {
        return (string)$this->row($uid)['CType'];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $uid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid', 'CType', 'pi_flexform', 'deleted')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($row, 'No tt_content record with uid ' . $uid);

        return $row;
    }
}
