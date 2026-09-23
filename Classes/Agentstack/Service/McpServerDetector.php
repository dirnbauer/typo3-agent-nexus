<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Service;

use Composer\InstalledVersions;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Package\PackageManager;
use Webconsulting\AgentNexus\Agentstack\Dto\McpServer;

/**
 * Finds the MCP server of this installation, if there is one.
 *
 * Agent Nexus implements five protocols and leaves MCP to hn/typo3-mcp-server,
 * an optional package: nothing here references its classes, so Agent Nexus
 * installs, analyses and runs without it. It counts as present when TYPO3 has
 * the extension active (installed is not enough in classic mode); its version
 * comes from Composer's InstalledVersions, falling back to the package
 * metadata; the protocol versions from the `x-mcp.protocol` block of its
 * Configuration/Capabilities.yaml, which that package documents as its
 * machine-readable inventory. Anything unreadable leaves the field empty rather
 * than hiding the server.
 */
final readonly class McpServerDetector
{
    public const string PACKAGE = 'hn/typo3-mcp-server';
    public const string NAME = 'typo3-mcp-server';
    public const string EXTENSION_KEY = 'mcp_server';
    public const string MODULE = 'user_mcp_server';
    public const string MANIFEST = 'Configuration/Capabilities.yaml';

    /** The keys of `x-mcp.protocol` that name a protocol version. */
    private const array VERSION_KEYS = ['stable_version', 'preview_version'];

    public function __construct(
        private PackageManager $packageManager,
        private ModuleProvider $moduleProvider,
        private UriBuilder $uriBuilder,
        private LoggerInterface $logger,
    ) {}

    public function detect(?BackendUserAuthentication $user): McpServer
    {
        if (!$this->packageManager->isPackageActive(self::EXTENSION_KEY)) {
            return McpServer::notInstalled();
        }
        return new McpServer(
            true,
            self::NAME,
            $this->version(),
            $this->protocolVersions(),
            $this->moduleUri($user),
        );
    }

    private function version(): string
    {
        try {
            $version = InstalledVersions::isInstalled(self::PACKAGE) ? (string)InstalledVersions::getPrettyVersion(self::PACKAGE) : '';
            if ($version === '') {
                $metaData = $this->packageManager->getPackage(self::EXTENSION_KEY)->getPackageMetaData();
                // A package that states no version gets "1.0.0+no-version-set" from TYPO3.
                $version = $metaData->getBuild() === 'no-version-set' ? '' : (string)$metaData->getVersion();
            }
        } catch (\Throwable) {
            return '';
        }
        return ltrim($version, 'v');
    }

    /**
     * @return list<string>
     */
    private function protocolVersions(): array
    {
        try {
            $manifest = Yaml::parseFile(rtrim($this->packageManager->getPackage(self::EXTENSION_KEY)->getPackagePath(), '/') . '/' . self::MANIFEST);
        } catch (\Throwable $e) {
            $this->logger->notice('The MCP server declares no readable protocol versions: {message}', ['message' => $e->getMessage()]);
            return [];
        }
        $protocol = is_array($manifest) ? ($manifest['capabilities']['x-mcp']['protocol'] ?? null) : null;
        if (!is_array($protocol)) {
            return [];
        }
        $versions = [];
        foreach (self::VERSION_KEYS as $key) {
            $version = $protocol[$key] ?? null;
            if (is_string($version) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $version) === 1 && !in_array($version, $versions, true)) {
                $versions[] = $version;
            }
        }
        return $versions;
    }

    private function moduleUri(?BackendUserAuthentication $user): string
    {
        if ($user === null || !$this->moduleProvider->isModuleRegistered(self::MODULE) || !$this->moduleProvider->accessGranted(self::MODULE, $user)) {
            return '';
        }
        try {
            return (string)$this->uriBuilder->buildUriFromRoute(self::MODULE);
        } catch (\Throwable) {
            return '';
        }
    }
}
