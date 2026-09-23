<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Dto;

/**
 * Whether an MCP server runs in this installation, which one, which protocol
 * versions it speaks and where its backend module is.
 *
 * Agent Nexus does not implement MCP itself; it reports the server that
 * hn/typo3-mcp-server provides. A value object, so the overview template reads
 * plain properties.
 */
final readonly class McpServer
{
    /** The protocol versions as one line, e.g. "2025-11-25, 2026-07-28". */
    public string $protocolVersionList;

    /**
     * @param bool $installed the package is installed and active
     * @param string $name the package as people know it
     * @param string $version its installed version, without a leading "v"; '' when unknown
     * @param list<string> $protocolVersions the MCP protocol versions it declares
     * @param string $moduleUri its backend module, '' when this user may not open it
     */
    public function __construct(
        public bool $installed,
        public string $name = '',
        public string $version = '',
        public array $protocolVersions = [],
        public string $moduleUri = '',
    ) {
        $this->protocolVersionList = implode(', ', $protocolVersions);
    }

    public static function notInstalled(): self
    {
        return new self(false);
    }
}
