<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ucp\Http\InvalidUcpAgent;
use Webconsulting\AgentNexus\Ucp\Http\StructuredField;
use Webconsulting\AgentNexus\Ucp\Http\UcpAgentHeader;

/**
 * UCP-Agent is an RFC 8941 Dictionary naming the platform profile. The
 * sandbox checks its syntax strictly, because it is the one thing it checks.
 */
final class UcpAgentHeaderTest extends UnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function validHeaders(): array
    {
        return [
            'the canonical form' => ['profile="https://platform.example/.well-known/ucp"', 'https://platform.example/.well-known/ucp', ''],
            'with surrounding space' => ['  profile="https://platform.example/profile.json"  ', 'https://platform.example/profile.json', ''],
            'a version parameter' => ['profile="https://platform.example/.well-known/ucp";version="2026-08-25"', 'https://platform.example/.well-known/ucp', '2026-08-25'],
            'a version member' => ['profile="https://platform.example/.well-known/ucp", version="2026-04-08"', 'https://platform.example/.well-known/ucp', '2026-04-08'],
            'other members are ignored' => ['agent=shopper, profile="https://platform.example/p", trace=?1', 'https://platform.example/p', ''],
            'an escaped quote in the URL' => ['profile="https://platform.example/p?q=\\"x\\""', 'https://platform.example/p?q="x"', ''],
            'loopback over http' => ['profile="http://localhost:8080/.well-known/ucp"', 'http://localhost:8080/.well-known/ucp', ''],
        ];
    }

    #[Test]
    #[DataProvider('validHeaders')]
    public function aWellFormedHeaderNamesTheProfile(string $header, string $profile, string $version): void
    {
        $parsed = UcpAgentHeader::parse($header);

        self::assertSame($profile, $parsed->profile);
        self::assertSame($version, $parsed->version);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidHeaders(): array
    {
        return [
            'missing' => [''],
            'a bare URL' => ['https://platform.example/.well-known/ucp'],
            'an unquoted token' => ['profile=platform'],
            'no profile member' => ['agent="https://platform.example/"'],
            'a profile flag' => ['profile'],
            'an unclosed string' => ['profile="https://platform.example/'],
            'a trailing comma' => ['profile="https://platform.example/",'],
            'an upper-case key' => ['Profile="https://platform.example/"'],
            'plain http elsewhere' => ['profile="http://platform.example/.well-known/ucp"'],
            'a relative URL' => ['profile="/.well-known/ucp"'],
            'credentials in the URL' => ['profile="https://user:secret@platform.example/"'],
            'a fragment' => ['profile="https://platform.example/#profile"'],
            'not a URL at all' => ['profile="..."'],
            'a control character' => ["profile=\"https://platform.example/\x01\""],
        ];
    }

    #[Test]
    #[DataProvider('invalidHeaders')]
    public function aMalformedHeaderIsRejected(string $header): void
    {
        $this->expectException(InvalidUcpAgent::class);
        UcpAgentHeader::parse($header);
    }

    #[Test]
    public function theHeaderTheAgentSendsParsesBack(): void
    {
        $header = UcpAgentHeader::forProfile('https://shop.example/api/agent-nexus/ucp/platform-profile');

        self::assertSame('profile="https://shop.example/api/agent-nexus/ucp/platform-profile"', $header);
        self::assertSame('https://shop.example/api/agent-nexus/ucp/platform-profile', UcpAgentHeader::parse($header)->profile);
    }

    #[Test]
    public function theDictionaryParserReadsEveryBareItemType(): void
    {
        $parsed = StructuredField::parseDictionary('a=1, b=-2.5, c="x", d=token, e=?0, f, g=:aGVsbG8=:, h=(1 "two");p=?1');

        self::assertSame(1, $parsed['a']['value']);
        self::assertSame(-2.5, $parsed['b']['value']);
        self::assertSame('x', $parsed['c']['value']);
        self::assertSame('token', $parsed['d']['value']);
        self::assertFalse($parsed['e']['value']);
        self::assertTrue($parsed['f']['value']);
        self::assertSame('hello', $parsed['g']['value']);
        self::assertSame([['value' => 1, 'params' => []], ['value' => 'two', 'params' => []]], $parsed['h']['value']);
        self::assertSame(['p' => true], $parsed['h']['params']);
    }
}
