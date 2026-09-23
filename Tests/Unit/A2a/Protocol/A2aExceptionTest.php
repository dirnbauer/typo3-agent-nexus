<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2a\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2a\Protocol\A2aError;
use Webconsulting\AgentNexus\A2a\Protocol\A2aException;

/**
 * The error mapping of specification section 5.4, and the shape each binding
 * gives an error: a JSON-RPC error object with typed details in `data`, and a
 * google.rpc.Status under `error` for HTTP+JSON.
 */
final class A2aExceptionTest extends UnitTestCase
{
    #[Test]
    #[DataProvider('mappingProvider')]
    public function everyA2aErrorMapsAsTheSpecificationSays(A2aError $error, int $code, int $httpStatus, string $rpcStatus): void
    {
        self::assertSame($code, $error->code());
        self::assertSame($httpStatus, $error->httpStatus());
        self::assertSame($rpcStatus, $error->rpcStatus());
        self::assertTrue($error->isA2aSpecific());
    }

    /**
     * @return array<string, array{0: A2aError, 1: int, 2: int, 3: string}>
     */
    public static function mappingProvider(): array
    {
        return [
            'TaskNotFoundError' => [A2aError::TaskNotFound, -32001, 404, 'NOT_FOUND'],
            'TaskNotCancelableError' => [A2aError::TaskNotCancelable, -32002, 400, 'FAILED_PRECONDITION'],
            'PushNotificationNotSupportedError' => [A2aError::PushNotificationNotSupported, -32003, 400, 'FAILED_PRECONDITION'],
            'UnsupportedOperationError' => [A2aError::UnsupportedOperation, -32004, 400, 'FAILED_PRECONDITION'],
            'ContentTypeNotSupportedError' => [A2aError::ContentTypeNotSupported, -32005, 400, 'INVALID_ARGUMENT'],
            'InvalidAgentResponseError' => [A2aError::InvalidAgentResponse, -32006, 500, 'INTERNAL'],
            'ExtendedAgentCardNotConfiguredError' => [A2aError::ExtendedAgentCardNotConfigured, -32007, 400, 'FAILED_PRECONDITION'],
            'ExtensionSupportRequiredError' => [A2aError::ExtensionSupportRequired, -32008, 400, 'FAILED_PRECONDITION'],
            'VersionNotSupportedError' => [A2aError::VersionNotSupported, -32009, 400, 'FAILED_PRECONDITION'],
        ];
    }

    #[Test]
    public function theStandardJsonRpcErrorsKeepTheirCodes(): void
    {
        self::assertSame(-32700, A2aError::ParseError->code());
        self::assertSame(-32600, A2aError::InvalidRequest->code());
        self::assertSame(-32601, A2aError::MethodNotFound->code());
        self::assertSame(-32602, A2aError::InvalidParams->code());
        self::assertSame(-32603, A2aError::Internal->code());
        self::assertFalse(A2aError::InvalidParams->isA2aSpecific());
    }

    #[Test]
    public function theSpecificationNamesAreDerivedFromTheReasons(): void
    {
        self::assertSame('TaskNotFoundError', A2aError::TaskNotFound->specName());
        self::assertSame('ExtendedAgentCardNotConfiguredError', A2aError::ExtendedAgentCardNotConfigured->specName());
        self::assertSame('JSONParseError', A2aError::ParseError->specName());
    }

    #[Test]
    public function anA2aErrorCarriesAnErrorInfoInTheJsonRpcData(): void
    {
        $error = A2aException::taskNotFound('task-9')->toJsonRpcError();

        self::assertSame(-32001, $error['code']);
        self::assertArrayHasKey('data', $error);
        self::assertSame('type.googleapis.com/google.rpc.ErrorInfo', $error['data'][0]['@type']);
        self::assertSame('TASK_NOT_FOUND', $error['data'][0]['reason']);
        self::assertSame('a2a-protocol.org', $error['data'][0]['domain']);
        self::assertSame('task-9', $error['data'][0]['metadata']['taskId']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $error['data'][0]['metadata']['timestamp']);
        foreach ($error['data'][0]['metadata'] as $value) {
            self::assertIsString($value, 'google.rpc.ErrorInfo metadata values are strings.');
        }
    }

    #[Test]
    public function aValidationErrorNamesTheFieldInABadRequest(): void
    {
        $error = A2aException::invalidParams('message.parts', 'must be a list with at least one part.')->toJsonRpcError();

        self::assertSame(-32602, $error['code']);
        self::assertArrayHasKey('data', $error);
        self::assertCount(1, $error['data'], 'No ErrorInfo: InvalidParams is not an A2A error.');
        self::assertSame('type.googleapis.com/google.rpc.BadRequest', $error['data'][0]['@type']);
        self::assertSame([['field' => 'message.parts', 'description' => 'must be a list with at least one part.']], $error['data'][0]['fieldViolations']);
    }

    #[Test]
    public function theRestBodyIsAGoogleRpcStatus(): void
    {
        $body = (new A2aException(A2aError::TaskNotCancelable, 'Already done.', ['taskId' => 't']))->toRestError();

        self::assertSame(400, $body['error']['code']);
        self::assertSame('FAILED_PRECONDITION', $body['error']['status']);
        self::assertSame('Already done.', $body['error']['message']);
        self::assertSame('TASK_NOT_CANCELABLE', $body['error']['details'][0]['reason']);
    }

    #[Test]
    public function theRateLimitIsThisInstallationsOwnError(): void
    {
        $exception = new A2aException(A2aError::RateLimited);

        self::assertSame(-32000, $exception->toJsonRpcError()['code']);
        self::assertSame(429, $exception->toRestError()['error']['code']);
        self::assertSame('RESOURCE_EXHAUSTED', $exception->toRestError()['error']['status']);
        self::assertSame(A2aError::OWN_DOMAIN, $exception->details()[0]['domain']);
    }

    #[Test]
    public function anErrorWithoutAMessageUsesTheStandardOne(): void
    {
        self::assertSame('Invalid JSON payload', (new A2aException(A2aError::ParseError))->getMessage());
    }
}
