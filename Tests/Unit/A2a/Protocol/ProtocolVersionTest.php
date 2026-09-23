<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2a\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2a\Protocol\A2aError;
use Webconsulting\AgentNexus\A2a\Protocol\A2aException;
use Webconsulting\AgentNexus\A2a\Protocol\Operation;
use Webconsulting\AgentNexus\A2a\Protocol\ProtocolVersion;

/**
 * Version negotiation (specification section 3.6): Major.Minor decides, patch
 * versions do not, and no version at all means 0.3.
 */
final class ProtocolVersionTest extends UnitTestCase
{
    #[Test]
    #[DataProvider('supportedProvider')]
    public function aSupportedVersionIsRecognised(string $header, ProtocolVersion $expected): void
    {
        self::assertSame($expected, ProtocolVersion::negotiate($header));
    }

    /**
     * @return array<string, array{0: string, 1: ProtocolVersion}>
     */
    public static function supportedProvider(): array
    {
        return [
            'no header is 0.3' => ['', ProtocolVersion::V0_3],
            'blank is 0.3' => ['  ', ProtocolVersion::V0_3],
            '1.0' => ['1.0', ProtocolVersion::V1_0],
            'a patch version does not matter' => ['1.0.1', ProtocolVersion::V1_0],
            '0.3' => ['0.3', ProtocolVersion::V0_3],
            '0.3.0' => ['0.3.0', ProtocolVersion::V0_3],
        ];
    }

    #[Test]
    #[DataProvider('unsupportedProvider')]
    public function anUnsupportedVersionIsVersionNotSupportedAndListsTheSupportedOnes(string $header): void
    {
        try {
            ProtocolVersion::negotiate($header);
            self::fail('Expected VersionNotSupportedError for ' . $header);
        } catch (A2aException $exception) {
            self::assertSame(A2aError::VersionNotSupported, $exception->error);
            self::assertSame(-32009, $exception->toJsonRpcError()['code']);
            self::assertSame('1.0, 0.3', $exception->metadata['supportedVersions']);
            self::assertSame($header, $exception->metadata['requestedVersion']);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsupportedProvider(): array
    {
        return [
            'a newer minor' => ['1.1'],
            'a newer major' => ['2.0'],
            'an older draft' => ['0.2'],
            'not a version' => ['latest'],
        ];
    }

    #[Test]
    public function everyOperationButListTasksHasA03MethodThatMapsBack(): void
    {
        foreach (Operation::cases() as $operation) {
            $legacy = $operation->legacyMethod();
            if ($operation === Operation::ListTasks) {
                self::assertNull($legacy, 'ListTasks is new in 1.0.');
                continue;
            }
            self::assertNotNull($legacy);
            self::assertSame($operation, Operation::fromMethod($legacy, ProtocolVersion::V0_3));
            self::assertNull(Operation::fromMethod($legacy, ProtocolVersion::V1_0), 'A 0.3 name is not a 1.0 method.');
            self::assertSame($operation, Operation::fromMethod($operation->value, ProtocolVersion::V1_0));
        }
    }
}
