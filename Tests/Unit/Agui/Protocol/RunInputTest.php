<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Agui\Protocol;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Agui\Protocol\InvalidRunInput;
use Webconsulting\AgentNexus\Agui\Protocol\RunInput;

/**
 * The producer's side of the processing model: unrecognised material in a
 * RunAgentInput is stripped and noted, a malformed known value refuses the
 * input before the run starts, and only another major version is refused for
 * its version.
 */
final class RunInputTest extends UnitTestCase
{
    #[Test]
    public function theInputOfThe10ClientIsRead(): void
    {
        $input = RunInput::fromJson((string)json_encode([
            'threadId' => 'thread-1',
            'runId' => 'run-1',
            'protocolVersion' => '1.0',
            'messages' => [
                ['id' => 'm-1', 'role' => 'user', 'content' => 'Which plan fits?'],
                ['id' => 'm-2', 'role' => 'assistant', 'content' => 'Team.', 'toolCalls' => [
                    ['id' => 'c-1', 'type' => 'function', 'function' => ['name' => 'confirm_booking', 'arguments' => '{}']],
                ]],
                ['id' => 'm-3', 'role' => 'tool', 'content' => 'declined', 'toolCallId' => 'c-1'],
            ],
            'tools' => [],
            'context' => [],
            'state' => new \stdClass(),
            'forwardedProps' => new \stdClass(),
        ]));

        self::assertSame('thread-1', $input->threadId);
        self::assertSame('run-1', $input->runId);
        self::assertSame('1.0', $input->protocolVersion);
        self::assertCount(3, $input->messages);
        self::assertSame('Which plan fits?', $input->lastUserText());
        self::assertSame([], $input->warnings);
        self::assertSame('{}', json_encode($input->toArray()['state']), 'An empty state object stays an object.');
        self::assertSame([], $input->tools);
    }

    #[Test]
    public function absentToolsContextAndStateAreAccepted(): void
    {
        $input = RunInput::fromJson('{"threadId":"t","runId":"r","messages":[]}');

        self::assertNull($input->tools);
        self::assertNull($input->context);
        self::assertNull($input->state);
        self::assertSame(['threadId' => 't', 'runId' => 'r', 'messages' => []], $input->toArray());
    }

    #[Test]
    public function unrecognisedMaterialIsStrippedAndNoted(): void
    {
        $input = RunInput::fromJson((string)json_encode([
            'threadId' => 't',
            'runId' => 'r',
            'approval' => ['decision' => 'approved'],
            'messages' => [
                ['id' => 'm-1', 'role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'Look at this'],
                    ['type' => 'hologram', 'source' => ['type' => 'url', 'value' => 'https://example.org/h']],
                ]],
                ['id' => 'm-2', 'role' => 'oracle', 'content' => 'from the future'],
            ],
        ]));

        self::assertArrayNotHasKey('approval', $input->toArray(), 'Unknown input members are never echoed.');
        self::assertCount(1, $input->messages, 'A message with an unknown role is dropped.');
        self::assertSame('Look at this', $input->lastUserText(), 'A content part of an unknown kind is dropped.');
        self::assertCount(3, $input->warnings);
    }

    #[Test]
    public function historicallyAcceptedNullsAreReadAsAbsent(): void
    {
        $input = RunInput::fromJson('{"threadId":"t","runId":"r","messages":[],"state":null,"forwardedProps":null,'
            . '"tools":[{"name":"x","description":"y","parameters":null}],"resume":[{"interruptId":"i","status":"cancelled","payload":null}]}');

        self::assertNull($input->state);
        self::assertNull($input->forwardedProps);
        self::assertSame([['name' => 'x', 'description' => 'y']], $input->tools);
        self::assertSame([['interruptId' => 'i', 'status' => 'cancelled']], $input->resume);
    }

    #[Test]
    public function theWidgetContextIsReadFromForwardedPropsAndNotStored(): void
    {
        $input = RunInput::fromJson('{"threadId":"t","runId":"r","messages":[],"forwardedProps":{"agentNexus":{"ce":12,"page":3,"url":"https://example.org/","preset":"support"},"theme":"dark"}}');

        self::assertSame(['ce' => 12, 'page' => 3, 'url' => 'https://example.org/', 'preset' => 'support'], $input->agentNexus());
        self::assertSame(['theme' => 'dark'], $input->toArray()['forwardedProps']);
    }

    #[Test]
    public function aNewerMinorVersionIsServedWithANote(): void
    {
        $input = RunInput::fromJson('{"threadId":"t","runId":"r","messages":[],"protocolVersion":"1.4"}');

        self::assertSame('1.4', $input->protocolVersion);
        self::assertStringContainsString('1.4', $input->warnings[0] ?? '');
    }

    #[Test]
    public function aVersionThatCannotBeReadIsServed(): void
    {
        self::assertSame('next', RunInput::fromJson('{"threadId":"t","runId":"r","messages":[],"protocolVersion":"next"}')->protocolVersion);
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function refusedInputs(): iterable
    {
        yield 'not JSON' => ['threadId=t', 400, 'invalid_json'];
        yield 'a JSON array' => ['[]', 400, 'invalid_input'];
        yield 'no messages' => ['{"threadId":"t","runId":"r"}', 400, 'invalid_input'];
        yield 'an empty run id' => ['{"threadId":"t","runId":"","messages":[]}', 400, 'invalid_input'];
        yield 'a number as message content' => ['{"threadId":"t","runId":"r","messages":[{"id":"m","role":"user","content":5}]}', 400, 'invalid_input'];
        yield 'a message without an id' => ['{"threadId":"t","runId":"r","messages":[{"role":"user","content":"x"}]}', 400, 'invalid_input'];
        yield 'a resume status that does not exist' => ['{"threadId":"t","runId":"r","messages":[],"resume":[{"interruptId":"i","status":"maybe"}]}', 400, 'invalid_input'];
        yield 'metadata that is not an object' => ['{"threadId":"t","runId":"r","messages":[{"id":"m","role":"user","content":"x","metadata":[1]}]}', 400, 'invalid_input'];
        yield 'another major version' => ['{"threadId":"t","runId":"r","messages":[],"protocolVersion":"2.0"}', 400, 'unsupported_protocol_version'];
    }

    #[Test]
    #[DataProvider('refusedInputs')]
    public function aMalformedInputIsRefusedBeforeTheRunStarts(string $body, int $status, string $reason): void
    {
        try {
            RunInput::fromJson($body);
        } catch (InvalidRunInput $e) {
            self::assertSame($status, $e->status);
            self::assertSame($reason, $e->reason);
            return;
        }
        self::fail('The input was accepted.');
    }

    #[Test]
    public function anInputThatIsTooLargeIsRefusedWith413(): void
    {
        try {
            RunInput::fromJson(str_repeat(' ', RunInput::MAX_BYTES + 1));
        } catch (InvalidRunInput $e) {
            self::assertSame(413, $e->status);
            self::assertSame('input_too_large', $e->reason);
            return;
        }
        self::fail('The input was accepted.');
    }
}
