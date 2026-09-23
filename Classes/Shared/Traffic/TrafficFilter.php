<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Traffic;

use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * What the traffic log is narrowed to. Built from query parameters, so every
 * value is validated here and anything unknown simply does not filter.
 */
final readonly class TrafficFilter
{
    public const string OUTCOME_OK = 'ok';
    public const string OUTCOME_ERROR = 'error';

    /** Selectable time windows in seconds, keyed by their query value. */
    public const array PERIODS = ['1h' => 3600, '24h' => 86400, '7d' => 604800];

    public function __construct(
        public ?Protocol $protocol = null,
        public ?Channel $channel = null,
        public string $outcome = '',
        public string $period = '',
        public string $search = '',
        public int $since = 0,
    ) {}

    /**
     * @param array<array-key, mixed> $parameters
     */
    public static function fromArray(array $parameters, ?int $now = null): self
    {
        $now ??= time();
        $protocol = is_string($parameters['protocol'] ?? null) ? Protocol::tryFrom($parameters['protocol']) : null;
        $channel = is_string($parameters['channel'] ?? null) ? Channel::tryFrom($parameters['channel']) : null;
        $outcome = is_string($parameters['outcome'] ?? null) && in_array($parameters['outcome'], [self::OUTCOME_OK, self::OUTCOME_ERROR], true)
            ? $parameters['outcome']
            : '';
        $period = is_string($parameters['period'] ?? null) && isset(self::PERIODS[$parameters['period']])
            ? $parameters['period']
            : '';
        $search = is_string($parameters['search'] ?? null) ? mb_substr(trim($parameters['search']), 0, 100) : '';

        return new self(
            $protocol,
            $channel,
            $outcome,
            $period,
            $search,
            $period !== '' ? $now - self::PERIODS[$period] : 0,
        );
    }

    /**
     * The filter as query parameters, for pagination and the live poll.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'protocol' => $this->protocol->value ?? '',
            'channel' => $this->channel->value ?? '',
            'outcome' => $this->outcome,
            'period' => $this->period,
            'search' => $this->search,
        ], static fn(string $value): bool => $value !== '');
    }

    public function isActive(): bool
    {
        return $this->toArray() !== [];
    }
}
