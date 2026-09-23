<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Http;

use Webconsulting\AgentNexus\Shared\Http\Api\Route;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteProvider;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * The UCP endpoints: the business profile, the REST binding of the checkout
 * capability, the demo platform's profile and the shopping agent the widget
 * talks to.
 *
 * The profile's service endpoint is `{api}/ucp`, so the REST paths below are
 * exactly the ones the UCP OpenAPI description lists, under that endpoint.
 */
final class UcpRoutes implements RouteProvider
{
    private const string REST = 'REST';

    public function routes(): array
    {
        return [
            new Route(
                'ucp.profile.wellknown',
                Protocol::Ucp,
                ['GET'],
                '/.well-known/ucp',
                ProfileEndpoint::class . '::business',
                'discovery',
                'Discovery',
                'The business profile a shopping agent reads first: services, capabilities and payment handlers.',
                wellKnown: true,
            ),
            new Route(
                'ucp.profile',
                Protocol::Ucp,
                ['GET'],
                '/ucp/profile',
                ProfileEndpoint::class . '::business',
                'discovery',
                'Discovery',
                'The same business profile, for hosts that do not publish well-known documents.',
            ),
            new Route(
                'ucp.platform.profile',
                Protocol::Ucp,
                ['GET'],
                '/ucp/platform-profile',
                ProfileEndpoint::class . '::platform',
                'platform_profile',
                'Discovery',
                'The profile of the demo shopping agent, named in its UCP-Agent header.',
            ),
            new Route(
                'ucp.checkout.create',
                Protocol::Ucp,
                ['POST'],
                '/ucp/checkout-sessions',
                CheckoutEndpoint::class . '::create',
                Operation::Create->value,
                self::REST,
                'Opens a checkout session and prices it from the catalogue.',
            ),
            new Route(
                'ucp.checkout.get',
                Protocol::Ucp,
                ['GET'],
                '/ucp/checkout-sessions/{id}',
                CheckoutEndpoint::class . '::get',
                Operation::Get->value,
                self::REST,
                'Returns a checkout session as it stands.',
            ),
            new Route(
                'ucp.checkout.update',
                Protocol::Ucp,
                ['PUT'],
                '/ucp/checkout-sessions/{id}',
                CheckoutEndpoint::class . '::update',
                Operation::Update->value,
                self::REST,
                'Replaces the line items and buyer details of a session.',
            ),
            new Route(
                'ucp.checkout.complete',
                Protocol::Ucp,
                ['POST'],
                '/ucp/checkout-sessions/{id}/complete',
                CheckoutEndpoint::class . '::complete',
                Operation::Complete->value,
                self::REST,
                'Places the order with one sandbox payment instrument. No money moves.',
            ),
            new Route(
                'ucp.checkout.cancel',
                Protocol::Ucp,
                ['POST'],
                '/ucp/checkout-sessions/{id}/cancel',
                CheckoutEndpoint::class . '::cancel',
                Operation::Cancel->value,
                self::REST,
                'Cancels a session that is not completed.',
            ),
            new Route(
                'ucp.agent',
                Protocol::Ucp,
                ['POST'],
                '/ucp/agent',
                AgentEndpoint::class . '::run',
                'RunAgent',
                'AG-UI 1.0 over SSE',
                'The shopping agent: calls the checkout API for a visitor and stops for their approval.',
                widget: true,
            ),
        ];
    }
}
