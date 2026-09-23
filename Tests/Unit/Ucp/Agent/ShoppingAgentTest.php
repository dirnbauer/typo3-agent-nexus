<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Agent;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Shared\Llm\TruncatedAnswer;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRedactor;
use Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures\ScriptedLanguageModel;
use Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures\UcpStack;
use Webconsulting\AgentNexus\Ucp\Agent\PendingApproval;
use Webconsulting\AgentNexus\Ucp\Agent\ShoppingAgent;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutRecord;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * The shopping agent proposes, stops, and only buys on an explicit yes.
 */
final class ShoppingAgentTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private UcpStack $stack;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stack = new UcpStack();
    }

    #[Test]
    public function aFirstRunStopsForApprovalWithoutSendingTheCompleteRequest(): void
    {
        $events = $this->stack->runAgent($this->input('t-1', 'r-1'));

        self::assertSame('RUN_STARTED', $events[0]['type']);
        self::assertSame('1.0', $events[0]['protocolVersion']);
        $finished = $this->last($events);
        self::assertSame('RUN_FINISHED', $finished['type']);
        self::assertSame('interrupt', $finished['outcome']['type'] ?? null);
        $interrupt = $finished['outcome']['interrupts'][0] ?? [];
        self::assertIsArray($interrupt);
        self::assertSame('confirmation', $interrupt['reason']);
        self::assertStringStartsWith('Approve this order: Desiderio Pro Licence for €49.00.', $interrupt['message']);

        $calls = $this->toolCalls($events);
        self::assertSame([ShoppingAgent::TOOL_DISCOVER, ShoppingAgent::TOOL_CREATE, ShoppingAgent::TOOL_COMPLETE], array_column($calls, 'name'));
        self::assertSame($interrupt['toolCallId'], $calls[2]['id'], 'The approval is about the proposed complete call.');
        self::assertNull($calls[2]['result'], 'The complete request is proposed, not sent.');
        self::assertSame(201, $calls[1]['result']['status'] ?? null);

        $checkout = $this->onlyCheckout();
        self::assertSame(Spec::STATUS_INCOMPLETE, $checkout->state);
        self::assertSame('t-1', $checkout->contextId);
        $approval = $this->approval($checkout);
        self::assertSame($interrupt['id'], $approval->interruptId);
        self::assertTrue($approval->isOpen());
        self::assertSame(4900, $approval->amount);

        $snapshot = $this->ofType($events, 'STATE_SNAPSHOT');
        self::assertSame(Spec::STATUS_INCOMPLETE, $this->last($snapshot)['snapshot']['checkout']['status'] ?? null);
        self::assertSame(['approved', 'email'], array_keys($interrupt['responseSchema']['properties']));
        self::assertSame(['required' => ['email']], $interrupt['responseSchema']['then'], 'The email is required on approval while the checkout has none.');
    }

    #[Test]
    public function everyToolCallStepAndMessageIsClosed(): void
    {
        foreach ([$this->stack->runAgent($this->input('t-1', 'r-1')), $this->resume('t-1', 'r-2', true, 'ada@example.org')] as $events) {
            $open = [];
            foreach ($events as $event) {
                [$kind, $id] = match ($event['type']) {
                    'TOOL_CALL_START' => ['+tool', $event['toolCallId']],
                    'TOOL_CALL_END' => ['-tool', $event['toolCallId']],
                    'STEP_STARTED' => ['+step', $event['stepName']],
                    'STEP_FINISHED' => ['-step', $event['stepName']],
                    'TEXT_MESSAGE_START', 'REASONING_MESSAGE_START' => ['+message', $event['messageId']],
                    'TEXT_MESSAGE_END', 'REASONING_MESSAGE_END' => ['-message', $event['messageId']],
                    'REASONING_START' => ['+span', $event['messageId']],
                    'REASONING_END' => ['-span', $event['messageId']],
                    default => ['', ''],
                };
                if ($kind === '') {
                    continue;
                }
                $key = substr($kind, 1) . ':' . $id;
                if ($kind[0] === '+') {
                    self::assertArrayNotHasKey($key, $open, 'Opened twice: ' . $key);
                    $open[$key] = true;
                } else {
                    self::assertArrayHasKey($key, $open, 'Closed without opening: ' . $key);
                    unset($open[$key]);
                }
            }
            self::assertSame([], $open, 'Still open when the run finished.');
            self::assertSame('RUN_FINISHED', $this->last($events)['type']);
            self::assertCount(1, $this->ofType($events, 'RUN_STARTED'));
        }
    }

    #[Test]
    public function approvingWithAnEmailAddressCompletesTheCheckout(): void
    {
        $proposal = $this->stack->runAgent($this->input('t-1', 'r-1'));
        $toolCallId = $this->interrupt($proposal)['toolCallId'];

        $events = $this->resume('t-1', 'r-2', true, 'ada@example.org');

        self::assertSame(['type' => 'success'], $this->last($events)['outcome']);
        $calls = $this->toolCalls($events);
        self::assertSame([ShoppingAgent::TOOL_UPDATE], array_column($calls, 'name'), 'The complete call is not proposed again.');
        $results = array_values(array_filter($this->ofType($events, 'TOOL_CALL_RESULT'), static fn(array $e): bool => $e['toolCallId'] === $toolCallId));
        self::assertCount(1, $results, 'The approved call gets its result on its original id.');
        $content = json_decode($results[0]['content'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($content);
        self::assertSame(200, $content['status']);
        self::assertSame(Spec::STATUS_COMPLETED, $content['body']['status']);

        $checkout = $this->onlyCheckout();
        self::assertSame(Spec::STATUS_COMPLETED, $checkout->state);
        self::assertSame(PendingApproval::APPROVED, $this->approval($checkout)->resolution);
        self::assertIsArray(CheckoutRecord::checkout($checkout)['order'] ?? null);
        self::assertSame(
            [Spec::STATUS_INCOMPLETE, Spec::STATUS_READY, Spec::STATUS_IN_PROGRESS, Spec::STATUS_COMPLETED],
            array_column($checkout->history, 'state'),
        );
    }

    #[Test]
    public function theCompleteRequestSentIsTheOneTheVisitorApproved(): void
    {
        $proposal = $this->stack->runAgent($this->input('t-1', 'r-1', email: 'ada@example.org'));
        $args = $this->argsOf($proposal, $this->interrupt($proposal)['toolCallId']);

        $this->resume('t-1', 'r-2', true);

        $approval = $this->approval($this->onlyCheckout());
        self::assertSame($args['headers']['Idempotency-Key'] ?? null, $approval->request['headers']['Idempotency-Key']);
        self::assertSame($args['body'] ?? null, $approval->request['body']);
    }

    #[Test]
    public function anEmailAddressGivenUpFrontMakesTheCheckoutReadyBeforeTheApproval(): void
    {
        $events = $this->stack->runAgent($this->input('t-1', 'r-1', email: 'ada@example.org'));

        $interrupt = $this->interrupt($events);
        self::assertArrayNotHasKey('then', $interrupt['responseSchema']);
        self::assertSame(Spec::STATUS_READY, $this->onlyCheckout()->state);
    }

    #[Test]
    public function approvingWithoutAnEmailAddressAsksAgain(): void
    {
        $proposal = $this->stack->runAgent($this->input('t-1', 'r-1'));
        $interruptId = $this->interrupt($proposal)['id'];

        $events = $this->resume('t-1', 'r-2', true);

        self::assertSame($interruptId, $this->interrupt($events)['id'], 'The same interrupt stays open.');
        self::assertTrue($this->approval($this->onlyCheckout())->isOpen());
        self::assertSame(Spec::STATUS_INCOMPLETE, $this->onlyCheckout()->state);
    }

    #[Test]
    public function decliningCancelsTheCheckoutAndClosesTheProposedCall(): void
    {
        $proposal = $this->stack->runAgent($this->input('t-1', 'r-1', email: 'ada@example.org'));
        $toolCallId = $this->interrupt($proposal)['toolCallId'];

        $events = $this->resume('t-1', 'r-2', false);

        self::assertSame([ShoppingAgent::TOOL_CANCEL], array_column($this->toolCalls($events), 'name'));
        $closing = array_values(array_filter($this->ofType($events, 'TOOL_CALL_RESULT'), static fn(array $e): bool => $e['toolCallId'] === $toolCallId));
        self::assertCount(1, $closing);
        self::assertSame(['sent' => false], array_intersect_key(json_decode($closing[0]['content'], true, 512, JSON_THROW_ON_ERROR), ['sent' => true]));
        self::assertSame(Spec::STATUS_CANCELED, $this->onlyCheckout()->state);
        self::assertSame(PendingApproval::DECLINED, $this->approval($this->onlyCheckout())->resolution);
    }

    #[Test]
    public function abandoningTheApprovalCancelsTheCheckout(): void
    {
        $proposal = $this->stack->runAgent($this->input('t-1', 'r-1'));

        $events = $this->stack->runAgent($this->input('t-1', 'r-2', resume: [['interruptId' => $this->interrupt($proposal)['id'], 'status' => 'cancelled']]));

        self::assertSame(['type' => 'success'], $this->last($events)['outcome']);
        self::assertSame(Spec::STATUS_CANCELED, $this->onlyCheckout()->state);
    }

    #[Test]
    public function aForgedAnswerIsRefusedAndNothingIsBought(): void
    {
        $this->stack->runAgent($this->input('t-1', 'r-1', email: 'ada@example.org'));

        $events = $this->stack->runAgent($this->input('t-1', 'r-2', resume: [['interruptId' => 'int_0000000000000000', 'status' => 'resolved', 'payload' => ['approved' => true]]]));

        self::assertCount(1, $events);
        self::assertSame('RUN_ERROR', $events[0]['type']);
        self::assertSame('unknown_interrupt', $events[0]['code']);
        self::assertSame(Spec::STATUS_READY, $this->onlyCheckout()->state);
        self::assertTrue($this->approval($this->onlyCheckout())->isOpen());
    }

    #[Test]
    public function anAnswerFromAnotherConversationIsRefused(): void
    {
        $proposal = $this->stack->runAgent($this->input('t-1', 'r-1', email: 'ada@example.org'));

        $events = $this->stack->runAgent($this->input('t-2', 'r-2', resume: [['interruptId' => $this->interrupt($proposal)['id'], 'status' => 'resolved', 'payload' => ['approved' => true]]]));

        self::assertSame('RUN_ERROR', $events[0]['type']);
        self::assertSame(Spec::STATUS_READY, $this->onlyCheckout()->state);
    }

    #[Test]
    public function anythingButAnExplicitYesIsANo(): void
    {
        $proposal = $this->stack->runAgent($this->input('t-1', 'r-1', email: 'ada@example.org'));

        $this->stack->runAgent($this->input('t-1', 'r-2', resume: [['interruptId' => $this->interrupt($proposal)['id'], 'status' => 'resolved', 'payload' => ['approved' => 'yes']]]));

        self::assertSame(Spec::STATUS_CANCELED, $this->onlyCheckout()->state);
    }

    #[Test]
    public function aSecondDifferentAnswerIsRefused(): void
    {
        $this->stack->runAgent($this->input('t-1', 'r-1', email: 'ada@example.org'));
        $this->resume('t-1', 'r-2', true);

        $events = $this->resume('t-1', 'r-3', false);

        self::assertSame('RUN_ERROR', $events[0]['type']);
        self::assertSame('interrupt_answered', $events[0]['code']);
        self::assertSame(Spec::STATUS_COMPLETED, $this->onlyCheckout()->state);
    }

    #[Test]
    public function repeatingTheSameAnswerIsSafe(): void
    {
        $this->stack->runAgent($this->input('t-1', 'r-1', email: 'ada@example.org'));
        $first = $this->resume('t-1', 'r-2', true);
        $order = CheckoutRecord::checkout($this->onlyCheckout())['order'] ?? null;

        $again = $this->resume('t-1', 'r-3', true);

        self::assertSame(['type' => 'success'], $this->last($again)['outcome']);
        self::assertSame($this->last($first)['result'] ?? null, $this->last($again)['result'] ?? null);
        self::assertSame($order, CheckoutRecord::checkout($this->onlyCheckout())['order'] ?? null);
        self::assertCount(1, array_filter($this->onlyCheckout()->history, static fn(array $h): bool => $h['state'] === Spec::STATUS_COMPLETED));
    }

    #[Test]
    public function aNewRunOnAWaitingConversationIsRefused(): void
    {
        $this->stack->runAgent($this->input('t-1', 'r-1'));

        $events = $this->stack->runAgent($this->input('t-1', 'r-2'));

        self::assertSame('RUN_ERROR', $events[0]['type']);
        self::assertSame('interrupt_pending', $events[0]['code']);
        self::assertCount(1, $this->stack->storage->objects);
    }

    #[Test]
    public function aDeclinedSandboxPaymentLeavesTheCheckoutReady(): void
    {
        $this->stack->runAgent($this->input('t-1', 'r-1', email: 'ada@example.org', payment: 'decline'));

        $events = $this->resume('t-1', 'r-2', true);

        self::assertSame(Spec::STATUS_READY, $this->onlyCheckout()->state);
        self::assertStringContainsString('did not place the order', implode(' ', array_column($this->ofType($events, 'TEXT_MESSAGE_CONTENT'), 'delta')));
    }

    #[Test]
    public function theAgencyWishBuysTheBundleAndTheOnboarding(): void
    {
        $events = $this->stack->runAgent($this->input('t-1', 'r-1', intent: 'agency'));

        $checkout = CheckoutRecord::checkout($this->onlyCheckout());
        $ids = array_map(static fn(array $line): mixed => $line['item']['id'] ?? null, is_array($checkout['line_items'] ?? null) ? array_values(array_filter($checkout['line_items'], is_array(...))) : []);
        self::assertSame(['agency-bundle', 'onboarding-addon'], $ids);
        self::assertStringContainsString('Agency Bundle and Onboarding Add-on for €448.00', $this->interrupt($events)['message']);
    }

    #[Test]
    public function aGenericClientIsUnderstoodFromItsMessage(): void
    {
        $this->stack->runAgent(['threadId' => 't-1', 'runId' => 'r-1', 'messages' => [['id' => 'm-1', 'role' => 'user', 'content' => 'I need priority support']]]);

        self::assertSame('€99.00 · 1 item', $this->onlyCheckout()->label);
    }

    #[Test]
    public function personalDataIsMaskedInTheNarratedRequests(): void
    {
        $events = $this->stack->runAgent($this->input('t-1', 'r-1', email: 'ada@example.org'));

        foreach (array_merge($this->ofType($events, 'TOOL_CALL_ARGS'), $this->ofType($events, 'TOOL_CALL_RESULT')) as $event) {
            self::assertStringNotContainsString('ada@example.org', (string)($event['delta'] ?? $event['content'] ?? ''));
        }
        self::assertStringContainsString(TrafficRedactor::MASK, implode('', array_column($this->ofType($events, 'TOOL_CALL_ARGS'), 'delta')));
    }

    #[Test]
    public function withoutAModelTheExplanationIsScripted(): void
    {
        $events = $this->stack->runAgent($this->input('t-1', 'r-1'), modelAllowed: true);

        $provenance = $this->ofType($events, 'CUSTOM')[0] ?? [];
        self::assertSame(ShoppingAgent::PROVENANCE_EVENT, $provenance['name'] ?? null);
        self::assertSame(['mode' => 'scripted', 'label' => 'Scripted demo'], $provenance['value'] ?? null);
    }

    #[Test]
    public function aModelMayExplainButNeverInventANumber(): void
    {
        $configuration = ['llmFrontendEnabled' => '1', 'ucpLlmEnabled' => '1'];
        $honest = new UcpStack(configuration: $configuration, model: new ScriptedLanguageModel(true, 'You run one site, so the Pro licence fits. It costs €49.00 per month.'));
        $inventive = new UcpStack(configuration: $configuration, model: new ScriptedLanguageModel(true, 'Pro fits you. Today it costs only €39.'));

        $told = $honest->runAgent($this->input('t-1', 'r-1'), modelAllowed: true);
        $refused = $inventive->runAgent($this->input('t-1', 'r-1'), modelAllowed: true);
        $notAllowed = $honest->runAgent($this->input('t-2', 'r-1'), modelAllowed: false);

        self::assertSame('You run one site, so the Pro licence fits. It costs €49.00 per month.', $this->ofType($told, 'REASONING_MESSAGE_CONTENT')[0]['delta'] ?? null);
        self::assertSame('llm', $this->ofType($told, 'CUSTOM')[0]['value']['mode'] ?? null);
        self::assertSame([['provider' => 'Test provider', 'model' => 'test-model', 'inputTokens' => 120, 'outputTokens' => 30, 'totalTokens' => 150]], $this->last($told)['usage'] ?? null);
        self::assertSame('scripted', $this->ofType($refused, 'CUSTOM')[0]['value']['mode'] ?? null);
        self::assertSame('scripted', $this->ofType($notAllowed, 'CUSTOM')[0]['value']['mode'] ?? null);
        self::assertSame(1, $honest->model->calls);
        self::assertCount(1, $honest->ledger->records, 'Spent tokens are recorded.');
        self::assertCount(1, $inventive->ledger->records, 'even when the answer is thrown away.');
    }

    #[Test]
    public function aRationaleCutOffAtTheOutputLimitIsScriptedAndSaysWhy(): void
    {
        $configuration = ['llmFrontendEnabled' => '1', 'ucpLlmEnabled' => '1', 'llmMaxOutputTokens' => '700'];
        $stack = new UcpStack(configuration: $configuration, model: new ScriptedLanguageModel(true, '', new TruncatedAnswer(160, 120, 160, null, 'test-model')));

        $events = $stack->runAgent($this->input('t-1', 'r-1'), modelAllowed: true);

        self::assertSame([160], $stack->model->maxTokens, 'The UCP budget, not the global fallback.');
        self::assertSame(
            ['mode' => 'scripted', 'label' => 'Scripted demo', 'reason' => 'the model answer was cut off at 160 output tokens'],
            $this->ofType($events, 'CUSTOM')[0]['value'] ?? null,
        );
        self::assertCount(1, $stack->ledger->records, 'The cut-off answer still cost tokens.');
        self::assertArrayNotHasKey('usage', $this->last($events), 'No live answer, no usage to report.');
    }

    /**
     * @param list<array<string, mixed>> $resume
     * @return array<string, mixed>
     */
    private function input(string $threadId, string $runId, string $email = '', string $intent = 'pro', string $payment = '', array $resume = []): array
    {
        $props = ['intent' => $intent];
        if ($email !== '') {
            $props['email'] = $email;
        }
        if ($payment !== '') {
            $props['payment'] = $payment;
        }
        $input = [
            'threadId' => $threadId,
            'runId' => $runId,
            'protocolVersion' => '1.0',
            'messages' => [['id' => 'm-' . $runId, 'role' => 'user', 'content' => 'Buy the Pro licence for me']],
            'forwardedProps' => ['agentNexus' => $props],
        ];
        if ($resume !== []) {
            $input['resume'] = $resume;
        }
        return $input;
    }

    /**
     * Answer the open approval of a thread.
     *
     * @return list<array<string, mixed>>
     */
    private function resume(string $threadId, string $runId, bool $approved, string $email = ''): array
    {
        $approval = $this->approval($this->onlyCheckout());
        $payload = ['approved' => $approved];
        if ($email !== '') {
            $payload['email'] = $email;
        }
        return $this->stack->runAgent($this->input($threadId, $runId, resume: [['interruptId' => $approval->interruptId, 'status' => 'resolved', 'payload' => $payload]]));
    }

    private function onlyCheckout(): ProtocolObject
    {
        self::assertCount(1, $this->stack->storage->objects);
        $object = array_values($this->stack->storage->objects)[0];
        return $object;
    }

    private function approval(ProtocolObject $checkout): PendingApproval
    {
        $data = CheckoutRecord::meta($checkout)[ShoppingAgent::META_APPROVAL] ?? null;
        self::assertIsArray($data);
        $approval = PendingApproval::fromArray($data);
        self::assertNotNull($approval);
        return $approval;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function interrupt(array $events): array
    {
        $interrupt = $this->last($events)['outcome']['interrupts'][0] ?? null;
        self::assertIsArray($interrupt);
        return UcpStack::stringKeys($interrupt);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array{id: string, name: string, result: array<string, mixed>|null}>
     */
    private function toolCalls(array $events): array
    {
        $calls = [];
        foreach ($this->ofType($events, 'TOOL_CALL_START') as $start) {
            $result = null;
            foreach ($this->ofType($events, 'TOOL_CALL_RESULT') as $candidate) {
                if ($candidate['toolCallId'] === $start['toolCallId']) {
                    $decoded = json_decode($candidate['content'], true, 512, JSON_THROW_ON_ERROR);
                    $result = is_array($decoded) ? UcpStack::stringKeys($decoded) : null;
                }
            }
            $calls[] = ['id' => $start['toolCallId'], 'name' => $start['toolCallName'], 'result' => $result];
        }
        return $calls;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function argsOf(array $events, string $toolCallId): array
    {
        $delta = implode('', array_column(array_filter($this->ofType($events, 'TOOL_CALL_ARGS'), static fn(array $e): bool => $e['toolCallId'] === $toolCallId), 'delta'));
        $decoded = json_decode($delta, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return UcpStack::stringKeys($decoded);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function ofType(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn(array $event): bool => ($event['type'] ?? null) === $type));
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function last(array $events): array
    {
        self::assertNotSame([], $events);
        return $events[array_key_last($events)];
    }
}
