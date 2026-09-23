<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Validates Agent Nexus payloads against the official specification schemas
 * vendored under Tests/Conformance/Schemas (see the SOURCE.txt next to each set
 * for where every file came from).
 *
 * Every schema is registered under its own `$id`, so `$ref`s resolve offline.
 * A2A publishes its bundle without an `$id`; it is registered as
 * {@see self::A2A_SCHEMA_ID}.
 *
 * Data goes through a JSON round trip first, exactly as it would on the wire —
 * which is the point: a PHP `[]` meant to be `{}` fails here the way it would
 * fail in another implementation's parser.
 */
final class SchemaValidator
{
    public const string A2A_SCHEMA_ID = 'https://a2a-protocol.org/v1.0/spec/a2a.json';
    public const string AGUI_SCHEMA_ID = 'https://ag-ui.com/spec/1.0/schema.json';

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

        $result = self::validator()->validate($decoded, $schemaUri);
        $error = $result->error();
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
        return array_values(array_unique($lines));
    }

    public static function schemaDirectory(): string
    {
        return __DIR__ . '/Schemas';
    }

    private static function validator(): Validator
    {
        if (self::$validator !== null) {
            return self::$validator;
        }
        $validator = new Validator();
        $validator->setMaxErrors(20);
        $resolver = $validator->resolver();
        if ($resolver === null) {
            throw new \RuntimeException('The schema validator has no resolver.', 1758614410);
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::schemaDirectory(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            $path = $file->getPathname();
            $schema = json_decode((string)file_get_contents($path), false);
            if (!$schema instanceof \stdClass) {
                continue;
            }
            $id = isset($schema->{'$id'}) && is_string($schema->{'$id'}) ? $schema->{'$id'} : null;
            if ($id === null && str_ends_with($path, '/a2a/1.0/a2a.json')) {
                $id = self::A2A_SCHEMA_ID;
            }
            if ($id !== null) {
                $resolver->registerFile($id, $path);
            }
        }

        return self::$validator = $validator;
    }
}
