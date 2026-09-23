<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Http\Api;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRecorder;

/**
 * Serves the Agent Nexus API in the frontend request stack.
 *
 * The specifications pin their endpoints to paths — A2A's Agent Card lives at
 * `/.well-known/agent-card.json`, UCP's profile at `/.well-known/ucp`, UCP's
 * checkout at `…/checkout-sessions/{id}` — which an eID (`?eID=…`) cannot
 * express. This middleware sits before site resolution, so the API answers on
 * every host of the installation regardless of how its sites are configured,
 * and hands every request it serves to the traffic recorder.
 *
 * Requests outside the API base path and the well-known documents pass through
 * untouched.
 */
final readonly class ApiRouter implements MiddlewareInterface
{
    private const string ALLOWED_HEADERS = 'Accept, Content-Type, A2A-Version, A2A-Extensions, X-A2A-Extensions, UCP-Agent, Idempotency-Key, Request-Id, Signature, Signature-Input, Content-Digest';
    private const string EXPOSED_HEADERS = 'A2A-Version, A2A-Extensions, Location, Retry-After, Idempotency-Key, Request-Id';

    public function __construct(
        private RouteRegistry $routes,
        private TrafficRecorder $recorder,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $match = $this->routes->match($request->getMethod(), $path);
        $route = $match['route'];

        if ($route === null) {
            if (!$this->routes->isApiPath($path)) {
                return $handler->handle($request);
            }
            return $this->withCors($match['methodMismatch']
                ? new JsonResponse(['error' => ['code' => 405, 'message' => 'This endpoint does not accept ' . strtoupper($request->getMethod()) . '.']], 405)
                : new JsonResponse(['error' => ['code' => 404, 'message' => 'No Agent Nexus endpoint at this path.']], 404));
        }

        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            return $this->withCors(new Response(null, 204), $route);
        }

        $origin = $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();
        $routeMatch = new RouteMatch(
            $route,
            $match['parameters'],
            $origin,
            rtrim($origin . $this->routes->apiBasePath(), '/'),
        );
        $request = $request->withAttribute(RouteMatch::ATTRIBUTE, $routeMatch);

        $capture = $this->recorder->start(
            $request,
            $route->protocol,
            $route->widget ? Channel::Widget : Channel::Api,
            strtoupper($request->getMethod()) . ' ' . $path,
        );
        if ($capture !== null) {
            $capture->describe($route->operation);
            $request = $request->withAttribute(TrafficCapture::ATTRIBUTE, $capture);
        }

        try {
            $response = $this->dispatch($route, $request);
        } catch (\Throwable $e) {
            $this->logger->error('Agent Nexus endpoint {route} failed.', ['route' => $route->id, 'exception' => $e]);
            $capture?->fail($e->getMessage());
            $response = new JsonResponse(['error' => ['code' => 500, 'message' => 'The endpoint failed. The error has been logged.']], 500);
        }

        $response = $this->withCors($response, $route);
        return $capture !== null ? $this->recorder->finish($capture, $response) : $response;
    }

    private function dispatch(Route $route, ServerRequestInterface $request): ResponseInterface
    {
        [$serviceId, $method] = explode('::', $route->handler, 2) + [1 => '__invoke'];
        $service = $this->container->get($serviceId);
        if (!is_object($service) || !method_exists($service, $method)) {
            throw new \LogicException(sprintf('Route "%s" points at %s, which does not exist.', $route->id, $route->handler), 1758614400);
        }
        $response = $service->{$method}($request);
        if (!$response instanceof ResponseInterface) {
            throw new \LogicException(sprintf('Route handler %s must return a response.', $route->handler), 1758614401);
        }
        return $response;
    }

    /**
     * Every Agent Nexus endpoint is public and credential-free, so any origin may
     * call it — which is what lets another agent's browser client talk to it.
     */
    private function withCors(ResponseInterface $response, ?Route $route = null): ResponseInterface
    {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Expose-Headers', self::EXPOSED_HEADERS);
        if ($route !== null) {
            $response = $response
                ->withHeader('Access-Control-Allow-Methods', implode(', ', [...$route->methods, 'OPTIONS']))
                ->withHeader('Access-Control-Allow-Headers', self::ALLOWED_HEADERS)
                ->withHeader('Access-Control-Max-Age', '600');
        }
        return $response;
    }
}
