<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Http\Api;

use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * One endpoint of the Agent Nexus API.
 *
 * Paths are relative to the API base path (the `apiBasePath` extension
 * setting, `/api/agent-nexus` by default) unless the route is a well-known
 * discovery document, which specifications pin to the host root. A path may
 * contain `{name}` placeholders; a placeholder matches one path segment up to
 * the next `/` or `:`, so A2A's `/tasks/{id}:cancel` works as written in the
 * specification.
 *
 * The handler is `Service\Class::method`: a public container service whose
 * method takes the request and returns the response. The matched route and its
 * parameters are available as the {@see RouteMatch::ATTRIBUTE} request
 * attribute.
 */
final readonly class Route
{
    /**
     * @param string $id          stable identifier, `<protocol>.<name>`
     * @param list<string> $methods HTTP methods, upper case
     * @param string $binding     how the specification names this binding
     *                            ("JSON-RPC 2.0", "HTTP+JSON", "Discovery" …)
     * @param bool $widget        the endpoint of a frontend widget rather than a
     *                            specification binding another agent calls
     */
    public function __construct(
        public string $id,
        public Protocol $protocol,
        public array $methods,
        public string $path,
        public string $handler,
        public string $operation = '',
        public string $binding = '',
        public string $description = '',
        public bool $wellKnown = false,
        public bool $widget = false,
    ) {}

    public function allows(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true)
            || (strtoupper($method) === 'HEAD' && in_array('GET', $this->methods, true));
    }

    /**
     * The path as a regular expression; placeholders become named groups.
     */
    public function pattern(string $apiBasePath): string
    {
        $path = $this->wellKnown ? $this->path : rtrim($apiBasePath, '/') . $this->path;
        $quoted = preg_quote($path, '#');
        $pattern = preg_replace('#\\\\\{([a-zA-Z][a-zA-Z0-9_]*)\\\\\}#', '(?P<$1>[^/:]+)', $quoted) ?? $quoted;
        return '#^' . $pattern . '$#';
    }

    /**
     * The path with placeholders filled in, relative to the host root.
     *
     * @param array<string, string> $parameters
     */
    public function uri(string $apiBasePath, array $parameters = []): string
    {
        $path = $this->wellKnown ? $this->path : rtrim($apiBasePath, '/') . $this->path;
        foreach ($parameters as $name => $value) {
            $path = str_replace('{' . $name . '}', rawurlencode($value), $path);
        }
        return $path;
    }
}
