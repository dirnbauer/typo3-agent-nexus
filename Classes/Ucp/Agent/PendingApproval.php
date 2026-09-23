<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

/**
 * The purchase the shopping agent proposed and stopped for.
 *
 * It holds the complete request exactly as the visitor was shown it —
 * including its Idempotency-Key — so the request an approval releases is the
 * request that was approved, byte for byte, and replaying the same answer
 * replays the same request. It is kept on the checkout's private member, next
 * to the session it is about, and is answered once: a second, different
 * answer is refused.
 */
final readonly class PendingApproval
{
    public const string OPEN = '';
    public const string APPROVED = 'approved';
    public const string DECLINED = 'declined';

    /**
     * @param array{method: string, path: string, headers: array<string, string>, body: array<string, mixed>} $request the proposed complete_checkout call
     */
    public function __construct(
        public string $interruptId,
        public string $toolCallId,
        public string $threadId,
        public string $runId,
        public string $checkoutId,
        public array $request,
        public int $amount,
        public string $currency,
        public string $expiresAt,
        public string $cancelKey,
        public string $resolution = self::OPEN,
        public int $resolvedAt = 0,
    ) {}

    public function isOpen(): bool
    {
        return $this->resolution === self::OPEN;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        try {
            return $this->expiresAt !== '' && new \DateTimeImmutable($this->expiresAt) < $now;
        } catch (\Exception) {
            return false;
        }
    }

    public function resolved(string $resolution, int $at): self
    {
        return new self(
            $this->interruptId,
            $this->toolCallId,
            $this->threadId,
            $this->runId,
            $this->checkoutId,
            $this->request,
            $this->amount,
            $this->currency,
            $this->expiresAt,
            $this->cancelKey,
            $resolution,
            $at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'interruptId' => $this->interruptId,
            'toolCallId' => $this->toolCallId,
            'threadId' => $this->threadId,
            'runId' => $this->runId,
            'checkoutId' => $this->checkoutId,
            'request' => $this->request,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'expiresAt' => $this->expiresAt,
            'cancelKey' => $this->cancelKey,
            'resolution' => $this->resolution,
            'resolvedAt' => $this->resolvedAt,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $strings = [];
        foreach (['interruptId', 'toolCallId', 'threadId', 'runId', 'checkoutId', 'currency', 'expiresAt', 'cancelKey', 'resolution'] as $field) {
            $value = $data[$field] ?? null;
            if (!is_string($value)) {
                return null;
            }
            $strings[$field] = $value;
        }
        $amount = $data['amount'] ?? null;
        $request = $data['request'] ?? null;
        if (!is_int($amount) || !is_array($request)) {
            return null;
        }
        $method = $request['method'] ?? null;
        $path = $request['path'] ?? null;
        if (!is_string($method) || !is_string($path) || !is_array($request['headers'] ?? null) || !is_array($request['body'] ?? null)) {
            return null;
        }
        $headers = [];
        foreach ($request['headers'] as $name => $value) {
            if (is_string($value)) {
                $headers[(string)$name] = $value;
            }
        }
        $body = [];
        foreach ($request['body'] as $key => $value) {
            $body[(string)$key] = $value;
        }
        $resolvedAt = $data['resolvedAt'] ?? null;

        return new self(
            $strings['interruptId'],
            $strings['toolCallId'],
            $strings['threadId'],
            $strings['runId'],
            $strings['checkoutId'],
            ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body],
            $amount,
            $strings['currency'],
            $strings['expiresAt'],
            $strings['cancelKey'],
            $strings['resolution'],
            is_int($resolvedAt) ? $resolvedAt : 0,
        );
    }
}
