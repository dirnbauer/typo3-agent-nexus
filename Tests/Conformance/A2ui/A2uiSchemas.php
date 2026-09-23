<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\A2ui;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;

/**
 * Validates against the vendored A2UI schemas with the one binding the A2UI
 * specification asks every validator to make.
 *
 * The envelope schemas are catalogue-agnostic: they refer to components and
 * functions through a placeholder file, `catalog.json` ("To validate A2UI
 * messages: map catalog.json to catalogs/basic/catalog.json" — a2ui_protocol.md
 * of v0.9.1 and v1.0). Two more ids are missing upstream: `client_to_server.json`
 * of v0.9.1 is published without an `$id`, and the v1.0 basic catalogue refers
 * to `common_types.json` relative to its own published location. Each binding
 * registers the unchanged schema under the id the references expect.
 *
 * It is Opis' CompliantValidator: the plain Validator treats `default` as an
 * instruction and inserts default values into the data before it checks
 * `unevaluatedProperties` — which makes every Text without a `variant` fail
 * the catalogue. JSON Schema defines `default` as an annotation only.
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

    /** id the references expect => vendored file */
    private const array BINDINGS = [
        'https://a2ui.org/specification/v0_9/catalog.json' => 'a2ui/v0.9.1/basic_catalog.json',
        'https://a2ui.org/specification/v0_9/client_to_server.json' => 'a2ui/v0.9.1/client_to_server.json',
        'https://a2ui.org/specification/v1_0/catalog.json' => 'a2ui/v1.0/basic_catalog.json',
        'https://a2ui.org/specification/v1_0/catalogs/basic/common_types.json' => 'a2ui/v1.0/common_types.json',
    ];

    private static ?Validator $validator = null;

    /**
     * Every validation error as one readable line; empty when the data conforms.
     *
     * @return list<string>
     */
    public static function errors(mixed $data, string $schemaUri): array
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        $error = self::validator()->validate($decoded, $schemaUri)->error();
        if (!$error instanceof ValidationError) {
            return [];
        }
        $lines = [];
        foreach ((new ErrorFormatter())->format($error, true) as $pointer => $messages) {
            foreach ((array)$messages as $message) {
                $lines[] = $pointer . ': ' . (is_string($message) ? $message : (string)json_encode($message));
            }
        }
        return array_values(array_unique($lines));
    }

    private static function validator(): Validator
    {
        if (self::$validator !== null) {
            return self::$validator;
        }
        $validator = new CompliantValidator(null, 20, false);
        $resolver = $validator->resolver();
        if ($resolver === null) {
            throw new \RuntimeException('The schema validator has no resolver.', 1758632001);
        }
        $directory = SchemaValidator::schemaDirectory();
        foreach (glob($directory . '/a2ui/*/*.json') ?: [] as $path) {
            $schema = json_decode((string)file_get_contents($path), false);
            if ($schema instanceof \stdClass && is_string($schema->{'$id'} ?? null)) {
                $resolver->registerRaw($schema, $schema->{'$id'});
            }
        }
        foreach (self::BINDINGS as $id => $file) {
            $schema = json_decode((string)file_get_contents($directory . '/' . $file), false);
            if (!$schema instanceof \stdClass) {
                throw new \RuntimeException('Cannot read ' . $file, 1758632002);
            }
            $schema->{'$id'} = $id;
            $resolver->registerRaw($schema, $id);
        }
        return self::$validator = $validator;
    }
}
