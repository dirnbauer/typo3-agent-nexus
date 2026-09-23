<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Shared setup for the functional suite: load the extension and give the test
 * instance one minimal site, because a frontend request needs something to
 * resolve even when the eID endpoints themselves run before site resolution.
 */
abstract class AbstractAgentNexusTestCase extends FunctionalTestCase
{
    protected const SITE_IDENTIFIER = 'testing';
    protected const BASE = 'https://agent-nexus.test/';
    /**
     * lib.contentElement comes from fluid_styled_content, which the
     * "webconsulting/agent-nexus" site set declares as its dependency — without
     * it the set does not activate and its settings never reach a site.
     */
    protected array $coreExtensionsToLoad = ['fluid_styled_content'];

    protected array $testExtensionsToLoad = ['agent_nexus'];

    protected function writeTestSite(int $rootPageId = 1): void
    {
        $path = Environment::getConfigPath() . '/sites/' . self::SITE_IDENTIFIER;
        GeneralUtility::mkdir_deep($path);
        GeneralUtility::writeFile($path . '/config.yaml', Yaml::dump([
            'rootPageId' => $rootPageId,
            'base' => self::BASE,
            'languages' => [[
                'title' => 'English',
                'enabled' => true,
                'languageId' => 0,
                'base' => '/',
                'locale' => 'en_US.UTF-8',
            ]],
            'errorHandling' => [],
            'routes' => [],
        ], 99, 2), true);
    }

    /**
     * A page tree with one root page, so a site has something to point at.
     */
    protected function createRootPage(int $uid = 1): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => $uid,
            'pid' => 0,
            'title' => 'Test root',
            'slug' => '/',
            'doktype' => 1,
            'is_siteroot' => 1,
            'deleted' => 0,
            'hidden' => 0,
        ]);
    }
}
