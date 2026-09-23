<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Agentstack\Service;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Package\MetaData;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Agentstack\Service\McpServerDetector;

/**
 * MCP is the one protocol of the map Agent Nexus leaves to another package.
 * The overview names that package when it is there, and says "not installed"
 * when it is not; every stub below answers only the exact question the
 * detector must ask.
 */
final class McpServerDetectorTest extends UnitTestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixtures/';
    private const string MODULE_URI = '/typo3/module/user/mcp-server?token=abc';

    #[Test]
    public function withoutThePackageThereIsNoServer(): void
    {
        $packageManager = $this->createMock(PackageManager::class);
        $packageManager->expects($this->once())->method('isPackageActive')->with('mcp_server')->willReturn(false);
        $packageManager->expects($this->never())->method('getPackage');

        $server = $this->detector($packageManager)->detect($this->user());

        self::assertFalse($server->installed);
        self::assertSame('', $server->version);
        self::assertSame([], $server->protocolVersions);
        self::assertSame('', $server->moduleUri);
    }

    #[Test]
    public function anInstalledServerNamesItsVersionProtocolVersionsAndModule(): void
    {
        $server = $this->detector($this->installed('mcp_server', '0.9.1'))->detect($this->user());

        self::assertTrue($server->installed);
        self::assertSame('typo3-mcp-server', $server->name);
        self::assertSame('0.9.1', $server->version);
        self::assertSame(['2025-11-25', '2026-07-28'], $server->protocolVersions);
        self::assertSame('2025-11-25, 2026-07-28', $server->protocolVersionList);
        self::assertSame(self::MODULE_URI, $server->moduleUri);
    }

    #[Test]
    public function aLeadingVAndTheMissingVersionPlaceholderAreNotShown(): void
    {
        self::assertSame('1.2.0', $this->detector($this->installed('mcp_server', 'v1.2.0'))->detect($this->user())->version);
        self::assertSame('', $this->detector($this->installed('mcp_server', '1.0.0+no-version-set'))->detect($this->user())->version);
    }

    #[Test]
    public function anUnreadableManifestStillShowsTheServer(): void
    {
        $server = $this->detector($this->installed('mcp_server_without_manifest', '0.9.1'))->detect($this->user());

        self::assertTrue($server->installed);
        self::assertSame([], $server->protocolVersions);
    }

    #[Test]
    public function withoutAccessToTheModuleThereIsNoLink(): void
    {
        $user = $this->user();
        $modules = $this->createMock(ModuleProvider::class);
        $modules->expects($this->once())->method('isModuleRegistered')->with('user_mcp_server')->willReturn(true);
        $modules->expects($this->once())->method('accessGranted')->with('user_mcp_server', $user)->willReturn(false);

        $server = $this->detector($this->installed('mcp_server', '0.9.1'), $modules)->detect($user);

        self::assertTrue($server->installed);
        self::assertSame('', $server->moduleUri);
    }

    #[Test]
    public function withoutABackendUserThereIsNoLink(): void
    {
        self::assertSame('', $this->detector($this->installed('mcp_server', '0.9.1'))->detect(null)->moduleUri);
    }

    private function detector(PackageManager $packageManager, ?ModuleProvider $modules = null): McpServerDetector
    {
        if ($modules === null) {
            $modules = self::createStub(ModuleProvider::class);
            $modules->method('isModuleRegistered')->willReturnMap([['user_mcp_server', true]]);
            $modules->method('accessGranted')->willReturnCallback(
                static fn(string $identifier): bool => $identifier === 'user_mcp_server',
            );
        }
        $uriBuilder = self::createStub(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static fn(string $route): Uri => $route === 'user_mcp_server'
                ? new Uri(self::MODULE_URI)
                : throw new \LogicException('Unexpected route ' . $route, 1758800101),
        );

        return new McpServerDetector($packageManager, $modules, $uriBuilder, new NullLogger());
    }

    /**
     * A package manager with hn/typo3-mcp-server active, its files in the
     * given fixture directory and this version in its metadata.
     */
    private function installed(string $fixture, string $version): PackageManager
    {
        $metaData = new MetaData('mcp_server');
        $metaData->setVersion($version);
        $package = self::createStub(PackageInterface::class);
        $package->method('getPackagePath')->willReturn(self::FIXTURES . $fixture . '/');
        $package->method('getPackageMetaData')->willReturn($metaData);

        $packageManager = self::createStub(PackageManager::class);
        $packageManager->method('isPackageActive')->willReturnMap([['mcp_server', true]]);
        $packageManager->method('getPackage')->willReturnMap([['mcp_server', $package]]);
        return $packageManager;
    }

    private function user(): BackendUserAuthentication
    {
        return self::createStub(BackendUserAuthentication::class);
    }
}
