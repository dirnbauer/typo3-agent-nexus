<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Http\Response;
use Webconsulting\AgentNexus\A2a\Protocol\Json;
use Webconsulting\AgentNexus\A2a\Service\AgentCard;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteMatch;

/**
 * Serves the Agent Card at `/.well-known/agent-card.json` and below the API
 * path. Cacheable for five minutes, with an ETag over the card's content — it
 * holds absolute URLs, so the same installation has one card per host — and
 * a 304 for a client that already has it (specification section 8.6).
 */
#[Autoconfigure(public: true)]
final readonly class AgentCardEndpoint
{
    private const string CACHE_CONTROL = 'public, max-age=300';

    public function __construct(
        private AgentCard $agentCard,
    ) {}

    public function card(ServerRequestInterface $request): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        $origin = $match instanceof RouteMatch
            ? $match->origin
            : $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();

        $json = Json::encode($this->agentCard->build($origin), true);
        $etag = '"' . substr(hash('sha256', $json), 0, 32) . '"';

        foreach (explode(',', $request->getHeaderLine('If-None-Match')) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === $etag || $candidate === 'W/' . $etag || $candidate === '*') {
                return new Response(null, 304, ['ETag' => $etag, 'Cache-Control' => self::CACHE_CONTROL]);
            }
        }

        $response = new Response('php://temp', 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'ETag' => $etag,
            'Cache-Control' => self::CACHE_CONTROL,
        ]);
        $response->getBody()->write($json);
        $response->getBody()->rewind();
        return $response;
    }
}
