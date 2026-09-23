<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Ap2;

use Webconsulting\AgentNexus\Ap2\Crypto\DelegateChain;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwt;
use Webconsulting\AgentNexus\Ap2\Crypto\SdJwtProcessor;
use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;

/**
 * Validation against the vendored AP2 v0.2.0 schemas.
 *
 * Validation is the shared {@see SchemaValidator}, which also registers the
 * alias the receipts need: both reference `types/receipt_status.json`, but
 * that file declares the `$id`
 * `https://ap2-protocol.org/schemas/receipt-status.json`.
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
        return SchemaValidator::errors($data, $schemaId);
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
}
