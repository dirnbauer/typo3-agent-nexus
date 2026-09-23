<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Traffic;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Localization\LanguageService;
use Webconsulting\AgentNexus\Shared\Protocol;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;

/**
 * Turns traffic rows into what the traffic log shows — once, for the server-
 * rendered list and for the live poll alike, so both look the same.
 */
final readonly class TrafficPresenter
{
    public const string LANGUAGE_DOMAIN = 'agent_nexus.traffic';

    public function __construct(
        private UriBuilder $uriBuilder,
        private ObjectStore $objectStore,
    ) {}

    /**
     * @param array<string, mixed> $row a tx_agentnexus_traffic row
     * @param array<string, string> $returnArguments list filter to come back to
     * @return array{uid: int, time: string, timeIso: string, protocol: string, protocolLabel: string, channel: string, channelLabel: string, method: string, endpoint: string, operation: string, correlationId: string, status: int, statusBadge: string, isError: bool, isStream: bool, duration: string, eventCount: int, detailUri: string}
     */
    public function row(array $row, array $returnArguments = []): array
    {
        $uid = (int)($row['uid'] ?? 0);
        $created = (int)($row['crdate'] ?? 0);
        $protocol = Protocol::tryFrom((string)($row['protocol'] ?? ''));
        $channel = Channel::tryFrom((string)($row['channel'] ?? ''));
        $status = (int)($row['status_code'] ?? 0);
        $isError = (bool)($row['is_error'] ?? false);

        return [
            'uid' => $uid,
            'time' => $created > 0 ? date('H:i:s', $created) : '',
            'timeIso' => $created > 0 ? date(DATE_ATOM, $created) : '',
            'protocol' => $protocol->value ?? '',
            'protocolLabel' => $protocol?->label() ?? (string)($row['protocol'] ?? ''),
            'channel' => $channel->value ?? '',
            'channelLabel' => $channel !== null ? $this->label('channel.' . $channel->value) : '',
            'method' => (string)($row['method'] ?? ''),
            'endpoint' => (string)($row['endpoint'] ?? ''),
            'operation' => (string)($row['operation'] ?? ''),
            'correlationId' => (string)($row['correlation_id'] ?? ''),
            'status' => $status,
            'statusBadge' => match (true) {
                $isError || $status >= 500 => 'danger',
                $status >= 400 => 'warning',
                $status >= 200 && $status < 300 => 'success',
                default => 'default',
            },
            'isError' => $isError,
            'isStream' => (bool)($row['is_stream'] ?? false),
            'duration' => $this->duration((int)($row['duration_ms'] ?? 0)),
            'eventCount' => (int)($row['event_count'] ?? 0),
            'detailUri' => (string)$this->uriBuilder->buildUriFromRoute(
                'agentnexus_traffic.detail',
                ['uid' => $uid] + ($returnArguments === [] ? [] : ['return' => $returnArguments]),
            ),
        ];
    }

    /**
     * Everything the detail view shows about one exchange.
     *
     * @param array<string, mixed> $row the full tx_agentnexus_traffic row
     * @return array<string, mixed>
     */
    public function detail(array $row): array
    {
        $summary = $this->row($row);
        $events = [];
        foreach ($this->decodeList((string)($row['events'] ?? '')) as $index => $frame) {
            if (!is_array($frame)) {
                continue;
            }
            $data = is_array($frame['data'] ?? null) ? $frame['data'] : [];
            $events[] = [
                'index' => $index + 1,
                'offset' => (int)($frame['t'] ?? 0),
                'name' => $this->eventName($data),
                'json' => $this->pretty($data),
            ];
        }

        $object = null;
        $protocol = Protocol::tryFrom($summary['protocol']);
        if ($protocol !== null && $summary['correlationId'] !== '') {
            $found = $this->objectStore->find(ObjectKind::forProtocol($protocol), $summary['correlationId']);
            if ($found !== null) {
                $object = [
                    'uid' => $found->uid,
                    'label' => $found->label !== '' ? $found->label : $found->objectId,
                    'state' => $found->state,
                    'uri' => (string)$this->uriBuilder->buildUriFromRoute(
                        $found->kind->inspectorModule() . '.detail',
                        ['uid' => $found->uid],
                    ),
                ];
            }
        }

        return $summary + [
            'created' => (int)($row['crdate'] ?? 0),
            'error' => (string)($row['error'] ?? ''),
            'requestHeaders' => $this->decodeMap((string)($row['request_headers'] ?? '')),
            'requestBody' => $this->prettyBody((string)($row['request_body'] ?? '')),
            'responseHeaders' => $this->decodeMap((string)($row['response_headers'] ?? '')),
            'responseBody' => $this->prettyBody((string)($row['response_body'] ?? '')),
            'events' => $events,
            'object' => $object,
        ];
    }

    /**
     * A one-word name for a streamed frame: the AG-UI event type, the A2A
     * stream-response member, the A2UI message key — whatever says what it is.
     *
     * @param array<array-key, mixed> $data
     */
    public function eventName(array $data): string
    {
        if (is_string($data['type'] ?? null)) {
            return $data['type'];
        }
        if (is_array($data['error'] ?? null)) {
            return 'error';
        }
        $result = is_array($data['result'] ?? null) ? $data['result'] : $data;
        foreach (['task', 'message', 'statusUpdate', 'artifactUpdate'] as $member) {
            if (isset($result[$member]) && is_array($result[$member])) {
                if ($member === 'statusUpdate' && is_string($result[$member]['status']['state'] ?? null)) {
                    return $member . ' · ' . $result[$member]['status']['state'];
                }
                return $member;
            }
        }
        if (is_string($result['kind'] ?? null)) {
            return $result['kind'];
        }
        foreach (['createSurface', 'updateComponents', 'updateDataModel', 'deleteSurface'] as $member) {
            if (isset($data[$member])) {
                return $member;
            }
        }
        $keys = array_keys($data);
        return is_string($keys[0] ?? null) ? $keys[0] : 'frame';
    }

    private function duration(int $milliseconds): string
    {
        return $milliseconds >= 1000
            ? number_format($milliseconds / 1000, 1) . ' s'
            : $milliseconds . ' ms';
    }

    private function prettyBody(string $body): string
    {
        if ($body === '') {
            return '';
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $this->pretty($decoded) : $body;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function pretty(array $data): string
    {
        return (string)json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, string>
     */
    private function decodeMap(string $json): array
    {
        $decoded = json_decode($json, true);
        $map = [];
        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                if (is_scalar($value)) {
                    $map[(string)$key] = (string)$value;
                }
            }
        }
        return $map;
    }

    /**
     * @return list<mixed>
     */
    private function decodeList(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function label(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        return $languageService instanceof LanguageService
            ? $languageService->sL(self::LANGUAGE_DOMAIN . ':' . $key)
            : $key;
    }
}
