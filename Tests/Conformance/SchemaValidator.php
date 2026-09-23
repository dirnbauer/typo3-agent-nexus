<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance;

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Validates Agent Nexus payloads against the official specification schemas
 * vendored under Tests/Conformance/Schemas (see the SOURCE.txt next to each set
 * for where every file came from).
 *
 * Every schema is registered under its own `$id`, so `$ref`s resolve offline.
 * Some references cannot resolve that way, and {@see self::ALIASES} registers
 * the unchanged file under the id the references expect:
 *
 * - A2A publishes both bundles (1.0 and 0.3) without an `$id`.
 * - A2UI's envelope schemas refer to components and functions through a
 *   placeholder, `catalog.json`, which the specification tells every validator
 *   to map to the basic catalogue; `client_to_server.json` of v0.9.1 has no
 *   `$id`, and the v1.0 basic catalogue refers to `common_types.json` relative
 *   to its own published location.
 * - AP2's receipts reference `types/receipt_status.json`, a file that declares
 *   another `$id`.
 *
 * The local overlays under Resources/Private/Schemas/Overlays are registered
 * the same way, each under its own `$id`. An overlay never replaces an
 * official file: it is a separate schema that refers to the official one and
 * resolves a documented conflict between two specifications (see
 * Documentation/Protocols/KnownSpecConflicts.rst). Validating against the
 * official `$id` still gives the official verdict.
 *
 * It is Opis' CompliantValidator: the plain Validator treats `default` as an
 * instruction and inserts default values into the data before it checks
 * `unevaluatedProperties`. JSON Schema defines `default` as an annotation only.
 *
 * Data goes through a JSON round trip first, exactly as it would on the wire —
 * which is the point: a PHP `[]` meant to be `{}` fails here the way it would
 * fail in another implementation's parser.
 */
final class SchemaValidator
{
    public const string A2A_SCHEMA_ID = 'https://a2a-protocol.org/v1.0/spec/a2a.json';
    public const string A2A_03_SCHEMA_ID = 'https://a2a-protocol.org/v0.3.0/specification/json/a2a.json';
    public const string AGUI_SCHEMA_ID = 'https://ag-ui.com/spec/1.0/schema.json';

    /** id the references expect => vendored file below {@see self::schemaDirectory()} */
    private const array ALIASES = [
        self::A2A_SCHEMA_ID => 'a2a/1.0/a2a.json',
        self::A2A_03_SCHEMA_ID => 'a2a/0.3/a2a.json',
        'https://a2ui.org/specification/v0_9/catalog.json' => 'a2ui/v0.9.1/basic_catalog.json',
        'https://a2ui.org/specification/v0_9/client_to_server.json' => 'a2ui/v0.9.1/client_to_server.json',
        'https://a2ui.org/specification/v1_0/catalog.json' => 'a2ui/v1.0/basic_catalog.json',
        'https://a2ui.org/specification/v1_0/catalogs/basic/common_types.json' => 'a2ui/v1.0/common_types.json',
        'https://ap2-protocol.org/schemas/types/receipt_status.json' => 'ap2/0.2.0/ap2/types/receipt_status.json',
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
        foreach ((new ErrorFormatter())->formatFlat($error) as $message) {
            $lines[] = is_string($message) ? $message : (string)json_encode($message);
        }
        foreach ((new ErrorFormatter())->format($error, true) as $pointer => $messages) {
            foreach ((array)$messages as $message) {
                $lines[] = $pointer . ': ' . (is_string($message) ? $message : (string)json_encode($message));
            }
        }
        $lines = array_values(array_unique($lines));
        return $lines === [] ? ['The data does not conform.'] : $lines;
    }

    public static function schemaDirectory(): string
    {
        return __DIR__ . '/Schemas';
    }

    /** Local overlays that resolve conflicts between specifications; shipped with the extension. */
    public static function overlayDirectory(): string
    {
        return dirname(__DIR__, 2) . '/Resources/Private/Schemas/Overlays';
    }

    private static function validator(): Validator
    {
        if (self::$validator !== null) {
            return self::$validator;
        }
        $validator = new CompliantValidator(null, 20, false);
        $resolver = $validator->resolver();
        if ($resolver === null) {
            throw new \RuntimeException('The schema validator has no resolver.', 1758614410);
        }

        foreach ([self::schemaDirectory(), self::overlayDirectory()] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                    continue;
                }
                $schema = json_decode((string)file_get_contents($file->getPathname()), false);
                if ($schema instanceof \stdClass && is_string($schema->{'$id'} ?? null)) {
                    $resolver->registerFile($schema->{'$id'}, $file->getPathname());
                }
            }
        }

        foreach (self::ALIASES as $id => $file) {
            $schema = json_decode((string)file_get_contents(self::schemaDirectory() . '/' . $file), false);
            if (!$schema instanceof \stdClass) {
                throw new \RuntimeException('Cannot read the vendored schema ' . $file, 1758632002);
            }
            $schema->{'$id'} = $id;
            $resolver->registerRaw($schema, $id);
        }

        return self::$validator = $validator;
    }
}
