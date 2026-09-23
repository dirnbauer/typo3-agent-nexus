<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Ucp;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * The shopping agent over HTTP: an AG-UI stream that stops for approval, and
 * the resumed run that completes or cancels — with the checkout it stored and
 * the traffic it left.
 */
final class ShoppingAgentApiTest extends UcpTestCase
{
    #[Test]
    public function theAgentStopsForApprovalAndLeavesItsTrail(): void
    {
        $response = $this->agentRequest($this->input('thread-1', 'run-1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/event-stream', $response->getHeaderLine('Content-Type'));
        $events = self::events($response);
        self::assertSame('RUN_STARTED', $events[0]['type']);
        foreach ($events as $event) {
            self::assertSame([], SchemaValidator::errors($event, SchemaValidator::AGUI_SCHEMA_ID . '#Event'), json_encode($event, JSON_THROW_ON_ERROR));
        }
        $finished = $events[array_key_last($events)];
        self::assertSame('interrupt', $finished['outcome']['type'] ?? null);

        $checkout = $this->onlyCheckout();
        self::assertSame(Spec::STATUS_INCOMPLETE, $checkout->state);
        self::assertSame('thread-1', $checkout->contextId);
        self::assertSame('agent', $checkout->source);

        $rows = $this->trafficRows();
        $inProcess = array_values(array_filter($rows, static fn(array $row): bool => $row['channel'] === 'agent'));
        self::assertSame(['discovery', 'create_checkout'], array_column($inProcess, 'operation'));
        self::assertSame($checkout->objectId, $inProcess[1]['correlation_id']);
        $stream = array_values(array_filter($rows, static fn(array $row): bool => $row['operation'] === 'RunAgent'));
        self::assertCount(1, $stream);
        self::assertSame(1, (int)$stream[0]['is_stream']);
        self::assertSame($checkout->objectId, $stream[0]['correlation_id']);
        self::assertSame('api', $stream[0]['channel'], 'Without widget context the caller is an API client.');
    }

    #[Test]
    public function anApprovalWithAnEmailAddressCompletesTheCheckout(): void
    {
        $proposal = $this->agentEvents($this->input('thread-1', 'run-1'));
        $interruptId = $proposal[array_key_last($proposal)]['outcome']['interrupts'][0]['id'] ?? '';
        self::assertIsString($interruptId);

        $events = $this->agentEvents($this->input('thread-1', 'run-2', [
            ['interruptId' => $interruptId, 'status' => 'resolved', 'payload' => ['approved' => true, 'email' => 'ada@example.org']],
        ]));

        self::assertSame(['type' => 'success'], $events[array_key_last($events)]['outcome'] ?? null);
        $checkout = $this->onlyCheckout();
        self::assertSame(Spec::STATUS_COMPLETED, $checkout->state);
        self::assertIsArray($checkout->payload['order'] ?? null);
        $operations = array_column(array_filter($this->trafficRows(), static fn(array $row): bool => $row['channel'] === 'agent'), 'operation');
        self::assertSame(['discovery', 'create_checkout', 'update_checkout', 'complete_checkout'], array_values($operations));
        self::assertContains('ResumeRun', array_column($this->trafficRows(), 'operation'));
    }

    #[Test]
    public function decliningCancelsTheCheckout(): void
    {
        $proposal = $this->agentEvents($this->input('thread-1', 'run-1'));
        $interruptId = $proposal[array_key_last($proposal)]['outcome']['interrupts'][0]['id'] ?? '';
        self::assertIsString($interruptId);

        $this->agentEvents($this->input('thread-1', 'run-2', [['interruptId' => $interruptId, 'status' => 'cancelled']]));

        self::assertSame(Spec::STATUS_CANCELED, $this->onlyCheckout()->state);
    }

    #[Test]
    public function aForgedAnswerIsRefusedBeforeTheRunStarts(): void
    {
        $this->agentEvents($this->input('thread-1', 'run-1'));

        $events = $this->agentEvents($this->input('thread-1', 'run-2', [
            ['interruptId' => 'int_forged', 'status' => 'resolved', 'payload' => ['approved' => true, 'email' => 'ada@example.org']],
        ]));

        self::assertCount(1, $events);
        self::assertSame('RUN_ERROR', $events[0]['type']);
        self::assertSame(Spec::STATUS_INCOMPLETE, $this->onlyCheckout()->state);
    }

    #[Test]
    public function aBodyThatIsNotARunAgentInputIsRejected(): void
    {
        $response = $this->request('POST', self::API . '/agent', ['Content-Type' => 'application/json'], '{"intent":"pro"}');

        self::assertSame(400, $response->getStatusCode());
        $events = self::events($response);
        self::assertSame('RUN_ERROR', $events[0]['type'] ?? null);
        self::assertSame('invalid_input', $events[0]['code'] ?? null);
    }

    #[Test]
    public function aWidgetRunIsFiledOnItsPage(): void
    {
        $input = $this->input('thread-1', 'run-1');
        $input['forwardedProps'] = ['agentNexus' => ['ce' => 7, 'page' => 1, 'url' => 'https://agent-nexus.test/', 'intent' => 'support']];

        $this->agentEvents($input);

        $checkout = $this->onlyCheckout();
        self::assertSame(1, $checkout->pid);
        self::assertSame('€99.00 · 1 item', $checkout->label);
        $stream = array_values(array_filter($this->trafficRows(), static fn(array $row): bool => $row['operation'] === 'RunAgent'));
        self::assertSame('widget', $stream[0]['channel']);
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
            'forwardedProps' => ['agentNexus' => ['intent' => 'pro']],
        ];
        if ($resume !== []) {
            $input['resume'] = $resume;
        }
        return $input;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function agentRequest(array $input): ResponseInterface
    {
        return $this->request('POST', self::API . '/agent', ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'], json_encode($input, JSON_THROW_ON_ERROR));
    }

    /**
     * Run the agent and read its whole stream. The agent works while its
     * stream is read, exactly as it does when a browser reads it.
     *
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    private function agentEvents(array $input): array
    {
        return self::events($this->agentRequest($input));
    }

    private function onlyCheckout(): ProtocolObject
    {
        $checkouts = $this->get(ObjectStore::class)->list(new ObjectFilter(ObjectKind::Checkout));
        self::assertCount(1, $checkouts);
        return $checkouts[0];
    }
}
