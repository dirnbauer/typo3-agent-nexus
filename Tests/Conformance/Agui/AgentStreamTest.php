<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Agui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Agui\Agent\Scenarios;
use Webconsulting\AgentNexus\Agui\Event\EventFactory;
use Webconsulting\AgentNexus\Agui\Protocol\EventType;
use Webconsulting\AgentNexus\Agui\Protocol\RunInput;
use Webconsulting\AgentNexus\Agui\Service\ThreadState;
use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;
use Webconsulting\AgentNexus\Tests\Unit\Agui\RunsAgents;

/**
 * Every event the demo agents send, every interrupt they raise and every
 * run record they leave, validated against the AG-UI 1.0 schema — plus the
 * traps: an undeclared field, an optional field sent as null, a float
 * timestamp, `[]` where an object belongs, the 3.x reasoning shape.
 */
final class AgentStreamTest extends ConformanceTestCase
{
    use RunsAgents;

    private const string SCHEMA = SchemaValidator::AGUI_SCHEMA_ID;

    /**
     * @return iterable<string, array{string, array<string, mixed>|null}>
     */
    public static function streams(): iterable
    {
        foreach (['plan', 'support', 'seo', 'translate', 'news'] as $preset) {
            yield $preset . ': proposal' => [$preset, null];
        }
        yield 'plan: approved' => ['plan', ['status' => 'resolved', 'payload' => ['approved' => true, 'name' => 'Ada', 'email' => 'ada@example.org']]];
        yield 'support: approved' => ['support', ['status' => 'resolved', 'payload' => ['approved' => true, 'name' => 'Ada', 'email' => 'ada@example.org', 'preferredTime' => 'Friday']]];
        yield 'seo: approved with edits' => ['seo', ['status' => 'resolved', 'payload' => ['approved' => true, 'editedArgs' => ['value' => 'Shorter.']]]];
        yield 'plan: declined' => ['plan', ['status' => 'resolved', 'payload' => ['approved' => false]]];
        yield 'plan: cancelled' => ['plan', ['status' => 'cancelled']];
        yield 'plan: invalid answer' => ['plan', ['status' => 'resolved', 'payload' => ['approved' => true]]];
    }

    /**
     * @param array<string, mixed>|null $answer
     */
    #[Test]
    #[DataProvider('streams')]
    public function everyEventConformsToItsDefinition(string $preset, ?array $answer): void
    {
        $events = $this->stream($preset, $answer);

        self::assertNotSame([], $events);
        foreach ($events as $event) {
            $type = EventType::from(is_string($event['type'] ?? null) ? $event['type'] : '');
            self::assertConformsTo(self::SCHEMA . '#' . $type->schemaAnchor(), $event);
            self::assertConformsTo(self::SCHEMA . '#Event', $event);
        }
    }

    #[Test]
    public function theInterruptAndItsOutcomeConform(): void
    {
        $finished = self::first($this->propose('plan'), 'RUN_FINISHED');

        self::assertConformsTo(self::SCHEMA . '#RunFinishedInterruptOutcome', $finished['outcome']);
        self::assertConformsTo(self::SCHEMA . '#Interrupt', self::interruptOf([$finished]));
    }

    #[Test]
    public function theRunRecordKeepsSpecificationShapes(): void
    {
        $input = self::input('thread-1', 'run-1', ['state' => new \stdClass(), 'forwardedProps' => ['agentNexus' => ['ce' => 1, 'page' => 2]]]);
        $record = self::record($this->propose('plan'), $input);

        self::assertConformsTo(self::SCHEMA . '#RunAgentInput', $record->payload['input']);
        self::assertIsArray($record->payload['messages'] ?? null);
        foreach ($record->payload['messages'] as $message) {
            self::assertConformsTo(self::SCHEMA . '#Message', $message);
        }
        self::assertIsArray($record->payload['interrupts'] ?? null);
        foreach ($record->payload['interrupts'] as $interrupt) {
            self::assertConformsTo(self::SCHEMA . '#Interrupt', $interrupt);
        }
        self::assertConformsTo(self::SCHEMA . '#RunFinishedOutcome', $record->payload['outcome']);
    }

    #[Test]
    public function aReadInputConformsToRunAgentInput(): void
    {
        $input = RunInput::fromJson((string)json_encode([
            'threadId' => 't',
            'runId' => 'r',
            'protocolVersion' => '1.0',
            'messages' => [
                ['id' => 'm-1', 'role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => 'See the picture'],
                    ['type' => 'image', 'source' => ['type' => 'url', 'value' => 'https://example.org/a.png', 'mimeType' => 'image/png']],
                ]],
                ['id' => 'm-2', 'role' => 'assistant', 'toolCalls' => [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'x', 'arguments' => '{}']]]],
                ['id' => 'm-3', 'role' => 'tool', 'toolCallId' => 'c', 'content' => 'ok'],
            ],
            'tools' => [['name' => 'x', 'description' => 'Does x.', 'parameters' => ['type' => 'object']]],
            'context' => [['description' => 'Page', 'value' => 'Pricing']],
            'resume' => [['interruptId' => 'i', 'status' => 'resolved', 'payload' => ['approved' => true]]],
            'future' => 'stripped',
        ]));

        self::assertConformsTo(self::SCHEMA . '#RunAgentInput', $input->toArray());
    }

    #[Test]
    public function tokenUsageWithoutCountsConforms(): void
    {
        self::assertConformsTo(self::SCHEMA . '#TokenUsage', EventFactory::tokenUsage('openai', 'gpt-test'));
        self::assertConformsTo(self::SCHEMA . '#TokenUsage', EventFactory::tokenUsage(inputTokens: 10, outputTokens: 5));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function traps(): iterable
    {
        $started = EventFactory::runStarted('t', 'r');
        yield 'an undeclared field (the 3.x approval)' => ['RunStartedEvent', $started + ['approval' => ['decision' => 'approved']]];
        yield 'an optional result sent as null' => ['RunFinishedEvent', ['type' => 'RUN_FINISHED', 'threadId' => 't', 'runId' => 'r', 'result' => null]];
        yield 'an optional role sent as null' => ['TextMessageStartEvent', ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'm', 'role' => null]];
        yield 'a float timestamp' => ['RunStartedEvent', ['timestamp' => 1790000000000.5] + $started];
        yield 'metadata as a JSON array' => ['CustomEvent', ['type' => 'CUSTOM', 'name' => 'x.y', 'value' => 1, 'metadata' => []]];
        yield 'activity content as a JSON array' => ['ActivitySnapshotEvent', ['type' => 'ACTIVITY_SNAPSHOT', 'messageId' => 'a', 'activityType' => 'x', 'content' => []]];
        yield 'the 3.x REASONING_START without an id' => ['Event', ['type' => 'REASONING_START']];
        yield 'the 3.x reasoning content without a message' => ['Event', ['type' => 'REASONING_MESSAGE_CONTENT', 'delta' => 'x']];
        yield 'a retired THINKING event' => ['Event', ['type' => 'THINKING_START']];
        yield 'an interrupt outcome with no interrupts' => ['RunFinishedEvent', ['type' => 'RUN_FINISHED', 'threadId' => 't', 'runId' => 'r', 'outcome' => ['type' => 'interrupt', 'interrupts' => []]]];
        yield 'a success outcome carrying interrupts' => ['RunFinishedOutcome', ['type' => 'success', 'interrupts' => [['id' => 'i', 'reason' => 'confirmation']]]];
        yield 'a run input with the 3.x approval field' => ['RunAgentInput', ['threadId' => 't', 'runId' => 'r', 'messages' => [], 'approval' => ['decision' => 'approved']]];
        yield 'a resume entry with null payload' => ['ResumeEntry', ['interruptId' => 'i', 'status' => 'resolved', 'payload' => null]];
        yield 'a subagent id on a run-scoped event' => ['RunStartedEvent', $started + ['subagentRunId' => 's']];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataProvider('traps')]
    public function theSchemaRejectsTheTrap(string $anchor, array $payload): void
    {
        self::assertViolates(self::SCHEMA . '#' . $anchor, $payload);
    }

    /**
     * @param array<string, mixed>|null $answer
     * @return list<array<string, mixed>>
     */
    private function stream(string $preset, ?array $answer): array
    {
        $proposal = $this->propose($preset);
        if ($answer === null) {
            return $proposal;
        }
        $interrupt = self::interruptOf($proposal);
        $input = self::input('thread-1', 'run-2', ['resume' => [['interruptId' => $interrupt['id']] + $answer]], '');
        $resumption = (new ThreadState([self::record($proposal, self::input('thread-1', 'run-1'))]))->resume($input->resume);
        $scenario = (new Scenarios())->get($resumption->preset);
        self::assertNotNull($scenario);
        return iterator_to_array($this->runner()->resume($input, $scenario, $resumption), false);
    }
}
