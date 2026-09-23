<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Component;

/**
 * Checks an agent-to-renderer message stream against the rules of one A2UI
 * version without a schema validator: every message an envelope with exactly
 * one message key and the right version, createSurface first and once, one
 * surface id, the basic catalogue, and components that the sanitiser would
 * leave exactly as they are.
 */
final readonly class MessageValidator
{
    private const array MESSAGE_TYPES = ['createSurface', 'updateComponents', 'updateDataModel', 'deleteSurface'];
    private const array V1_MESSAGE_TYPES = ['callRendererFunction', 'agentFunctionResponse'];

    public function __construct(
        private SurfaceSanitizer $sanitizer,
    ) {}

    /**
     * @param array<array-key, mixed> $messages
     * @return list<string> what is wrong; empty when the stream is valid
     */
    public function validate(array $messages, A2uiVersion $version): array
    {
        $types = $version === A2uiVersion::V1_0 ? [...self::MESSAGE_TYPES, ...self::V1_MESSAGE_TYPES] : self::MESSAGE_TYPES;
        $errors = [];
        $surfaceId = null;
        $components = [];
        $dataModel = [];

        foreach (array_values($messages) as $index => $message) {
            $number = $index + 1;
            if (!is_array($message)) {
                $errors[] = sprintf('Message %d is not a JSON object.', $number);
                continue;
            }
            if (!$version->accepts($message['version'] ?? null)) {
                $errors[] = sprintf('Message %d does not carry "version": "%s".', $number, $version->value);
            }
            $keys = array_values(array_filter(array_keys($message), static fn(int|string $key): bool => $key !== 'version'));
            if (count($keys) !== 1 || !in_array($keys[0], $types, true)) {
                $errors[] = sprintf('Message %d must carry exactly one of %s.', $number, implode(', ', $types));
                continue;
            }
            $type = (string)$keys[0];
            $body = $message[$type];
            if (!is_array($body)) {
                $errors[] = sprintf('Message %d: "%s" is not an object.', $number, $type);
                continue;
            }
            $id = $body['surfaceId'] ?? null;

            if ($type === 'createSurface') {
                if ($surfaceId !== null) {
                    $errors[] = sprintf('Message %d creates the surface a second time.', $number);
                }
                $surfaceId = is_string($id) ? $id : '';
                $catalogId = $body['catalogId'] ?? null;
                if (($version === A2uiVersion::V0_9_1 || $catalogId !== null) && $catalogId !== $version->catalogId()) {
                    $errors[] = sprintf('Message %d does not name the basic catalogue %s.', $number, $version->catalogId());
                }
                if (isset($body['components']) && is_array($body['components'])) {
                    $components = $this->collect($components, $body['components']);
                }
                if (isset($body['dataModel']) && is_array($body['dataModel'])) {
                    $dataModel = $body['dataModel'];
                }
            } elseif ($surfaceId === null) {
                $errors[] = sprintf('Message %d (%s) comes before createSurface.', $number, $type);
            }
            if (!is_string($id) || $id === '' || ($surfaceId !== null && $id !== $surfaceId)) {
                $errors[] = sprintf('Message %d does not name the surface "%s".', $number, (string)$surfaceId);
            }
            if ($type === 'updateComponents') {
                $components = $this->collect($components, is_array($body['components'] ?? null) ? $body['components'] : []);
            }
            if ($type === 'updateDataModel') {
                if ($version === A2uiVersion::V1_0 && !array_key_exists('value', $body)) {
                    $errors[] = sprintf('Message %d: updateDataModel needs a "value" in v1.0 (null deletes).', $number);
                }
                if (in_array($body['path'] ?? '/', ['/', ''], true) && is_array($body['value'] ?? null)) {
                    $dataModel = $body['value'];
                }
            }
        }

        if ($surfaceId === null) {
            $errors[] = 'There is no createSurface message.';
        }
        if ($components === []) {
            $errors[] = 'No components were sent.';
            return $errors;
        }

        $clean = $this->sanitizer->sanitize(array_values($components), $dataModel, $version);
        foreach ($clean->notes as $note) {
            $errors[] = $note;
        }
        $sanitised = [];
        foreach ($clean->components as $component) {
            $sanitised[$component->id] = $component->toArray();
        }
        foreach ($components as $id => $component) {
            if (!isset($sanitised[$id])) {
                $errors[] = sprintf('Component "%s" does not survive the catalogue check.', $id);
            } elseif ($sanitised[$id] != $component) {
                $errors[] = sprintf('Component "%s" differs from what the %s catalogue allows.', $id, $version->value);
            }
        }
        if (!isset($sanitised[Component::ROOT])) {
            $errors[] = 'No component has the id "root".';
        }
        return array_values(array_unique($errors));
    }

    /**
     * @param array<string, array<array-key, mixed>> $components
     * @param array<array-key, mixed> $list
     * @return array<string, array<array-key, mixed>>
     */
    private function collect(array $components, array $list): array
    {
        foreach ($list as $component) {
            if (is_array($component) && is_string($component['id'] ?? null)) {
                $components[$component['id']] = $component;
            }
        }
        return $components;
    }
}
