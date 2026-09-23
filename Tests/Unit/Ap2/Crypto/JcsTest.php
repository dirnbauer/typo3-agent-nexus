<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\Jcs;

/**
 * RFC 8785 test vectors. The number table is Appendix B (IEEE 754 bit pattern
 * and the ECMAScript serialisation); the two objects are the examples of
 * sections 3.2.2 and 3.2.3. Every value was also checked against Node's
 * `Number.prototype.toString` and `JSON.stringify` while this was written.
 */
final class JcsTest extends UnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function appendixB(): array
    {
        return [
            'zero' => ['0000000000000000', '0'],
            'minus zero' => ['8000000000000000', '0'],
            'min pos number' => ['0000000000000001', '5e-324'],
            'min neg number' => ['8000000000000001', '-5e-324'],
            'max pos number' => ['7fefffffffffffff', '1.7976931348623157e+308'],
            'max neg number' => ['ffefffffffffffff', '-1.7976931348623157e+308'],
            'max pos int' => ['4340000000000000', '9007199254740992'],
            'max neg int' => ['c340000000000000', '-9007199254740992'],
            '~2**68' => ['4430000000000000', '295147905179352830000'],
            'below 1e23' => ['44b52d02c7e14af5', '9.999999999999997e+22'],
            '1e23' => ['44b52d02c7e14af6', '1e+23'],
            'above 1e23' => ['44b52d02c7e14af7', '1.0000000000000001e+23'],
            'high-precision integer' => ['444b1ae4d6e2ef4e', '999999999999999700000'],
            'high-precision integer 2' => ['444b1ae4d6e2ef4f', '999999999999999900000'],
            '1e21' => ['444b1ae4d6e2ef50', '1e+21'],
            'below 1e-6' => ['3eb0c6f7a0b5ed8c', '9.999999999999997e-7'],
            '1e-6' => ['3eb0c6f7a0b5ed8d', '0.000001'],
            'rounding 1' => ['41b3de4355555553', '333333333.3333332'],
            'rounding 2' => ['41b3de4355555554', '333333333.33333325'],
            'rounding 3' => ['41b3de4355555555', '333333333.3333333'],
            'rounding 4' => ['41b3de4355555556', '333333333.3333334'],
            'rounding 5' => ['41b3de4355555557', '333333333.33333343'],
            'small negative' => ['becbf647612f3696', '-0.0000033333333333333333'],
            'big fraction' => ['43143ff3c1cb0959', '1424953923781206.2'],
        ];
    }

    #[Test]
    #[DataProvider('appendixB')]
    public function writesNumbersTheWayEcmaScriptDoes(string $ieee754, string $expected): void
    {
        $unpacked = unpack('E', (string)hex2bin($ieee754));
        self::assertIsArray($unpacked);
        $number = $unpacked[1];
        self::assertIsFloat($number);

        self::assertSame($expected, Jcs::number($number));
    }

    #[Test]
    public function canonicalisesTheSection322Example(): void
    {
        // Built with chr(92) so the escapes reach the JSON parser unchanged.
        $b = chr(92);
        $string = $b . 'u20ac$' . $b . 'u000F' . $b . 'u000aA\'' . $b . 'u0042' . $b . 'u0022' . $b . 'u005c' . $b . $b . $b . '"' . $b . '/';
        $json = '{"numbers": [333333333.33333329, 1E30, 4.50, 2e-3, 0.000000000000000000000000001], "string": "' . $string . '", "literals": [null, true, false]}';

        $expected = '{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$'
            . $b . 'u000f' . $b . 'nA\'B' . $b . '"' . $b . $b . $b . $b . $b . '"/"}';

        self::assertSame($expected, Jcs::canonicalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function sortsMembersByUtf16CodeUnitsNotByCodePoints(): void
    {
        $object = [
            '€' => 'Euro Sign',
            "\r" => 'Carriage Return',
            "\u{fb33}" => 'Hebrew Letter Dalet With Dagesh',
            '1' => 'One',
            "\u{1f600}" => 'Emoji: Grinning Face',
            "\u{80}" => 'Control',
            'ö' => 'Latin Small Letter O With Diaeresis',
        ];

        // U+1F600 is a surrogate pair (D83D DE00) and so sorts before U+FB33.
        self::assertSame(
            '{"' . chr(92) . 'r":"Carriage Return","1":"One","' . "\u{80}" . '":"Control","ö":"Latin Small Letter O With Diaeresis",'
            . '"€":"Euro Sign","' . "\u{1f600}" . '":"Emoji: Grinning Face","' . "\u{fb33}" . '":"Hebrew Letter Dalet With Dagesh"}',
            Jcs::canonicalize($object),
        );
    }

    #[Test]
    public function mapsPhpValuesOntoJson(): void
    {
        self::assertSame(
            '{"a":{},"b":[],"c":[1,2,0,1e+21],"d":"/","e":null}',
            Jcs::canonicalize(['e' => null, 'd' => '/', 'c' => [1, 2.0, -0.0, 1e21], 'b' => [], 'a' => new \stdClass()]),
        );
    }

    #[Test]
    public function writesIntegersBeyondTwoToThe53AsDoubles(): void
    {
        self::assertSame('9007199254740992', Jcs::canonicalize(9007199254740992));
        self::assertSame('9223372036854776000', Jcs::canonicalize(PHP_INT_MAX));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unrepresentable(): array
    {
        return [
            'NaN' => [NAN],
            'Infinity' => [INF],
            'invalid UTF-8' => ["\xc3\x28"],
            'a resource-like object' => [new \ArrayObject()],
        ];
    }

    #[Test]
    #[DataProvider('unrepresentable')]
    public function refusesWhatJsonCannotRepresent(mixed $value): void
    {
        $this->expectException(CryptoException::class);
        Jcs::canonicalize(['value' => $value]);
    }
}
