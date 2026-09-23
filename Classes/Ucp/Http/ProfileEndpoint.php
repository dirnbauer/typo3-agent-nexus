<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Http\Response;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteMatch;
use Webconsulting\AgentNexus\Ucp\Profile\ProfileBuilder;

/**
 * Serves the UCP profiles: the business profile at /.well-known/ucp (and at
 * {api}/ucp/profile) and the demo platform's profile.
 *
 * Profiles are published artifacts, so they follow UCP's publishing rules:
 * no redirect, `Cache-Control: public` with a max-age of at least 60 seconds,
 * never private, no-store or no-cache, and a validator — an ETag, answered
 * with 304 when a client revalidates.
 */
#[Autoconfigure(public: true)]
final readonly class ProfileEndpoint
{
    public function __construct(
        private ProfileBuilder $profiles,
    ) {}

    public function business(ServerRequestInterface $request): ResponseInterface
    {
        return $this->publish($request, $this->profiles->business($this->apiBaseUrl($request)));
    }

    public function platform(ServerRequestInterface $request): ResponseInterface
    {
        return $this->publish($request, $this->profiles->platform());
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function publish(ServerRequestInterface $request, array $profile): ResponseInterface
    {
        $json = JsonResponses::encode($profile);
        $etag = '"' . substr(hash('sha256', $json), 0, 32) . '"';
        $headers = [
            'Cache-Control' => ProfileBuilder::CACHE_CONTROL,
            'ETag' => $etag,
            'Vary' => 'Accept-Encoding',
        ];

        $candidates = array_map(trim(...), explode(',', $request->getHeaderLine('If-None-Match')));
        if (in_array($etag, $candidates, true) || in_array('W/' . $etag, $candidates, true)) {
            return new Response(null, 304, $headers);
        }
        return JsonResponses::raw(200, $json, $headers);
    }

    private function apiBaseUrl(ServerRequestInterface $request): string
    {
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        if (!$match instanceof RouteMatch) {
            throw new \LogicException('The UCP profile is served through the API router only.', 1758700401);
        }
        return $match->apiBaseUrl;
    }
}
