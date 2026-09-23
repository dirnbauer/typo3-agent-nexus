<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use TYPO3\CMS\Core\Package\PackageManager;
use Webconsulting\AgentNexus\A2a\Http\A2aRoutes;
use Webconsulting\AgentNexus\A2a\Protocol\ProtocolVersion;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;

/**
 * Builds the site's **Agent Card** (A2A 1.0) — the public description a client
 * reads at `/.well-known/agent-card.json` to learn who the agent is, how to
 * reach it and what it can do. No card, no collaboration.
 *
 * `supportedInterfaces` lists the bindings in order of preference: JSON-RPC
 * for 1.0, HTTP+JSON for 1.0 and — for clients written before 1.0 — the same
 * JSON-RPC endpoint for 0.3. Every URL is absolute and built from the host the
 * card was asked for. The card declares no security schemes: this demo agent
 * is public, so the fields are left out rather than sent empty.
 *
 * The card is the one source of truth for what the agent offers: the endpoint
 * serves it, the Agent Card screen shows it, the console reads it.
 */
final readonly class AgentCard
{
    public const string NAME = 'TYPO3 Site Agent';
    public const string DOCUMENTATION_URL = 'https://github.com/dirnbauer/typo3-agent-nexus/blob/main/Documentation/Protocols/A2A.rst';

    /** Media types the skills read and write. */
    public const array INPUT_MODES = ['text/plain'];
    public const array OUTPUT_MODES = ['text/markdown', 'text/plain'];

    public function __construct(
        private SkillCatalog $skills,
        private RouteRegistry $routes,
        private PackageManager $packageManager,
    ) {}

    /**
     * @param string $origin scheme and host the card describes, e.g. `https://example.org`
     * @return array<string, mixed>
     */
    public function build(string $origin): array
    {
        $jsonRpc = $this->routes->url('a2a.jsonrpc', $origin);
        $rest = rtrim($origin, '/') . rtrim($this->routes->apiBasePath(), '/') . A2aRoutes::REST_BASE;

        $skills = [];
        foreach ($this->skills->all() as $skill) {
            $skills[] = [
                'id' => $skill['id'],
                'name' => $skill['name'],
                'description' => $skill['description'],
                'tags' => $skill['tags'],
                'examples' => $skill['examples'],
            ];
        }

        return [
            'name' => self::NAME,
            'description' => 'The public agent for this TYPO3 site. It summarises content, drafts copy and plans work, and returns results as A2A artifacts.',
            'supportedInterfaces' => [
                ['url' => $jsonRpc, 'protocolBinding' => 'JSONRPC', 'protocolVersion' => ProtocolVersion::V1_0->value],
                ['url' => $rest, 'protocolBinding' => 'HTTP+JSON', 'protocolVersion' => ProtocolVersion::V1_0->value],
                ['url' => $jsonRpc, 'protocolBinding' => 'JSONRPC', 'protocolVersion' => ProtocolVersion::V0_3->value],
            ],
            'provider' => [
                'organization' => 'webconsulting GmbH',
                'url' => 'https://webconsulting.at',
            ],
            'version' => $this->version(),
            'documentationUrl' => self::DOCUMENTATION_URL,
            'capabilities' => [
                'streaming' => true,
                'pushNotifications' => false,
                'extendedAgentCard' => false,
            ],
            'defaultInputModes' => self::INPUT_MODES,
            'defaultOutputModes' => self::OUTPUT_MODES,
            'skills' => $skills,
        ];
    }

    /**
     * The agent's version is the version of the extension that implements
     * it: when Agent Nexus changes, the agent changes.
     */
    public function version(): string
    {
        try {
            $version = (string)$this->packageManager->getPackage(ExtensionSettings::EXTENSION_KEY)->getPackageMetaData()->getVersion();
        } catch (\Throwable) {
            $version = '';
        }
        return $version !== '' ? ltrim($version, 'vV') : 'unknown';
    }
}
