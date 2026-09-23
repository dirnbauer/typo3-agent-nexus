<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * One piece of message or artifact content: exactly one of `text`, `raw`
 * (base64), `url` or `data` (any JSON value), plus optional `metadata`,
 * `filename` and `mediaType`.
 *
 * A2A 1.0 tells the kinds apart by which member is present; the 0.3 `kind`
 * discriminator is gone, and so are the separate TextPart/FilePart/DataPart
 * types.
 */
final readonly class Part
{
    /** The members of the content oneof, in the proto's order. */
    public const array CONTENT_MEMBERS = ['text', 'raw', 'url', 'data'];

    /**
     * @param string $kind one of {@see CONTENT_MEMBERS}
     * @param array<string, mixed> $metadata
     */
    private function __construct(
        public string $kind,
        public mixed $content,
        public array $metadata = [],
        public string $filename = '',
        public string $mediaType = '',
    ) {}

    public static function text(string $text, string $mediaType = ''): self
    {
        return new self('text', $text, [], '', $mediaType);
    }

    public static function fromArray(mixed $json, string $path): self
    {
        $part = Json::object($json, $path);
        $present = array_values(array_filter(self::CONTENT_MEMBERS, static fn(string $member): bool => array_key_exists($member, $part)));
        if (count($present) !== 1) {
            $hint = isset($part['kind'])
                ? ' This looks like an A2A 0.3 part; A2A 1.0 has no "kind" and puts the content in "text", "raw", "url" or "data".'
                : '';
            throw A2aException::invalidParams($path, 'must carry exactly one of text, raw, url or data.' . $hint);
        }
        $kind = $present[0];
        $content = $part[$kind];
        if ($kind !== 'data' && !is_string($content)) {
            throw A2aException::invalidParams($path . '.' . $kind, 'must be a string.');
        }
        if ($kind === 'raw' && is_string($content) && preg_match('#^[A-Za-z0-9+/]*={0,2}$#', $content) !== 1) {
            throw A2aException::invalidParams($path . '.raw', 'must be base64.');
        }

        return new self(
            $kind,
            $content,
            Json::struct($part, 'metadata', $path),
            Json::string($part, 'filename', $path),
            Json::string($part, 'mediaType', $path),
        );
    }

    public function isText(): bool
    {
        return $this->kind === 'text';
    }

    /** The text of a text part; empty for every other kind. */
    public function textContent(): string
    {
        return $this->kind === 'text' && is_string($this->content) ? $this->content : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $json = [$this->kind => $this->content];
        if ($this->metadata !== []) {
            $json['metadata'] = $this->metadata;
        }
        if ($this->filename !== '') {
            $json['filename'] = $this->filename;
        }
        if ($this->mediaType !== '') {
            $json['mediaType'] = $this->mediaType;
        }
        return $json;
    }
}
