<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Profile;

use Webconsulting\AgentNexus\Ucp\Payment\SandboxPaymentHandler;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * The two UCP profiles this installation publishes.
 *
 *  - The business profile, served at /.well-known/ucp: the version, the
 *    shopping service with its REST endpoint, the checkout capability and the
 *    sandbox payment handler. A platform reads it before anything else.
 *  - The platform profile of the demo shopping agent. The agent names it in
 *    its UCP-Agent header, as every platform must; this business does not
 *    fetch it (see {@see \Webconsulting\AgentNexus\Ucp\Http\UcpAgentHeader}).
 *
 * Both are built from code on every request, so they always describe what the
 * API actually does. Signing keys (`keys`) are not published: nothing in the
 * sandbox signs yet.
 */
final readonly class ProfileBuilder
{
    /** Cache-Control of both documents: public, and well above UCP's 60-second floor. */
    public const string CACHE_CONTROL = 'public, max-age=300';

    public function __construct(
        private SandboxPaymentHandler $payments,
    ) {}

    /** Base URL of the REST binding: the service endpoint the profile publishes. */
    public static function restEndpoint(string $apiBaseUrl): string
    {
        return rtrim($apiBaseUrl, '/') . Spec::REST_PATH;
    }

    /**
     * @return array{ucp: array<string, mixed>}
     */
    public function business(string $apiBaseUrl): array
    {
        return [
            'ucp' => [
                'version' => Spec::VERSION,
                'services' => [
                    Spec::SERVICE_SHOPPING => [[
                        'version' => Spec::VERSION,
                        'spec' => Spec::SPEC_OVERVIEW,
                        'transport' => 'rest',
                        'schema' => Spec::SCHEMA_REST,
                        'endpoint' => self::restEndpoint($apiBaseUrl),
                    ]],
                ],
                'capabilities' => [
                    Spec::CAPABILITY_CHECKOUT => [[
                        'version' => Spec::VERSION,
                        'spec' => Spec::SPEC_CHECKOUT,
                        'schema' => Spec::SCHEMA_CHECKOUT,
                    ]],
                ],
                'payment_handlers' => $this->payments->declaration(),
            ],
        ];
    }

    /**
     * The demo shopping agent's profile. It lists no payment handler: the
     * sandbox handler belongs to the business, and a platform may only
     * declare a handler together with a schema hosted on the handler's own
     * domain.
     *
     * @return array{ucp: array<string, mixed>}
     */
    public function platform(): array
    {
        return [
            'ucp' => [
                'version' => Spec::VERSION,
                'services' => [
                    Spec::SERVICE_SHOPPING => [[
                        'version' => Spec::VERSION,
                        'spec' => Spec::SPEC_OVERVIEW,
                        'transport' => 'rest',
                        'schema' => Spec::SCHEMA_REST,
                    ]],
                ],
                'capabilities' => [
                    Spec::CAPABILITY_CHECKOUT => [[
                        'version' => Spec::VERSION,
                        'spec' => Spec::SPEC_CHECKOUT,
                        'schema' => Spec::SCHEMA_CHECKOUT,
                    ]],
                ],
                'payment_handlers' => new \stdClass(),
            ],
        ];
    }

    /**
     * The rules of UCP 2026-08-25 a business profile can break, checked
     * against the given document — what the backend shows next to it.
     *
     * @param array<string, mixed> $profile
     * @return list<array{key: string, pass: bool, detail: string}>
     */
    public function checks(array $profile): array
    {
        $ucp = is_array($profile['ucp'] ?? null) ? $profile['ucp'] : [];
        $version = is_string($ucp['version'] ?? null) ? $ucp['version'] : '';

        $entries = [];
        foreach (['services', 'capabilities', 'payment_handlers'] as $registry) {
            foreach (is_array($ucp[$registry] ?? null) ? $ucp[$registry] : [] as $name => $list) {
                foreach (is_array($list) ? $list : [] as $entry) {
                    if (is_array($entry)) {
                        $entries[] = ['registry' => $registry, 'name' => (string)$name, 'entry' => $entry];
                    }
                }
            }
        }

        $wrongVersion = array_filter(
            $entries,
            static fn(array $e): bool => str_starts_with($e['name'], 'dev.ucp.') && ($e['entry']['version'] ?? null) !== $version,
        );
        $endpoints = array_map(
            static fn(array $e): string => is_string($e['entry']['endpoint'] ?? null) ? $e['entry']['endpoint'] : '',
            array_filter($entries, static fn(array $e): bool => $e['registry'] === 'services'),
        );
        $insecure = array_filter($endpoints, static fn(string $url): bool => !str_starts_with($url, 'https://'));
        $capabilitySchemas = array_filter(
            $entries,
            static fn(array $e): bool => $e['registry'] === 'capabilities'
                && !(is_string($e['entry']['schema'] ?? null) && str_starts_with($e['entry']['schema'], 'https://ucp.dev/')),
        );
        $handlers = array_filter($entries, static fn(array $e): bool => $e['registry'] === 'payment_handlers');
        $badHandlers = array_filter(
            $handlers,
            static fn(array $e): bool => !is_string($e['entry']['id'] ?? null) || !is_string($e['entry']['version'] ?? null)
                || str_starts_with($e['name'], 'dev.ucp.')
                || preg_match('/^[a-z](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9_-]*[a-z0-9_])?)+$/', $e['name']) !== 1,
        );

        return [
            ['key' => 'version', 'pass' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $version) === 1 && $version === Spec::VERSION, 'detail' => $version],
            ['key' => 'entryVersions', 'pass' => $wrongVersion === [], 'detail' => implode(', ', array_column($wrongVersion, 'name'))],
            ['key' => 'https', 'pass' => $endpoints !== [] && $insecure === [], 'detail' => implode(', ', $endpoints)],
            ['key' => 'capabilitySchemas', 'pass' => $capabilitySchemas === [], 'detail' => implode(', ', array_column($capabilitySchemas, 'name'))],
            ['key' => 'handlers', 'pass' => $handlers !== [] && $badHandlers === [], 'detail' => implode(', ', array_unique(array_column($handlers, 'name')))],
            ['key' => 'caching', 'pass' => true, 'detail' => self::CACHE_CONTROL],
        ];
    }
}
