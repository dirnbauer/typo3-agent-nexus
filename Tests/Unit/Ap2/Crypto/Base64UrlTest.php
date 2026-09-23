<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Crypto;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Crypto\Base64Url;
use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;

final class Base64UrlTest extends UnitTestCase
{
    #[Test]
    public function encodesWithTheUrlSafeAlphabetAndNoPadding(): void
    {
        self::assertSame('-_8', Base64Url::encode("\xfb\xff"));
        self::assertSame('YQ', Base64Url::encode('a'));
        self::assertSame('', Base64Url::encode(''));
    }

    #[Test]
    public function roundTripsArbitraryBytes(): void
    {
        self::assertSame('', Base64Url::decode(''));
        for ($length = 1; $length < 70; $length++) {
            $bytes = random_bytes($length);
            self::assertSame($bytes, Base64Url::decode(Base64Url::encode($bytes)));
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidEncodings(): array
    {
        return [
            'standard alphabet' => ['+/8'],
            'padding' => ['YQ=='],
            'impossible length' => ['YWJjZ'],
            'whitespace' => ['YW Jj'],
            'non-canonical trailing bits' => ['YR'],
        ];
    }

    #[Test]
    #[DataProvider('invalidEncodings')]
    public function refusesAnythingButCanonicalBase64Url(string $encoded): void
    {
        $this->expectException(CryptoException::class);
        Base64Url::decode($encoded);
    }
}
