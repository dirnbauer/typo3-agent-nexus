<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Http;

use Webconsulting\AgentNexus\Shared\Http\Api\Route;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteProvider;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * The AG-UI endpoint: POST a RunAgentInput, receive the run as AG-UI 1.0
 * events over Server-Sent Events. Any AG-UI client can use it; the assistant
 * widget does too, adding its content element in forwardedProps.
 */
final readonly class AguiRoutes implements RouteProvider
{
    public const string RUN = 'agui.run';

    public function routes(): array
    {
        return [
            new Route(
                id: self::RUN,
                protocol: Protocol::Agui,
                methods: ['POST'],
                path: '/ag-ui',
                handler: AguiEndpoint::class . '::run',
                operation: 'RunAgent',
                binding: 'HTTP + SSE',
                description: 'Runs the site assistant. Send a RunAgentInput; the run comes back as AG-UI 1.0 events. Approval is an interrupt: answer it in resume on the next run.',
            ),
        ];
    }
}
