<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Http;

use Webconsulting\AgentNexus\Shared\Http\Api\Route;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteProvider;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * The AP2 endpoints. AP2 v0.2 defines no HTTP binding of its own — mandates
 * ride inside a commerce protocol (UCP, A2A) — so these are the sandbox's:
 * the public keys of its roles, and the Trusted Surface demo.
 */
final readonly class Ap2Routes implements RouteProvider
{
    public const string JWKS = 'ap2.jwks';
    public const string AUTHORIZE = 'ap2.authorize';

    public function routes(): array
    {
        return [
            new Route(
                self::JWKS,
                Protocol::Ap2,
                ['GET'],
                '/ap2/jwks.json',
                Ap2Endpoint::class . '::jwks',
                'GetJwks',
                'JWK Set (RFC 7517)',
                'The public ES256 keys of the five sandbox roles, by key id.',
            ),
            new Route(
                self::AUTHORIZE,
                Protocol::Ap2,
                ['POST'],
                '/ap2/authorize',
                Ap2Endpoint::class . '::authorize',
                'Authorize',
                'Sandbox, HTTP+JSON',
                'Runs the autonomous AP2 flow: open mandates, the signed checkout, the closed mandates, every check and both receipts. Nothing is charged.',
                widget: true,
            ),
        ];
    }
}
