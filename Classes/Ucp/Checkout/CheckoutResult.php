<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Checkout;

use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * What one checkout operation produced: the HTTP status and body the platform
 * receives, and — when the operation changed the session — the checkout to
 * store and the states it passed through on the way.
 */
final readonly class CheckoutResult
{
    /**
     * @param array<string, mixed> $body the response: a checkout, or an error_response
     * @param array<string, mixed>|null $checkout the new state to store; null when nothing changed
     * @param list<array{state: string, note: string}> $transitions every state entered, in order
     */
    public function __construct(
        public int $status,
        public array $body,
        public ?array $checkout = null,
        public array $transitions = [],
    ) {}

    /**
     * The session as it stands, with what went wrong this time. Nothing is
     * stored: a rejected update or a declined payment leaves the checkout as
     * it was.
     *
     * @param array<string, mixed> $body
     */
    public static function unchanged(array $body, int $status = 200): self
    {
        return new self($status, $body);
    }

    /**
     * No resource to return — the store refused to create one, or there is
     * none: the error_response shape (schemas/common/types/error_response.json).
     *
     * @param list<array<string, mixed>> $messages at least one
     */
    public static function failure(int $status, array $messages): self
    {
        return new self($status, self::errorResponse($messages));
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array{ucp: array{version: string, status: 'error'}, messages: list<array<string, mixed>>}
     */
    public static function errorResponse(array $messages): array
    {
        return [
            'ucp' => ['version' => Spec::VERSION, 'status' => 'error'],
            'messages' => Messages::ordered($messages),
        ];
    }
}
