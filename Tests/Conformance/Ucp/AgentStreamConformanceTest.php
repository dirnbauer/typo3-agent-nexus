<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Ucp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;
use Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures\UcpStack;
use Webconsulting\AgentNexus\Ucp\Agent\ShoppingAgent;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutRecord;

/**
 * The shopping agent's side of the conversation is AG-UI 1.0: every event of
 * every kind of run, and the inputs the widget sends, against the AG-UI
 * schema.
 */
final class AgentStreamConformanceTest extends ConformanceTestCase
{
    private const array ANCHORS = [
        'RUN_STARTED' => 'RunStartedEvent',
        'RUN_FINISHED' => 'RunFinishedEvent',
        'RUN_ERROR' => 'RunErrorEvent',
        'STEP_STARTED' => 'StepStartedEvent',
        'STEP_FINISHED' => 'StepFinishedEvent',
        'TEXT_MESSAGE_START' => 'TextMessageStartEvent',
        'TEXT_MESSAGE_CONTENT' => 'TextMessageContentEvent',
        'TEXT_MESSAGE_END' => 'TextMessageEndEvent',
        'TOOL_CALL_START' => 'ToolCallStartEvent',
        'TOOL_CALL_ARGS' => 'ToolCallArgsEvent',
        'TOOL_CALL_END' => 'ToolCallEndEvent',
        'TOOL_CALL_RESULT' => 'ToolCallResultEvent',
        'STATE_SNAPSHOT' => 'StateSnapshotEvent',
        'CUSTOM' => 'CustomEvent',
        'REASONING_START' => 'ReasoningStartEvent',
        'REASONING_MESSAGE_START' => 'ReasoningMessageStartEvent',
        'REASONING_MESSAGE_CONTENT' => 'ReasoningMessageContentEvent',
        'REASONING_MESSAGE_END' => 'ReasoningMessageEndEvent',
        'REASONING_END' => 'ReasoningEndEvent',
    ];
    protected bool $resetSingletonInstances = true;

    /**
     * @return array<string, array{0: string}>
     */
    public static function runs(): array
    {
        return [
            'a proposal that stops for approval' => ['propose'],
            'an approval that completes the checkout' => ['approve'],
            'a decline that cancels it' => ['decline'],
            'an approval that still lacks an email address' => ['askAgain'],
            'a forged answer' => ['forged'],
        ];
    }

    #[Test]
    #[DataProvider('runs')]
    public function everyEventConformsToItsAgUiDefinition(string $run): void
    {
        $events = $this->events($run);

        self::assertNotSame([], $events);
        self::assertContains($events[0]['type'], ['RUN_STARTED', 'RUN_ERROR'], 'A stream opens with RUN_STARTED or RUN_ERROR.');
        foreach ($events as $event) {
            $type = is_string($event['type'] ?? null) ? $event['type'] : '';
            self::assertArrayHasKey($type, self::ANCHORS, 'The agent only emits events it is tested for.');
            self::assertConformsTo(SchemaValidator::AGUI_SCHEMA_ID . '#Event', $event);
            self::assertConformsTo(SchemaValidator::AGUI_SCHEMA_ID . '#' . self::ANCHORS[$type], $event);
            self::assertIsInt($event['timestamp'] ?? null, 'Timestamps are integer milliseconds.');
        }
    }

    #[Test]
    public function theApprovalIsAnInterruptOutcome(): void
    {
        $finished = $this->last($this->events('propose'));

        self::assertConformsTo(SchemaValidator::AGUI_SCHEMA_ID . '#RunFinishedInterruptOutcome', $finished['outcome']);
        self::assertConformsTo(SchemaValidator::AGUI_SCHEMA_ID . '#Interrupt', $finished['outcome']['interrupts'][0]);
    }

    #[Test]
    public function theInputsTheWidgetSendsAreRunAgentInputs(): void
    {
        self::assertConformsTo(SchemaValidator::AGUI_SCHEMA_ID . '#RunAgentInput', $this->input('t-1', 'r-1'));
        self::assertConformsTo(SchemaValidator::AGUI_SCHEMA_ID . '#RunAgentInput', $this->input('t-1', 'r-2', [
            ['interruptId' => 'int_0123456789abcdef', 'status' => 'resolved', 'payload' => ['approved' => true, 'email' => 'ada@example.org']],
        ]));
        self::assertConformsTo(SchemaValidator::AGUI_SCHEMA_ID . '#RunAgentInput', $this->input('t-1', 'r-3', [
            ['interruptId' => 'int_0123456789abcdef', 'status' => 'cancelled'],
        ]));
    }

    #[Test]
    public function anInterruptCarryingNullIsRejected(): void
    {
        $interrupt = $this->last($this->events('propose'))['outcome']['interrupts'][0];
        $interrupt['expiresAt'] = null;

        self::assertViolates(SchemaValidator::AGUI_SCHEMA_ID . '#Interrupt', $interrupt, 'AG-UI 1.0 omits optional fields instead of sending null.');
    }

    #[Test]
    public function aSuccessOutcomeCannotCarryInterrupts(): void
    {
        self::assertViolates(
            SchemaValidator::AGUI_SCHEMA_ID . '#RunFinishedSuccessOutcome',
            ['type' => 'success', 'interrupts' => [['id' => 'int_1', 'reason' => 'confirmation']]],
        );
    }

    #[Test]
    public function theHomeMadeApprovalFieldIsNotPartOfRunAgentInput(): void
    {
        $input = $this->input('t-1', 'r-2');
        $input['approval'] = ['decision' => 'approved'];

        self::assertViolates(SchemaValidator::AGUI_SCHEMA_ID . '#RunAgentInput', $input, '3.1 answered approvals in a home-made field; 1.0 uses resume.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(string $run): array
    {
        $stack = new UcpStack();
        $proposal = $stack->runAgent($this->input('t-1', 'r-1'));
        if ($run === 'propose') {
            return $proposal;
        }
        $object = array_values($stack->storage->objects)[0];
        $approval = CheckoutRecord::meta($object)[ShoppingAgent::META_APPROVAL] ?? [];
        $interruptId = is_array($approval) && is_string($approval['interruptId'] ?? null) ? $approval['interruptId'] : '';

        return $stack->runAgent($this->input('t-1', 'r-2', [match ($run) {
            'approve' => ['interruptId' => $interruptId, 'status' => 'resolved', 'payload' => ['approved' => true, 'email' => 'ada@example.org']],
            'decline' => ['interruptId' => $interruptId, 'status' => 'resolved', 'payload' => ['approved' => false]],
            'askAgain' => ['interruptId' => $interruptId, 'status' => 'resolved', 'payload' => ['approved' => true]],
            default => ['interruptId' => 'int_forged', 'status' => 'resolved', 'payload' => ['approved' => true]],
        }]));
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function last(array $events): array
    {
        self::assertNotSame([], $events);
        return $events[count($events) - 1];
    }

    /**
     * @param list<array<string, mixed>> $resume
     * @return array<string, mixed>
     */
    private function input(string $threadId, string $runId, array $resume = []): array
    {
        $input = [
            'threadId' => $threadId,
            'runId' => $runId,
            'protocolVersion' => '1.0',
            'messages' => [['id' => 'm-' . $runId, 'role' => 'user', 'content' => 'Pro licence']],
            'forwardedProps' => ['agentNexus' => ['ce' => 12, 'page' => 3, 'url' => 'https://shop.example/ucp', 'intent' => 'pro']],
        ];
        if ($resume !== []) {
            $input['resume'] = $resume;
        }
        return $input;
    }
}
