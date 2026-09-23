<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Component;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Surface;

/**
 * Builds the agent-to-renderer messages of one A2UI version.
 *
 * Every message is an envelope: one JSON object with `version` and exactly one
 * message key. v0.9.1 builds a surface in three messages — createSurface
 * (surface id, catalogue, theme, whether to send the data model back),
 * updateComponents, updateDataModel — while the v1.0 candidate may carry the
 * components and the data model inline in a single createSurface.
 *
 * JSON objects stay objects: an empty data model is sent as `{}`, never `[]`.
 */
final class MessageBuilder
{
    /** Shown next to the surface by renderers that support attribution (v0.9.1 theme). */
    public const string AGENT_DISPLAY_NAME = 'Agent Nexus';

    /**
     * The messages that create a surface and fill it.
     *
     * @return list<array<string, mixed>>
     */
    public function surface(Surface $surface, A2uiVersion $version): array
    {
        if ($version === A2uiVersion::V1_0) {
            return [$this->envelope($version, 'createSurface', [
                'surfaceId' => $surface->surfaceId,
                'catalogId' => $version->catalogId(),
                'sendDataModel' => $surface->sendDataModel,
                'components' => $surface->componentsToArray(),
                'dataModel' => self::object($surface->dataModel),
            ])];
        }

        $createSurface = [
            'surfaceId' => $surface->surfaceId,
            'catalogId' => $version->catalogId(),
        ];
        $theme = $surface->theme + ['agentDisplayName' => self::AGENT_DISPLAY_NAME];
        $createSurface['theme'] = $theme;
        $createSurface['sendDataModel'] = $surface->sendDataModel;

        return [
            $this->envelope($version, 'createSurface', $createSurface),
            $this->updateComponents($surface->surfaceId, $surface->components, $version),
            $this->updateDataModel($surface->surfaceId, '/', self::object($surface->dataModel), $version),
        ];
    }

    /**
     * @param list<Component> $components
     * @return array<string, mixed>
     */
    public function updateComponents(string $surfaceId, array $components, A2uiVersion $version): array
    {
        return $this->envelope($version, 'updateComponents', [
            'surfaceId' => $surfaceId,
            'components' => array_map(static fn(Component $component): array => $component->toArray(), $components),
        ]);
    }

    /**
     * Replace the value at a JSON Pointer ("/" or null for the whole model).
     *
     * @return array<string, mixed>
     */
    public function updateDataModel(string $surfaceId, ?string $path, mixed $value, A2uiVersion $version): array
    {
        $body = ['surfaceId' => $surfaceId];
        if ($path !== null) {
            $body['path'] = $path;
        }
        $body['value'] = $value;
        return $this->envelope($version, 'updateDataModel', $body);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteSurface(string $surfaceId, A2uiVersion $version): array
    {
        return $this->envelope($version, 'deleteSurface', ['surfaceId' => $surfaceId]);
    }

    /**
     * An A2UI error message, in the shape a renderer reports errors to an
     * agent: `VALIDATION_FAILED` with the JSON Pointer of the offending field,
     * or a generic error with a code of its own.
     *
     * @return array<string, mixed>
     */
    public function error(A2uiVersion $version, string $code, string $surfaceId, string $message, ?string $path = null): array
    {
        $error = ['code' => $code, 'surfaceId' => $surfaceId];
        if ($code === 'VALIDATION_FAILED') {
            $error['path'] = $path ?? '/';
        }
        $error['message'] = $message;
        return $this->envelope($version, 'error', $error);
    }

    /**
     * A PHP array that must travel as a JSON object.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>|\stdClass
     */
    public static function object(array $data): array|\stdClass
    {
        return $data === [] ? new \stdClass() : $data;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function envelope(A2uiVersion $version, string $type, array $body): array
    {
        return ['version' => $version->value, $type => $body];
    }
}
