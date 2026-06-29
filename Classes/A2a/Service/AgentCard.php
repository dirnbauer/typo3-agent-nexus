<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Builds the site's **Agent Card** — the public, machine-readable description an
 * A2A client fetches (conventionally at `/.well-known/agent-card.json`) to learn
 * who the agent is, where to reach it, what it can do, and how it authenticates.
 * Discovery is the entry point of the whole protocol: no card, no collaboration.
 */
final class AgentCard implements SingletonInterface
{
    public function __construct(
        private readonly SkillCatalog $skillCatalog,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ServerRequestInterface $request): array
    {
        $base = rtrim((string)$request->getUri()->withQuery('')->withFragment('')->withPath(''), '/');
        $rpcUrl = $base . '/index.php?eID=a2a_rpc';

        $skills = [];
        foreach ($this->skillCatalog->all() as $skill) {
            $skills[] = [
                'id' => $skill['id'],
                'name' => $skill['name'],
                'description' => $skill['description'],
                'tags' => $skill['tags'],
                'examples' => $skill['examples'],
            ];
        }

        return [
            'protocolVersion' => '0.3.0',
            'name' => 'TYPO3 Site Agent',
            'description' => 'The public agent for this TYPO3 site. It summarises content, drafts copy and plans work, and returns results as A2A artifacts.',
            'url' => $rpcUrl,
            'preferredTransport' => 'JSONRPC',
            'version' => '0.1.0',
            'provider' => [
                'organization' => 'webconsulting GmbH',
                'url' => 'https://webconsulting.at',
            ],
            'capabilities' => [
                'streaming' => true,
                'pushNotifications' => false,
                'stateTransitionHistory' => true,
            ],
            'defaultInputModes' => ['text/plain'],
            'defaultOutputModes' => ['text/plain', 'text/markdown'],
            'skills' => $skills,
            // Discoverable demo agent — no credentials required.
            'securitySchemes' => new \stdClass(),
            'security' => [],
        ];
    }
}
