<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\A2ui;

use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;

/**
 * The ids of the vendored A2UI schemas the tests validate against.
 *
 * Validation itself is the shared {@see SchemaValidator}, which also makes the
 * binding the A2UI specification asks every validator to make: the envelope
 * schemas refer to components and functions through a placeholder,
 * `catalog.json` ("To validate A2UI messages: map catalog.json to
 * catalogs/basic/catalog.json" — a2ui_protocol.md of v0.9.1 and v1.0).
 */
final class A2uiSchemas
{
    public const string V0_9_ENVELOPE = 'https://a2ui.org/specification/v0_9/server_to_client.json';
    public const string V0_9_LIST = 'https://a2ui.org/specification/v0_9/server_to_client_list.json';
    public const string V0_9_LIST_WRAPPER = 'https://a2ui.org/specification/v0_9/server_to_client_list_wrapper.json';
    public const string V0_9_CLIENT = 'https://a2ui.org/specification/v0_9/client_to_server.json';
    public const string V0_9_CLIENT_LIST = 'https://a2ui.org/specification/v0_9/client_to_server_list.json';
    public const string V0_9_CATALOG = 'https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json';
    public const string V0_9_DATA_MODEL = 'https://a2ui.org/specification/v0_9/client_data_model.json';
    public const string V0_9_CLIENT_CAPABILITIES = 'https://a2ui.org/specification/v0_9/client_capabilities.json';
    public const string V0_9_SERVER_CAPABILITIES = 'https://a2ui.org/specification/v0_9/server_capabilities.json';

    public const string V1_0_ENVELOPE = 'https://a2ui.org/specification/v1_0/agent_to_renderer.json';
    public const string V1_0_LIST = 'https://a2ui.org/specification/v1_0/json/agent_to_renderer_list.json';
    public const string V1_0_LIST_WRAPPER = 'https://a2ui.org/specification/v1_0/json/agent_to_renderer_list_wrapper.json';
    public const string V1_0_RENDERER = 'https://a2ui.org/specification/v1_0/renderer_to_agent.json';
    public const string V1_0_RENDERER_LIST = 'https://a2ui.org/specification/v1_0/json/renderer_to_agent_list.json';
    public const string V1_0_CATALOG = 'https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json';
    public const string V1_0_DATA_MODEL = 'https://a2ui.org/specification/v1_0/renderer_data_model.json';
    public const string V1_0_RENDERER_CAPABILITIES = 'https://a2ui.org/specification/v1_0/renderer_capabilities.json';
    public const string V1_0_AGENT_CAPABILITIES = 'https://a2ui.org/specification/v1_0/agent_capabilities.json';

    /**
     * Every validation error as one readable line; empty when the data conforms.
     *
     * @return list<string>
     */
    public static function errors(mixed $data, string $schemaUri): array
    {
        return SchemaValidator::errors($data, $schemaUri);
    }
}
