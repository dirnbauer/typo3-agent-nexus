<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\RendererMessage;
use Webconsulting\AgentNexus\A2ui\Http\A2uiProblem;
use Webconsulting\AgentNexus\Shared\Http\WidgetContext;

/**
 * Reads the body of `POST /a2ui/actions`: one renderer-to-agent A2UI message
 * (`version` and exactly one of `action` or `error`), the transport
 * `metadata` that may carry the data model, and the widget context.
 *
 *     {"version": "v0.9.1",
 *      "action": {"name", "surfaceId", "sourceComponentId", "timestamp", "context"},
 *      "metadata": {"a2uiClientDataModel": {"version": "v0.9.1", "surfaces": {"<id>": {…}}}}}
 */
final class RendererMessageParser
{
    /** Members of the request besides the A2UI message itself. */
    private const array TRANSPORT_MEMBERS = ['metadata', WidgetContext::KEY];

    private const string DATE_TIME = '/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

    /**
     * @param array<string, mixed> $body
     * @throws A2uiProblem when the message is not a valid renderer-to-agent message
     */
    public function parse(array $body): RendererMessage
    {
        $version = A2uiVersion::fromWire($body['version'] ?? null);
        if ($version === null) {
            throw new A2uiProblem(422, 'UNSUPPORTED_VERSION', 'Send "version": "v0.9.1" (or "v0.9") or "v1.0".', path: '/version');
        }

        $message = ['version' => $body['version']];
        $kinds = [];
        $content = null;
        foreach ($body as $key => $value) {
            if ($key === 'version' || in_array($key, self::TRANSPORT_MEMBERS, true)) {
                continue;
            }
            if ($key !== RendererMessage::ACTION && $key !== RendererMessage::ERROR) {
                throw A2uiProblem::badRequest(sprintf('Unexpected member "%s": send one A2UI message (version and action or error) plus optional metadata.', $key));
            }
            $kinds[] = $key;
            $message[$key] = $value;
            $content = $value;
        }
        if (count($kinds) !== 1) {
            throw A2uiProblem::badRequest('Send exactly one of "action" or "error".');
        }
        $kind = $kinds[0];
        if (!is_array($content) || ($content !== [] && array_is_list($content))) {
            throw A2uiProblem::invalid('/' . $kind, sprintf('"%s" must be a JSON object.', $kind), version: $version);
        }
        $object = [];
        foreach ($content as $name => $value) {
            $object[(string)$name] = $value;
        }

        if ($kind === RendererMessage::ACTION) {
            $this->checkAction($object, $version);
        } else {
            $this->checkError($object, $version);
        }
        $surfaceId = is_string($object['surfaceId'] ?? null) ? $object['surfaceId'] : '';

        $metadata = [];
        if (array_key_exists('metadata', $body)) {
            if (!is_array($body['metadata']) || ($body['metadata'] !== [] && array_is_list($body['metadata']))) {
                throw A2uiProblem::invalid('/metadata', '"metadata" must be a JSON object.', $surfaceId, $version);
            }
            foreach ($body['metadata'] as $name => $value) {
                $metadata[(string)$name] = $value;
            }
        }

        return new RendererMessage($version, $kind, $object, $message, $this->dataModel($metadata, $surfaceId, $version), $metadata);
    }

    /**
     * @param array<string, mixed> $action
     */
    private function checkAction(array $action, A2uiVersion $version): void
    {
        $surfaceId = is_string($action['surfaceId'] ?? null) ? $action['surfaceId'] : '';
        foreach (['name', 'surfaceId', 'sourceComponentId'] as $field) {
            if (!is_string($action[$field] ?? null) || trim($action[$field]) === '') {
                throw A2uiProblem::invalid('/action/' . $field, sprintf('The action needs a "%s".', $field), $surfaceId, $version);
            }
        }
        if (!is_string($action['timestamp'] ?? null) || preg_match(self::DATE_TIME, $action['timestamp']) !== 1) {
            throw A2uiProblem::invalid('/action/timestamp', 'The action needs a "timestamp" in ISO 8601, e.g. 2026-09-23T10:15:00Z.', $surfaceId, $version);
        }
        if (!array_key_exists('context', $action) || !is_array($action['context']) || ($action['context'] !== [] && array_is_list($action['context']))) {
            throw A2uiProblem::invalid('/action/context', 'The action needs a "context" object (it may be empty).', $surfaceId, $version);
        }
    }

    /**
     * @param array<string, mixed> $error
     */
    private function checkError(array $error, A2uiVersion $version): void
    {
        $surfaceId = is_string($error['surfaceId'] ?? null) ? $error['surfaceId'] : '';
        foreach (['code', 'message', 'surfaceId'] as $field) {
            if (!is_string($error[$field] ?? null)) {
                throw A2uiProblem::invalid('/error/' . $field, sprintf('The error needs a "%s".', $field), $surfaceId, $version);
            }
        }
    }

    /**
     * The data model of this surface from the metadata, if the renderer sent one.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>|null
     */
    private function dataModel(array $metadata, string $surfaceId, A2uiVersion $version): ?array
    {
        $members = [$version->dataModelMember(), ...array_map(
            static fn(A2uiVersion $other): string => $other->dataModelMember(),
            array_filter(A2uiVersion::cases(), static fn(A2uiVersion $other): bool => $other !== $version),
        )];
        foreach ($members as $member) {
            $container = $metadata[$member] ?? null;
            if ($container === null) {
                continue;
            }
            if (!is_array($container) || !is_array($container['surfaces'] ?? null)) {
                throw A2uiProblem::invalid('/metadata/' . $member, sprintf('"%s" must be {"version", "surfaces": {"<surfaceId>": {…}}}.', $member), $surfaceId, $version);
            }
            $model = $container['surfaces'][$surfaceId] ?? null;
            if ($model === null) {
                return null;
            }
            if (!is_array($model) || ($model !== [] && array_is_list($model))) {
                throw A2uiProblem::invalid('/metadata/' . $member . '/surfaces', 'A data model must be a JSON object.', $surfaceId, $version);
            }
            $object = [];
            foreach ($model as $key => $value) {
                $object[(string)$key] = $value;
            }
            return $object;
        }
        return null;
    }
}
