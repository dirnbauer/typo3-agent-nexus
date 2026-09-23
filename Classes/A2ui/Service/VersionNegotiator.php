<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Http\A2uiProblem;

/**
 * Decides which A2UI version to answer a request in.
 *
 * In this order: the `version` the client asks for; otherwise the versions
 * whose basic catalogue the client's capabilities list
 * (`a2uiClientCapabilities` with a "v0.9" entry, `a2uiRendererCapabilities`
 * with a "v1.0" entry — top-level members of the request or inside its
 * `metadata`), preferring the stable v0.9.1; otherwise v0.9.1.
 */
final class VersionNegotiator
{
    /**
     * @param array<string, mixed> $body
     * @throws A2uiProblem when the client asks for a version or catalogue this agent does not speak
     */
    public function negotiate(array $body): A2uiVersion
    {
        $requested = null;
        if (array_key_exists('version', $body)) {
            $requested = A2uiVersion::fromWire($body['version']);
            if ($requested === null) {
                throw new A2uiProblem(422, 'UNSUPPORTED_VERSION', sprintf(
                    'This agent speaks A2UI %s. Leave "version" out or send one of them.',
                    implode(', ', array_map(static fn(A2uiVersion $version): string => $version->value, A2uiVersion::cases())),
                ), path: '/version');
            }
        }

        $capabilities = $this->capabilities($body);
        if ($capabilities === null) {
            return $requested ?? A2uiVersion::DEFAULT;
        }

        $supported = array_values(array_filter(
            A2uiVersion::cases(),
            static fn(A2uiVersion $version): bool => self::supports($capabilities, $version),
        ));
        if ($requested !== null) {
            if (!in_array($requested, $supported, true)) {
                throw new A2uiProblem(422, 'UNSUPPORTED_CATALOG', sprintf(
                    'The renderer capabilities do not list the %s basic catalogue (%s).',
                    $requested->value,
                    $requested->catalogId(),
                ), version: $requested);
            }
            return $requested;
        }
        if ($supported === []) {
            throw new A2uiProblem(422, 'UNSUPPORTED_CATALOG', sprintf(
                'The renderer supports none of the catalogues this agent generates: %s.',
                implode(', ', array_map(static fn(A2uiVersion $version): string => $version->catalogId(), A2uiVersion::cases())),
            ));
        }
        return in_array(A2uiVersion::DEFAULT, $supported, true) ? A2uiVersion::DEFAULT : $supported[0];
    }

    /**
     * The capabilities a client sent, merged from both member names and both
     * places; null when it sent none.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function capabilities(array $body): ?array
    {
        $metadata = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
        $found = false;
        $merged = [];
        foreach ([$body, $metadata] as $container) {
            foreach (A2uiVersion::cases() as $version) {
                if (!array_key_exists($version->capabilitiesMember(), $container)) {
                    continue;
                }
                $found = true;
                $capabilities = $container[$version->capabilitiesMember()];
                if (!is_array($capabilities) || ($capabilities !== [] && array_is_list($capabilities))) {
                    throw A2uiProblem::invalid('/' . $version->capabilitiesMember(), 'The capabilities must be a JSON object keyed by version, e.g. {"v0.9": {"supportedCatalogIds": [...]}}.');
                }
                foreach ($capabilities as $key => $entry) {
                    $merged[(string)$key] = $entry;
                }
            }
        }
        return $found ? $merged : null;
    }

    /**
     * @param array<string, mixed> $capabilities
     */
    private static function supports(array $capabilities, A2uiVersion $version): bool
    {
        $entry = $capabilities[$version->capabilitiesKey()] ?? null;
        $catalogIds = is_array($entry) && is_array($entry['supportedCatalogIds'] ?? null) ? $entry['supportedCatalogIds'] : [];
        return array_any($version->catalogIds(), static fn(string $id): bool => in_array($id, $catalogIds, true));
    }
}
