<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

/**
 * What a request with an Idempotency-Key did: the operation, the SHA-256 of
 * its raw body, and the response it received — which a retry with the same
 * key and the same body receives again, byte for byte.
 */
final readonly class IdempotencyRecord
{
    public function __construct(
        public string $operation,
        public string $path,
        public string $bodyHash,
        public int $status,
        public string $body,
        public string $location = '',
        public int $createdAt = 0,
    ) {}

    /** The same operation on the same resource with the same body. */
    public function matches(string $operation, string $path, string $bodyHash): bool
    {
        return $this->operation === $operation
            && $this->path === $path
            && hash_equals($this->bodyHash, $bodyHash);
    }

    /**
     * @return array{operation: string, path: string, bodyHash: string, status: int, body: string, location: string, createdAt: int}
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'path' => $this->path,
            'bodyHash' => $this->bodyHash,
            'status' => $this->status,
            'body' => $this->body,
            'location' => $this->location,
            'createdAt' => $this->createdAt,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        if (!is_string($data['operation'] ?? null) || !is_string($data['path'] ?? null)
            || !is_string($data['bodyHash'] ?? null) || !is_int($data['status'] ?? null) || !is_string($data['body'] ?? null)) {
            return null;
        }
        return new self(
            $data['operation'],
            $data['path'],
            $data['bodyHash'],
            $data['status'],
            $data['body'],
            is_string($data['location'] ?? null) ? $data['location'] : '',
            is_int($data['createdAt'] ?? null) ? $data['createdAt'] : 0,
        );
    }
}
