<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Inspector;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficPresenter;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRepository;

/**
 * Turns a stored protocol object into what the inspector shows.
 *
 * The payload is the object in its specification's own JSON shape — an A2A
 * Task, a UCP checkout, an AP2 mandate — so each kind gets a reading of the
 * parts that matter (messages and artifacts, line items and totals, claims and
 * checks), and every kind gets the same frame: the state history, the raw JSON
 * and the traffic that touched the object. Payloads are read defensively; a
 * field a protocol did not send is simply not shown.
 */
final readonly class InspectorPresenter
{
    public function __construct(
        private UriBuilder $uriBuilder,
        private ObjectStore $objectStore,
        private TrafficRepository $trafficRepository,
        private TrafficPresenter $trafficPresenter,
    ) {}

    /** Core badge variant for a state. */
    public static function stateBadge(ObjectKind $kind, string $state): string
    {
        $state = strtolower($state);
        return match (true) {
            str_contains($state, 'completed'), $state === 'finished', $state === 'verified', $state === 'submitted' => 'success',
            str_contains($state, 'failed'), str_contains($state, 'rejected'), $state === 'error' => 'danger',
            str_contains($state, 'input_required'), str_contains($state, 'auth_required'), $state === 'interrupted', $state === 'requires_escalation' => 'warning',
            str_contains($state, 'canceled'), $state === 'cancelled', $state === 'deleted' => 'default',
            default => 'info',
        };
    }

    /**
     * @return array{uid: int, objectId: string, contextId: string, label: string, state: string, stateBadge: string, source: string, created: int, updated: int, detailUri: string}
     */
    public function row(ProtocolObject $object): array
    {
        return [
            'uid' => $object->uid,
            'objectId' => $object->objectId,
            'contextId' => $object->contextId,
            'label' => $object->label,
            'state' => $object->state,
            'stateBadge' => self::stateBadge($object->kind, $object->state),
            'source' => $object->source,
            'created' => $object->crdate,
            'updated' => $object->tstamp,
            'detailUri' => $this->detailUri($object),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(ProtocolObject $object): array
    {
        $traffic = array_map(
            fn(array $row): array => $this->trafficPresenter->row($row),
            $this->trafficRepository->findByCorrelation($object->objectId),
        );

        $related = [];
        if ($object->contextId !== '') {
            foreach ($this->objectStore->list(new ObjectFilter($object->kind, contextId: $object->contextId), 20) as $sibling) {
                if ($sibling->uid !== $object->uid) {
                    $related[] = $this->row($sibling);
                }
            }
        }

        return $this->row($object) + [
            'kind' => $object->kind->value,
            'protocol' => $object->kind->protocol()->value,
            'protocolLabel' => $object->kind->protocol()->label(),
            'history' => $object->history,
            'json' => (string)json_encode($object->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'traffic' => $traffic,
            'related' => $related,
            'view' => match ($object->kind) {
                ObjectKind::Task => $this->task($object->payload),
                ObjectKind::Run => $this->run($object->payload),
                ObjectKind::Checkout => $this->checkout($object->payload),
                ObjectKind::Mandate => $this->mandate($object->payload),
                ObjectKind::Surface => $this->surface($object->payload),
            },
        ];
    }

    public function detailUri(ProtocolObject $object): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(
            $object->kind->inspectorModule() . '.detail',
            ['uid' => $object->uid],
        );
    }

    /**
     * An A2A Task: its conversation and what it produced.
     *
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function task(array $task): array
    {
        $messages = [];
        foreach ($this->listOf($task['history'] ?? null) as $message) {
            $messages[] = [
                'role' => $this->roleLabel($this->string($message['role'] ?? '')),
                'text' => $this->partsText($message['parts'] ?? null),
                'data' => $this->partsData($message['parts'] ?? null),
            ];
        }
        $status = $this->map($task['status'] ?? null);
        $statusMessage = $this->map($status['message'] ?? null);

        $artifacts = [];
        foreach ($this->listOf($task['artifacts'] ?? null) as $artifact) {
            $artifacts[] = [
                'name' => $this->string($artifact['name'] ?? ($artifact['artifactId'] ?? '')),
                'description' => $this->string($artifact['description'] ?? ''),
                'text' => $this->partsText($artifact['parts'] ?? null),
                'data' => $this->partsData($artifact['parts'] ?? null),
            ];
        }

        return [
            'status' => $this->string($status['state'] ?? ''),
            'statusTimestamp' => $this->string($status['timestamp'] ?? ''),
            'statusText' => $this->partsText($statusMessage['parts'] ?? null),
            'messages' => $messages,
            'artifacts' => $artifacts,
            'metadata' => $this->prettyOrEmpty($task['metadata'] ?? null),
        ];
    }

    /**
     * An AG-UI run: what it was asked, how it ended and what it waited for.
     *
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    private function run(array $run): array
    {
        $input = $this->map($run['input'] ?? null);
        $messages = [];
        foreach ($this->listOf($input['messages'] ?? null) as $message) {
            $content = $message['content'] ?? '';
            $messages[] = [
                'role' => $this->string($message['role'] ?? ''),
                'text' => is_string($content) ? $content : $this->partsText($content),
            ];
        }
        $interrupts = [];
        foreach ($this->listOf($run['interrupts'] ?? null) as $interrupt) {
            $interrupts[] = [
                'id' => $this->string($interrupt['id'] ?? ''),
                'reason' => $this->string($interrupt['reason'] ?? ''),
                'message' => $this->string($interrupt['message'] ?? ''),
                'toolCallId' => $this->string($interrupt['toolCallId'] ?? ''),
            ];
        }
        $outcome = $this->map($run['outcome'] ?? null);

        return [
            'threadId' => $this->string($input['threadId'] ?? ''),
            'protocolVersion' => $this->string($input['protocolVersion'] ?? ''),
            'messages' => $messages,
            'outcome' => $this->string($outcome['type'] ?? ''),
            'interrupts' => $interrupts,
            'resume' => $this->prettyOrEmpty($input['resume'] ?? null),
            'result' => $this->prettyOrEmpty($run['result'] ?? null),
            'state' => $this->prettyOrEmpty($run['state'] ?? null),
            'eventCount' => is_int($run['eventCount'] ?? null) ? $run['eventCount'] : null,
        ];
    }

    /**
     * A UCP checkout session: the priced cart and what the merchant said.
     *
     * @param array<string, mixed> $checkout
     * @return array<string, mixed>
     */
    private function checkout(array $checkout): array
    {
        $currency = $this->string($checkout['currency'] ?? '');
        $lines = [];
        foreach ($this->listOf($checkout['line_items'] ?? null) as $line) {
            $item = $this->map($line['item'] ?? null);
            $lines[] = [
                'id' => $this->string($line['id'] ?? ''),
                'title' => $this->string($item['title'] ?? ($item['id'] ?? '')),
                'quantity' => is_int($line['quantity'] ?? null) ? $line['quantity'] : 0,
                'price' => $this->money($item['price'] ?? null, $currency),
                'total' => $this->money($this->totalOf($line['totals'] ?? null, 'total'), $currency),
            ];
        }
        $totals = [];
        foreach ($this->listOf($checkout['totals'] ?? null) as $total) {
            $totals[] = [
                'isTotal' => ($total['type'] ?? null) === 'total',
                'type' => $this->string($total['type'] ?? ''),
                'label' => $this->string($total['display_text'] ?? ($total['type'] ?? '')),
                'amount' => $this->money($total['amount'] ?? null, $currency),
            ];
        }
        $messages = [];
        foreach ($this->listOf($checkout['messages'] ?? null) as $message) {
            $type = $this->string($message['type'] ?? '');
            $messages[] = [
                'type' => $type,
                'badge' => match ($type) {
                    'error' => 'danger',
                    'warning' => 'warning',
                    default => 'info',
                },
                'code' => $this->string($message['code'] ?? ''),
                'severity' => $this->string($message['severity'] ?? ''),
                'content' => $this->string($message['content'] ?? ''),
            ];
        }
        $links = [];
        foreach ($this->listOf($checkout['links'] ?? null) as $link) {
            $links[] = ['type' => $this->string($link['type'] ?? ''), 'url' => $this->string($link['url'] ?? ''), 'title' => $this->string($link['title'] ?? '')];
        }
        $order = $this->map($checkout['order'] ?? null);
        $ucp = $this->map($checkout['ucp'] ?? null);

        return [
            'status' => $this->string($checkout['status'] ?? ''),
            'currency' => $currency,
            'version' => $this->string($ucp['version'] ?? ''),
            'lines' => $lines,
            'totals' => $totals,
            'messages' => $messages,
            'links' => $links,
            'order' => $order === [] ? null : ['id' => $this->string($order['id'] ?? ''), 'permalink' => $this->string($order['permalink_url'] ?? ''), 'label' => $this->string($order['label'] ?? '')],
            'continueUrl' => $this->string($checkout['continue_url'] ?? ''),
            'expiresAt' => $this->string($checkout['expires_at'] ?? ''),
        ];
    }

    /**
     * An AP2 mandate or receipt: which type, who signed it, what it says and
     * whether it verified.
     *
     * @param array<string, mixed> $mandate
     * @return array<string, mixed>
     */
    private function mandate(array $mandate): array
    {
        $claims = $this->map($mandate['claims'] ?? null);
        $verification = $this->map($mandate['verification'] ?? null);
        $checks = [];
        foreach ($this->listOf($verification['checks'] ?? null) as $check) {
            $checks[] = [
                'label' => $this->string($check['label'] ?? ''),
                'pass' => ($check['pass'] ?? false) === true,
                'detail' => $this->string($check['detail'] ?? ''),
            ];
        }
        $disclosures = [];
        foreach ($this->listOf($mandate['disclosures'] ?? null) as $disclosure) {
            $disclosures[] = (string)json_encode($disclosure, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $vct = $this->string($mandate['vct'] ?? ($claims['vct'] ?? ($mandate['type'] ?? '')));

        return [
            'vct' => $vct,
            'role' => $this->string($mandate['role'] ?? ''),
            'token' => $this->string($mandate['token'] ?? ''),
            'header' => $this->prettyOrEmpty($mandate['header'] ?? null),
            'claims' => $this->prettyOrEmpty($claims === [] ? null : $claims),
            'disclosures' => $disclosures,
            'valid' => array_key_exists('valid', $verification) ? ($verification['valid'] === true) : null,
            'checks' => $checks,
        ];
    }

    /**
     * An A2UI surface: the messages that built it and the data it returned.
     *
     * @param array<string, mixed> $surface
     * @return array<string, mixed>
     */
    private function surface(array $surface): array
    {
        $messages = [];
        foreach ($this->listOf($surface['messages'] ?? null) as $index => $message) {
            $keys = array_values(array_filter(array_keys($message), static fn(int|string $key): bool => $key !== 'version'));
            $messages[] = [
                'index' => $index + 1,
                'name' => is_string($keys[0] ?? null) ? $keys[0] : 'message',
                'json' => (string)json_encode($message, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }
        $actions = [];
        foreach ($this->listOf($surface['actions'] ?? null) as $action) {
            $body = $this->map($action['action'] ?? $action);
            $actions[] = [
                'name' => $this->string($body['name'] ?? ''),
                'component' => $this->string($body['sourceComponentId'] ?? ''),
                'timestamp' => $this->string($body['timestamp'] ?? ''),
                'json' => (string)json_encode($action, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        return [
            'version' => $this->string($surface['version'] ?? ''),
            'messages' => $messages,
            'dataModel' => $this->prettyOrEmpty($surface['dataModel'] ?? null),
            'actions' => $actions,
        ];
    }

    private function roleLabel(string $role): string
    {
        return match ($role) {
            'ROLE_USER', 'user' => 'user',
            'ROLE_AGENT', 'agent' => 'agent',
            default => strtolower($role),
        };
    }

    private function partsText(mixed $parts): string
    {
        $text = [];
        foreach ($this->listOf($parts) as $part) {
            if (is_string($part['text'] ?? null)) {
                $text[] = $part['text'];
            }
        }
        return implode("\n", $text);
    }

    private function partsData(mixed $parts): string
    {
        $data = [];
        foreach ($this->listOf($parts) as $part) {
            if (array_key_exists('data', $part)) {
                $data[] = $part['data'];
            }
        }
        return $data === [] ? '' : (string)json_encode(count($data) === 1 ? $data[0] : $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function totalOf(mixed $totals, string $type): mixed
    {
        foreach ($this->listOf($totals) as $total) {
            if (($total['type'] ?? null) === $type) {
                return $total['amount'] ?? null;
            }
        }
        return null;
    }

    private function money(mixed $minorUnits, string $currency): string
    {
        if (!is_int($minorUnits)) {
            return '';
        }
        $formatted = number_format($minorUnits / 100, 2, '.', ',');
        return $currency === 'EUR' ? '€' . $formatted : trim($formatted . ' ' . $currency);
    }

    private function prettyOrEmpty(mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') {
            return '';
        }
        return (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function map(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string)$key] = $item;
        }
        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $list = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $list[] = $this->map($item);
            }
        }
        return $list;
    }
}
