<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Http\Api;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Contributes the endpoints of one protocol to the API.
 *
 * Every implementation is collected by {@see RouteRegistry}; nothing else has
 * to be registered. The endpoint listings in the backend overview and in the
 * frontend "Protocol info" element read the same routes, so a route that is
 * served is also a route that is documented.
 */
#[AutoconfigureTag(self::TAG)]
interface RouteProvider
{
    public const string TAG = 'agentnexus.route_provider';

    /**
     * @return list<Route>
     */
    public function routes(): array;
}
