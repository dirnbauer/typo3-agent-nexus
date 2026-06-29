<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Eid;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;

/**
 * Serves the site's UCP merchant manifest + catalog — what a shopping agent
 * fetches first to discover the store, its currency, capabilities, checkout
 * endpoint and products. In production you would also expose it at a well-known
 * path; here the discoverable URL is the eID.
 */
final class ManifestEndpoint
{
    public function manifest(ServerRequestInterface $request): ResponseInterface
    {
        $manifest = GeneralUtility::makeInstance(Merchant::class)->manifest($request);
        return (new JsonResponse($manifest))
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }
}
