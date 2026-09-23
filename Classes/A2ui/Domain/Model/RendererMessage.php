<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * A renderer-to-agent message as it arrived: an `action` a user triggered or
 * an `error` the renderer reports, plus the data model the transport metadata
 * carried for the surface (when the surface asked for it with `sendDataModel`).
 */
final readonly class RendererMessage
{
    public const string ACTION = 'action';
    public const string ERROR = 'error';

    /**
     * @param array<string, mixed> $body      the action or error object
     * @param array<string, mixed> $message   the A2UI message: version plus action or error
     * @param array<string, mixed>|null $dataModel the surface's data model from the metadata
     * @param array<string, mixed> $metadata  the transport metadata as received
     */
    public function __construct(
        public A2uiVersion $version,
        public string $kind,
        public array $body,
        public array $message,
        public ?array $dataModel = null,
        public array $metadata = [],
    ) {}

    public function isAction(): bool
    {
        return $this->kind === self::ACTION;
    }

    public function surfaceId(): string
    {
        return is_string($this->body['surfaceId'] ?? null) ? $this->body['surfaceId'] : '';
    }

    public function name(): string
    {
        return is_string($this->body['name'] ?? null) ? $this->body['name'] : '';
    }

    public function sourceComponentId(): string
    {
        return is_string($this->body['sourceComponentId'] ?? null) ? $this->body['sourceComponentId'] : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $context = [];
        foreach (is_array($this->body['context'] ?? null) ? $this->body['context'] : [] as $key => $value) {
            $context[(string)$key] = $value;
        }
        return $context;
    }

    /**
     * What is stored on the surface: the message, and the metadata that came with it.
     *
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return $this->metadata !== [] ? $this->message + ['metadata' => $this->metadata] : $this->message;
    }
}
