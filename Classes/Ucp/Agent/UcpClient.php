<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteMatch;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Protocol;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRecorder;
use Webconsulting\AgentNexus\Ucp\Http\CheckoutEndpoint;
use Webconsulting\AgentNexus\Ucp\Http\InProcessCaller;
use Webconsulting\AgentNexus\Ucp\Http\JsonResponses;
use Webconsulting\AgentNexus\Ucp\Http\ProfileEndpoint;

/**
 * The shopping agent's HTTP client — without the network.
 *
 * Each call becomes a PSR-7 request for the same route, with the same headers
 * and body another platform would send, and goes through the same endpoint
 * method the API router would call. Only the socket is skipped. Every exchange
 * lands in the traffic log as an in-process call (channel "agent"),
 * correlated with its checkout.
 */
final readonly class UcpClient
{
    public function __construct(
        private RouteRegistry $routes,
        private CheckoutEndpoint $checkouts,
        private ProfileEndpoint $profiles,
        private TrafficRecorder $recorder,
    ) {}

    public function call(AgentSession $session, UcpCall $call): UcpExchange
    {
        $route = $this->routes->get($call->routeId)
            ?? throw new \LogicException(sprintf('There is no route "%s".', $call->routeId), 1758700701);
        $method = $route->methods[0] ?? 'GET';
        $path = $route->uri($this->routes->apiBasePath(), $call->parameters);

        $headers = $call->headers;
        $json = '';
        if ($call->body !== null) {
            $json = JsonResponses::encode($call->body);
            $headers['Content-Type'] = 'application/json';
        }
        $stream = new Stream('php://temp', 'rw');
        $stream->write($json);
        $stream->rewind();

        $request = (new ServerRequest($session->origin . $path, $method, $stream, $headers))
            ->withAttribute(RouteMatch::ATTRIBUTE, new RouteMatch($route, $call->parameters, $session->origin, $session->apiBaseUrl))
            ->withAttribute(InProcessCaller::ATTRIBUTE, $session->caller);

        $started = microtime(true);
        $response = $this->dispatch($call->routeId, $request);
        $durationMs = (int)round((microtime(true) - $started) * 1000);

        $decoded = json_decode((string)$response->getBody(), true);
        $body = [];
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                $body[(string)$key] = $value;
            }
        }
        $exchange = new UcpExchange($method, $path, $headers, $call->body, $response->getStatusCode(), $body);

        $checkoutId = $exchange->checkout()['id'] ?? ($call->parameters['id'] ?? '');
        $this->recorder->recordInProcess(
            Protocol::Ucp,
            $method,
            $method . ' ' . $path,
            $call->operation,
            $call->body,
            $response->getStatusCode(),
            $body === [] ? null : $body,
            is_string($checkoutId) ? $checkoutId : '',
            $durationMs,
        );

        return $exchange;
    }

    private function dispatch(string $routeId, ServerRequestInterface $request): ResponseInterface
    {
        return match ($routeId) {
            'ucp.profile.wellknown', 'ucp.profile' => $this->profiles->business($request),
            'ucp.platform.profile' => $this->profiles->platform($request),
            'ucp.checkout.create' => $this->checkouts->create($request),
            'ucp.checkout.get' => $this->checkouts->get($request),
            'ucp.checkout.update' => $this->checkouts->update($request),
            'ucp.checkout.complete' => $this->checkouts->complete($request),
            'ucp.checkout.cancel' => $this->checkouts->cancel($request),
            default => throw new \LogicException(sprintf('The shopping agent does not call "%s".', $routeId), 1758700702),
        };
    }
}
