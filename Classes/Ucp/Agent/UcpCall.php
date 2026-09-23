<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

/**
 * One request the shopping agent sends to the business: which route, with
 * which headers and body.
 */
final readonly class UcpCall
{
    /**
     * @param array<string, string> $parameters path placeholders, e.g. ['id' => 'chk_…']
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body null sends no body
     */
    public function __construct(
        public string $routeId,
        public string $operation,
        public array $parameters = [],
        public array $headers = [],
        public ?array $body = null,
    ) {}
}
