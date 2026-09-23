<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * An output of a task. Results come back as artifacts, not as messages
 * (specification section 3.7). Required: `artifactId` and at least one part.
 */
final readonly class Artifact
{
    /**
     * @param non-empty-list<Part> $parts
     * @param array<string, mixed> $metadata
     * @param list<string> $extensions
     */
    public function __construct(
        public string $artifactId,
        public array $parts,
        public string $name = '',
        public string $description = '',
        public array $metadata = [],
        public array $extensions = [],
    ) {}

    public static function fromArray(mixed $json, string $path): self
    {
        $artifact = Json::object($json, $path);
        $rawParts = $artifact['parts'] ?? null;
        if (!is_array($rawParts) || $rawParts === [] || !array_is_list($rawParts)) {
            throw A2aException::invalidParams($path . '.parts', 'must be a list with at least one part.');
        }
        $parts = [];
        foreach ($rawParts as $index => $rawPart) {
            $parts[] = Part::fromArray($rawPart, $path . '.parts[' . $index . ']');
        }

        return new self(
            Json::requiredString($artifact, 'artifactId', $path),
            $parts,
            Json::string($artifact, 'name', $path),
            Json::string($artifact, 'description', $path),
            Json::struct($artifact, 'metadata', $path),
            Json::stringList($artifact, 'extensions', $path),
        );
    }

    /** The text of every text part, in order. */
    public function text(): string
    {
        return implode('', array_map(static fn(Part $part): string => $part->textContent(), $this->parts));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $json = ['artifactId' => $this->artifactId];
        if ($this->name !== '') {
            $json['name'] = $this->name;
        }
        if ($this->description !== '') {
            $json['description'] = $this->description;
        }
        $json['parts'] = array_map(static fn(Part $part): array => $part->toArray(), $this->parts);
        if ($this->metadata !== []) {
            $json['metadata'] = $this->metadata;
        }
        if ($this->extensions !== []) {
            $json['extensions'] = $this->extensions;
        }
        return $json;
    }
}
