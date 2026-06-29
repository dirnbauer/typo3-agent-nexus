<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Eid;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\AgentNexus\A2a\Service\AgentCard;

/**
 * Serves the site's Agent Card — the public, machine-readable description an A2A
 * client fetches to discover this agent. In production you would also expose it at
 * the well-known path `/.well-known/agent-card.json`; here the discoverable URL is
 * the eID, which the card itself advertises as its JSON-RPC endpoint.
 */
final class AgentCardEndpoint
{
    public function card(ServerRequestInterface $request): ResponseInterface
    {
        $card = GeneralUtility::makeInstance(AgentCard::class)->build($request);
        return (new JsonResponse($card))
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
