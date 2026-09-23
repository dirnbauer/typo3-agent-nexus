<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Service;

use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * Which version of each specification Agent Nexus implements, and the newest
 * published one, as checked against the primary sources.
 *
 * The overview module, the "Protocol hub" and "Protocol info" elements and the
 * documentation (Documentation/Protocols/SpecVersions.rst) all state these
 * versions; this is the one place the code keeps them. When a specification
 * moves, update this class, the documentation page and the implementation
 * together.
 */
final class SpecificationVersions
{
    /** The day every "latest" value below was checked against its primary source. */
    public const string CHECKED = '2026-09-23';

    /**
     * @var array<string, array{label: string, implemented: string, latest: string, released: string, candidate: string, url: string, repository: string}>
     */
    private const array VERSIONS = [
        'a2ui' => [
            'label' => 'A2UI',
            'implemented' => 'v0.9.1',
            'latest' => 'v0.9.1',
            'released' => '2026-05-29',
            'candidate' => 'v1.0',
            'url' => 'https://a2ui.org',
            'repository' => 'https://github.com/a2ui-project/a2ui',
        ],
        'agui' => [
            'label' => 'AG-UI',
            'implemented' => '1.0',
            'latest' => '1.0',
            'released' => '2026-09-17',
            'candidate' => '',
            'url' => 'https://docs.ag-ui.com/spec/1.0',
            'repository' => 'https://github.com/ag-ui-protocol/ag-ui',
        ],
        'a2a' => [
            'label' => 'A2A',
            'implemented' => '1.0',
            'latest' => '1.0.1',
            'released' => '2026-05-28',
            'candidate' => '',
            'url' => 'https://a2a-protocol.org/v1.0.1/specification/',
            'repository' => 'https://github.com/a2aproject/A2A',
        ],
        'ucp' => [
            'label' => 'UCP',
            'implemented' => '2026-08-25',
            'latest' => '2026-08-25',
            'released' => '2026-08-25',
            'candidate' => '',
            'url' => 'https://ucp.dev/2026-08-25/specification/overview/',
            'repository' => 'https://github.com/Universal-Commerce-Protocol/ucp',
        ],
        'ap2' => [
            'label' => 'AP2',
            'implemented' => 'v0.2.0',
            'latest' => 'v0.2.0',
            'released' => '2026-04-28',
            'candidate' => '',
            'url' => 'https://ap2-protocol.org',
            'repository' => 'https://github.com/google-agentic-commerce/AP2',
        ],
        'mcp' => [
            'label' => 'MCP',
            'implemented' => '',
            'latest' => '2026-07-28',
            'released' => '2026-07-28',
            'candidate' => '',
            'url' => 'https://modelcontextprotocol.io/specification/2026-07-28',
            'repository' => 'https://github.com/modelcontextprotocol/modelcontextprotocol',
        ],
    ];

    /**
     * @return array{key: string, label: string, implemented: string, latest: string, released: string, candidate: string, url: string, repository: string, current: bool}
     */
    public function for(Protocol $protocol): array
    {
        return $this->row($protocol->value);
    }

    /**
     * Every specification: the five implemented ones and MCP, which Agent Nexus
     * references but leaves to hn/typo3-mcp-server ({@see McpServerDetector}).
     *
     * @return list<array{key: string, label: string, implemented: string, latest: string, released: string, candidate: string, url: string, repository: string, current: bool}>
     */
    public function all(): array
    {
        return array_map($this->row(...), array_keys(self::VERSIONS));
    }

    /**
     * @return array{key: string, label: string, implemented: string, latest: string, released: string, candidate: string, url: string, repository: string, current: bool}
     */
    private function row(string $key): array
    {
        $version = self::VERSIONS[$key];
        return ['key' => $key] + $version + [
            // Implemented counts as current when it is the latest release, or the
            // latest release's MAJOR.MINOR where the wire carries only that.
            'current' => $version['implemented'] !== ''
                && ($version['implemented'] === $version['latest'] || str_starts_with($version['latest'], $version['implemented'] . '.')),
        ];
    }
}
