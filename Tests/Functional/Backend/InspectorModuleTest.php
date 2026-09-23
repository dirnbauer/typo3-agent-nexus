<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Agentstack\Controller\InspectorController;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRepository;

/**
 * Every inspector screen lists its own kind and reads its payload shape.
 */
final class InspectorModuleTest extends AbstractBackendModuleTestCase
{
    /**
     * @return array<string, array{0: string, 1: ObjectKind, 2: array<string, mixed>, 3: string}>
     */
    public static function objectProvider(): array
    {
        return [
            'an A2A task' => ['agentnexus_inspector_tasks', ObjectKind::Task, [
                'id' => 'task-1',
                'contextId' => 'ctx-1',
                'status' => ['state' => 'TASK_STATE_COMPLETED', 'timestamp' => '2026-09-23T10:00:02.000Z'],
                'history' => [['messageId' => 'm-1', 'role' => 'ROLE_USER', 'parts' => [['text' => 'Summarise our pricing page']]]],
                'artifacts' => [['artifactId' => 'a-1', 'name' => 'summary.md', 'parts' => [['text' => 'There are three plans.']]]],
            ], 'There are three plans.'],
            'an AG-UI run' => ['agentnexus_inspector_runs', ObjectKind::Run, [
                'input' => ['threadId' => 't-1', 'runId' => 'run-1', 'protocolVersion' => '1.0', 'messages' => [['id' => 'u-1', 'role' => 'user', 'content' => 'Which plan fits a team of five?']]],
                'outcome' => ['type' => 'interrupt', 'interrupts' => []],
                'interrupts' => [['id' => 'int-1', 'reason' => 'confirmation', 'message' => 'Approve the booking', 'toolCallId' => 'tc-1']],
            ], 'Approve the booking'],
            'a UCP checkout' => ['agentnexus_inspector_checkouts', ObjectKind::Checkout, [
                'ucp' => ['version' => '2026-08-25', 'payment_handlers' => []],
                'id' => 'chk_1',
                'status' => 'ready_for_complete',
                'currency' => 'EUR',
                'line_items' => [['id' => 'li_1', 'item' => ['id' => 'pro-license', 'title' => 'Desiderio Pro Licence', 'price' => 4900], 'quantity' => 1, 'totals' => [['type' => 'total', 'amount' => 4900]]]],
                'totals' => [['type' => 'subtotal', 'amount' => 4900], ['type' => 'total', 'amount' => 4900]],
                'links' => [],
            ], '€49.00'],
            'an AP2 mandate' => ['agentnexus_inspector_mandates', ObjectKind::Mandate, [
                'vct' => 'mandate.payment.open.1',
                'role' => 'Trusted Surface',
                'token' => 'eyJhbGciOiJFUzI1NiJ9.e30.sig~',
                'claims' => ['vct' => 'mandate.payment.open.1'],
                'verification' => ['valid' => true, 'checks' => [['label' => 'Same approved merchant', 'pass' => true, 'detail' => 'demo-merchant']]],
            ], 'Same approved merchant'],
            'an A2UI surface' => ['agentnexus_inspector_surfaces', ObjectKind::Surface, [
                'version' => 'v0.9.1',
                'messages' => [['version' => 'v0.9.1', 'createSurface' => ['surfaceId' => 's-1', 'catalogId' => 'https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json']]],
                'dataModel' => ['name' => 'Ada'],
                'actions' => [],
            ], 'createSurface'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataProvider('objectProvider')]
    public function theListShowsTheObject(string $module, ObjectKind $kind, array $payload, string $expected): void
    {
        $this->store($kind, $payload);

        $list = self::body($this->get(InspectorController::class)->listAction($this->moduleRequest($module)));

        self::assertStringContainsString('object-1', $list);
        self::assertStringContainsString('A summary line', $list);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataProvider('objectProvider')]
    public function theDetailReadsThePayloadHistoryAndTraffic(string $module, ObjectKind $kind, array $payload, string $expected): void
    {
        $stored = $this->store($kind, $payload);

        $detail = self::body($this->get(InspectorController::class)->detailAction(
            $this->moduleRequest($module . '.detail', ['uid' => (string)$stored->uid]),
        ));

        self::assertStringContainsString($expected, $detail);
        self::assertStringContainsString('finished in the test', $detail, 'The state history is shown.');
        self::assertStringContainsString('TestOperation', $detail, 'The traffic that touched the object is linked.');
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function longListProvider(): array
    {
        return [
            'the first page' => [[], 'Objects 1 to 30 of 32'],
            'the last page' => [['page' => '2'], 'Objects 31 to 32 of 32'],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('longListProvider')]
    public function aLongListIsPagedWithItsTotal(array $query, string $range): void
    {
        $store = $this->get(ObjectStore::class);
        for ($i = 1; $i <= 32; $i++) {
            $store->save(new ProtocolObject(ObjectKind::Task, 'task-' . $i, state: 'TASK_STATE_COMPLETED'));
        }

        $html = self::body($this->get(InspectorController::class)->listAction($this->moduleRequest('agentnexus_inspector_tasks', $query)));

        self::assertStringContainsString($range, $html);
    }

    #[Test]
    public function anObjectOfAnotherKindIsNotShownOnTheWrongScreen(): void
    {
        $task = $this->get(ObjectStore::class)->save(new ProtocolObject(ObjectKind::Task, 'task-x', state: 'TASK_STATE_WORKING'));

        $html = self::body($this->get(InspectorController::class)->detailAction(
            $this->moduleRequest('agentnexus_inspector_checkouts.detail', ['uid' => (string)$task->uid]),
        ));

        self::assertStringContainsString('no longer exists', $html);
    }

    #[Test]
    public function anEmptyScreenSaysWhereObjectsComeFrom(): void
    {
        $html = self::body($this->get(InspectorController::class)->listAction($this->moduleRequest('agentnexus_inspector_mandates')));

        self::assertStringContainsString('Nothing here yet', $html);
        self::assertStringContainsString('AP2 studio', $html);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function store(ObjectKind $kind, array $payload): ProtocolObject
    {
        $stored = $this->get(ObjectStore::class)->save(
            (new ProtocolObject($kind, 'object-1', 'ctx-1', 'working', 'api', 'A summary line', $payload))
                ->withState('done', 'finished in the test'),
        );
        $this->get(TrafficRepository::class)->add([
            'protocol' => $kind->protocol()->value, 'channel' => 'api', 'method' => 'POST', 'endpoint' => 'POST /x',
            'operation' => 'TestOperation', 'correlation_id' => 'object-1', 'status_code' => 200, 'is_error' => 0,
            'is_stream' => 0, 'duration_ms' => 1, 'event_count' => 0, 'request_headers' => '{}', 'request_body' => '',
            'response_headers' => '{}', 'response_body' => '', 'events' => '', 'error' => '', 'be_user' => 0,
        ]);
        return $stored;
    }
}
