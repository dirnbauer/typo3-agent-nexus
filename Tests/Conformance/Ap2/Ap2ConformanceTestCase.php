<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Ap2;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;
use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwt;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwtProcessor;
use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;

/**
 * Validation against the vendored AP2 v0.2.0 schemas.
 *
 * The shared {@see SchemaValidator} registers every schema under its `$id`.
 * Two AP2 files cannot be resolved that way: both receipts reference
 * `types/receipt_status.json`, but that file declares the `$id`
 * `https://ap2-protocol.org/schemas/receipt-status.json`. This validator
 * registers the AP2 set the same way and adds the one alias the upstream
 * references need, without touching the vendored files.
 */
abstract class Ap2ConformanceTestCase extends ConformanceTestCase
{
    protected const string OPEN_CHECKOUT = 'https://ap2-protocol.org/schemas/open_checkout_mandate';
    protected const string CHECKOUT = 'https://ap2-protocol.org/schemas/checkout_mandate.json';
    protected const string OPEN_PAYMENT = 'https://ap2-protocol.org/schemas/payment_mandate_open.json';
    protected const string PAYMENT = 'https://ap2-protocol.org/schemas/payment_mandate.json';
    protected const string CHECKOUT_RECEIPT = 'https://ap2-protocol.org/schemas/checkout_receipt.json';
    protected const string PAYMENT_RECEIPT = 'https://ap2-protocol.org/schemas/payment_receipt.json';
    protected const string UCP_CHECKOUT = 'https://ap2-protocol.org/schemas/types/checkout.json';
    protected const string JWK = 'https://ap2-protocol.org/schemas/types/jwk_public_key.json';
    protected const string UCP_JWK = 'https://ucp.dev/schemas/profile.json#/$defs/jwk_public_key';

    private const string RECEIPT_STATUS_REFERENCE = 'https://ap2-protocol.org/schemas/types/receipt_status.json';

    private static ?Validator $validator = null;

    protected static function assertAp2Conforms(string $schemaId, mixed $data, string $message = ''): void
    {
        self::assertSame(
            [],
            self::ap2Errors($data, $schemaId),
            ($message !== '' ? $message . "\n" : '')
                . 'Does not conform to ' . $schemaId . ":\n"
                . (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
    }

    protected static function assertAp2Violates(string $schemaId, mixed $data, string $message = ''): void
    {
        self::assertNotSame([], self::ap2Errors($data, $schemaId), $message !== '' ? $message : 'Expected a schema violation.');
    }

    /**
     * @return list<string>
     */
    protected static function ap2Errors(mixed $data, string $schemaId): array
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $error = self::validator()->validate(json_decode($json, false, 512, JSON_THROW_ON_ERROR), $schemaId)->error();
        if (!$error instanceof ValidationError) {
            return [];
        }
        $lines = [];
        foreach ((new ErrorFormatter())->format($error, true) as $pointer => $messages) {
            foreach ((array)$messages as $line) {
                $lines[] = $pointer . ': ' . (is_string($line) ? $line : (string)json_encode($line));
            }
        }
        return $lines === [] ? ['The data does not conform.'] : $lines;
    }

    /**
     * A vendored AP2 schema file as an array.
     *
     * @return array<string, mixed>
     */
    protected static function schema(string $relativePath): array
    {
        return Json::decodeObject((string)file_get_contents(self::ap2Directory() . '/' . $relativePath));
    }

    /**
     * The mandate a token discloses in `delegate_payload`.
     *
     * @return array<string, mixed>
     */
    protected static function mandateOf(SdJwt $token): array
    {
        $payload = SdJwtProcessor::process($token);
        $list = Json::list($payload['delegate_payload'] ?? null);
        self::assertCount(1, $list, 'One mandate per token.');
        return Json::map($list[0]);
    }

    /**
     * @return list<array<string, mixed>> the mandate of every token, open first
     */
    protected static function mandatesOf(DelegateChain $chain): array
    {
        return array_map(self::mandateOf(...), $chain->tokens);
    }

    private static function ap2Directory(): string
    {
        return SchemaValidator::schemaDirectory() . '/ap2/0.2.0';
    }

    private static function validator(): Validator
    {
        if (self::$validator !== null) {
            return self::$validator;
        }
        $validator = new Validator();
        $validator->setMaxErrors(20);
        $resolver = $validator->resolver();
        self::assertNotNull($resolver, 'The schema validator has a resolver.');

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(SchemaValidator::schemaDirectory(), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            $schema = json_decode((string)file_get_contents($file->getPathname()), false);
            if ($schema instanceof \stdClass && isset($schema->{'$id'}) && is_string($schema->{'$id'})) {
                $resolver->registerFile($schema->{'$id'}, $file->getPathname());
            }
        }
        $resolver->registerFile(self::RECEIPT_STATUS_REFERENCE, self::ap2Directory() . '/ap2/types/receipt_status.json');

        return self::$validator = $validator;
    }
}
