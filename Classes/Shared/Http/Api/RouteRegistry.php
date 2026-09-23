<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Http\Api;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * Every endpoint Agent Nexus serves, collected from the protocols'
 * {@see RouteProvider}s.
 */
final class RouteRegistry implements SingletonInterface
{
    /** @var list<Route>|null */
    private ?array $routes = null;

    /**
     * @param iterable<RouteProvider> $providers
     */
    public function __construct(
        #[AutowireIterator(RouteProvider::TAG)]
        private readonly iterable $providers,
        private readonly ExtensionSettings $settings,
    ) {}

    /**
     * @return list<Route>
     */
    public function all(): array
    {
        if ($this->routes !== null) {
            return $this->routes;
        }
        $routes = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->routes() as $route) {
                if ($route->wellKnown && !$this->settings->publishWellKnown()) {
                    continue;
                }
                $routes[] = $route;
            }
        }
        return $this->routes = $routes;
    }

    /**
     * @return list<Route>
     */
    public function forProtocol(Protocol $protocol): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn(Route $route): bool => $route->protocol === $protocol,
        ));
    }

    public function get(string $id): ?Route
    {
        return array_find($this->all(), static fn(Route $route): bool => $route->id === $id);
    }

    /**
     * The route for a method and path, or the reason there is none.
     *
     * @return array{route: ?Route, parameters: array<string, string>, methodMismatch: bool}
     */
    public function match(string $method, string $path): array
    {
        $apiBasePath = $this->settings->apiBasePath();
        $methodMismatch = false;
        foreach ($this->all() as $route) {
            if (preg_match($route->pattern($apiBasePath), $path, $matches) !== 1) {
                continue;
            }
            if (strtoupper($method) !== 'OPTIONS' && !$route->allows($method)) {
                $methodMismatch = true;
                continue;
            }
            $parameters = [];
            foreach ($matches as $name => $value) {
                if (is_string($name)) {
                    $parameters[$name] = rawurldecode($value);
                }
            }
            return ['route' => $route, 'parameters' => $parameters, 'methodMismatch' => false];
        }
        return ['route' => null, 'parameters' => [], 'methodMismatch' => $methodMismatch];
    }

    public function isApiPath(string $path): bool
    {
        $base = $this->settings->apiBasePath();
        return $path === $base || str_starts_with($path, $base . '/');
    }

    /**
     * Absolute URL of a route, for documents that describe this installation.
     *
     * @param array<string, string> $parameters
     */
    public function url(string $routeId, string $origin, array $parameters = []): string
    {
        $route = $this->get($routeId);
        if ($route === null) {
            return '';
        }
        return rtrim($origin, '/') . $route->uri($this->settings->apiBasePath(), $parameters);
    }

    public function apiBasePath(): string
    {
        return $this->settings->apiBasePath();
    }
}
