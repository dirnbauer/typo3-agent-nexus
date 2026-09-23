<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Shared\Traffic;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRedactor;

final class TrafficRedactorTest extends UnitTestCase
{
    private TrafficRedactor $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new TrafficRedactor();
    }

    #[Test]
    public function onlyAllowListedHeadersAreKept(): void
    {
        $headers = $this->subject->headers([
            'Content-Type' => ['application/json'],
            'Cookie' => ['be_typo_user=secret'],
            'Authorization' => ['Bearer secret'],
            'A2A-Version' => ['1.0'],
            'X-Unknown' => ['x'],
        ]);

        self::assertSame(['a2a-version' => '1.0', 'content-type' => 'application/json'], $headers);
    }

    #[Test]
    public function personalDataIsMaskedAtAnyDepth(): void
    {
        $body = (string)json_encode([
            'buyer' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.org'],
            'line_items' => [['item' => ['id' => 'pro-license'], 'quantity' => 1]],
            'phone_number' => '+43 1 234',
        ]);

        $masked = json_decode($this->subject->body($body, true), true);

        self::assertIsArray($masked);
        self::assertSame(TrafficRedactor::MASK, $masked['buyer']['email']);
        self::assertSame(TrafficRedactor::MASK, $masked['buyer']['first_name']);
        self::assertSame(TrafficRedactor::MASK, $masked['phone_number']);
        self::assertSame('pro-license', $masked['line_items'][0]['item']['id'], 'Protocol data stays readable.');
    }

    #[Test]
    public function jsonInsideAStringIsMaskedToo(): void
    {
        $event = [
            'type' => 'TOOL_CALL_ARGS',
            'toolCallId' => 'tc-1',
            'delta' => (string)json_encode(['name' => 'Ada', 'email' => 'ada@example.org', 'plan' => 'Team']),
        ];

        $masked = $this->subject->data($event, true);
        $arguments = json_decode((string)$masked['delta'], true);

        self::assertIsArray($arguments);
        self::assertSame(TrafficRedactor::MASK, $arguments['email']);
        self::assertSame('Team', $arguments['plan']);
    }

    #[Test]
    public function withoutRedactionNothingIsChangedButTheSize(): void
    {
        $body = (string)json_encode(['email' => 'ada@example.org']);

        self::assertSame($body, $this->subject->body($body, false));
        self::assertStringEndsWith('[truncated]', $this->subject->cap(str_repeat('x', 100), 10));
    }

    #[Test]
    public function aPlainStringThatIsNotJsonIsLeftAlone(): void
    {
        self::assertSame(['text' => '{not json'], $this->subject->data(['text' => '{not json'], true));
    }
}
