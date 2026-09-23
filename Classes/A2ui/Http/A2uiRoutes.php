<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Http;

use Webconsulting\AgentNexus\Shared\Http\Api\Route;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteProvider;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * The A2UI endpoints of the Agent Nexus API. The frontend widget calls them
 * like any other client, carrying its widget context in the request.
 */
final readonly class A2uiRoutes implements RouteProvider
{
    public const string BINDING = 'HTTP + JSON (A2UI messages)';

    public function routes(): array
    {
        return [
            new Route(
                'a2ui.surfaces',
                Protocol::A2ui,
                ['POST'],
                '/a2ui/surfaces',
                A2uiEndpoint::class . '::surfaces',
                'createSurface',
                self::BINDING,
                'Turns a one-line request into a surface and answers with the A2UI messages that create it, in order.',
            ),
            new Route(
                'a2ui.actions',
                Protocol::A2ui,
                ['POST'],
                '/a2ui/actions',
                A2uiEndpoint::class . '::actions',
                'action',
                self::BINDING,
                'Takes an action a renderer reports, with the surface\'s data model, and answers with the messages that update or delete the surface.',
            ),
            new Route(
                'a2ui.catalog',
                Protocol::A2ui,
                ['GET'],
                '/a2ui/catalog',
                A2uiEndpoint::class . '::catalog',
                'catalog',
                'HTTP + JSON',
                'Lists the A2UI versions and basic catalogues this agent generates, with every component and function.',
            ),
        ];
    }
}
