<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Http\Api;

/**
 * A route the current request matched, with its path parameters and the
 * absolute URL prefixes a handler needs to describe itself (an Agent Card lists
 * its own interface URLs, a UCP profile its own endpoint).
 */
final readonly class RouteMatch
{
    public const string ATTRIBUTE = 'agentnexus.route';

    /**
     * @param array<string, string> $parameters
     * @param string $origin     scheme and host, e.g. `https://example.org`
     * @param string $apiBaseUrl `$origin` plus the API base path, no trailing slash
     */
    public function __construct(
        public Route $route,
        public array $parameters,
        public string $origin,
        public string $apiBaseUrl,
    ) {}

    public function parameter(string $name): string
    {
        return $this->parameters[$name] ?? '';
    }
}
