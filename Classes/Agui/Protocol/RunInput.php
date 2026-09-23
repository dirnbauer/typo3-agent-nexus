<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

use Webconsulting\AgentNexus\Shared\Http\WidgetContext;

/**
 * One RunAgentInput, read and checked as AG-UI 1.0 asks a producer to.
 *
 * Unrecognised material — a member the schema does not declare, a message
 * role or content part from a newer version — is stripped and noted in
 * {@see self::$warnings}, never echoed. A malformed known value rejects the
 * input before the run starts ({@see InvalidRunInput}). So does a
 * `protocolVersion` from a later major line; a newer minor of 1.x is served.
 */
final readonly class RunInput
{
    /** A run input larger than this is refused with 413. */
    public const int MAX_BYTES = 262144;

    /** Longest thread or run id; they are stored as object ids. */
    private const int MAX_ID_LENGTH = 128;

    /**
     * @param list<array<string, mixed>> $messages
     * @param list<array<string, mixed>>|null $tools   null when the input carried none
     * @param list<array<string, mixed>>|null $context null when the input carried none
     * @param mixed $state          the state the run starts from; null when absent (read as {})
     * @param mixed $forwardedProps null when absent
     * @param list<array{interruptId: string, status: string, payload?: mixed, metadata?: mixed}> $resume
     * @param list<string> $warnings what was stripped, and why
     */
    public function __construct(
        public string $threadId,
        public string $runId,
        public array $messages,
        public ?string $protocolVersion = null,
        public ?string $parentRunId = null,
        public mixed $state = null,
        public ?array $tools = null,
        public ?array $context = null,
        public mixed $forwardedProps = null,
        public array $resume = [],
        public array $warnings = [],
    ) {}

    /**
     * @throws InvalidRunInput
     */
    public static function fromJson(string $body): self
    {
        if (strlen($body) > self::MAX_BYTES) {
            throw new InvalidRunInput(413, 'input_too_large', sprintf('The run input is larger than %d bytes.', self::MAX_BYTES));
        }
        try {
            $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidRunInput(400, 'invalid_json', 'The request body is not JSON. Send a RunAgentInput object.');
        }
        return self::fromDecoded($decoded);
    }

    /**
     * @param mixed $decoded JSON decoded with objects as \stdClass, or the same data as PHP arrays
     * @throws InvalidRunInput
     */
    public static function fromDecoded(mixed $decoded): self
    {
        if (!Json::isObject($decoded)) {
            throw new InvalidRunInput(400, 'invalid_input', 'A RunAgentInput is a JSON object.');
        }
        $reader = new ShapeReader(strict: false);
        try {
            $input = $reader->runInput($decoded);
        } catch (ShapeError $e) {
            throw new InvalidRunInput(400, 'invalid_input', 'The run input is malformed at ' . $e->getMessage(), $e->pointer);
        }

        $warnings = $reader->stripped();
        $threadId = self::id($input, 'threadId');
        $runId = self::id($input, 'runId');
        $version = is_string($input['protocolVersion'] ?? null) ? $input['protocolVersion'] : null;
        if ($version !== null) {
            $warnings = [...$warnings, ...self::checkVersion($version)];
        }

        return new self(
            threadId: $threadId,
            runId: $runId,
            messages: self::listOfObjects($input['messages'] ?? []),
            protocolVersion: $version,
            parentRunId: is_string($input['parentRunId'] ?? null) ? $input['parentRunId'] : null,
            state: $input['state'] ?? null,
            tools: array_key_exists('tools', $input) ? self::listOfObjects($input['tools']) : null,
            context: array_key_exists('context', $input) ? self::listOfObjects($input['context']) : null,
            forwardedProps: $input['forwardedProps'] ?? null,
            resume: self::resumeEntries($input['resume'] ?? []),
            warnings: $warnings,
        );
    }

    /**
     * The text of the last user message: its string content, or its text
     * parts joined. Other parts are skipped, as a producer that cannot use a
     * part must do.
     */
    public function lastUserText(): string
    {
        foreach (array_reverse($this->messages) as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_string($content)) {
                return trim($content);
            }
            $texts = [];
            foreach (is_array($content) ? $content : [] as $part) {
                if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                    $texts[] = $part['text'];
                }
            }
            return trim(implode("\n", $texts));
        }
        return '';
    }

    /**
     * forwardedProps as an object's members; empty when it is absent or not
     * an object.
     *
     * @return array<string, mixed>
     */
    public function forwardedProps(): array
    {
        return Json::isObject($this->forwardedProps) ? Json::members($this->forwardedProps) : [];
    }

    /**
     * What this installation's own widgets add under
     * forwardedProps.agentNexus: content element, page, URL, preset.
     *
     * @return array<string, mixed>
     */
    public function agentNexus(): array
    {
        $extras = $this->forwardedProps()[WidgetContext::KEY] ?? null;
        return Json::isObject($extras) ? Json::members($extras) : [];
    }

    /**
     * The input as the specification shapes it, without this installation's
     * own extras — what a run record keeps.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $input = ['threadId' => $this->threadId, 'runId' => $this->runId];
        if ($this->protocolVersion !== null) {
            $input['protocolVersion'] = $this->protocolVersion;
        }
        if ($this->parentRunId !== null) {
            $input['parentRunId'] = $this->parentRunId;
        }
        if ($this->state !== null) {
            $input['state'] = $this->state;
        }
        $input['messages'] = $this->messages;
        if ($this->tools !== null) {
            $input['tools'] = $this->tools;
        }
        if ($this->context !== null) {
            $input['context'] = $this->context;
        }
        if ($this->forwardedProps !== null) {
            $forwarded = Json::isObject($this->forwardedProps) ? $this->forwardedProps() : null;
            if ($forwarded === null) {
                $input['forwardedProps'] = $this->forwardedProps;
            } else {
                unset($forwarded[WidgetContext::KEY]);
                if ($forwarded !== []) {
                    $input['forwardedProps'] = $forwarded;
                }
            }
        }
        if ($this->resume !== []) {
            $input['resume'] = $this->resume;
        }
        return $input;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function id(array $input, string $name): string
    {
        $id = is_string($input[$name] ?? null) ? $input[$name] : '';
        if ($id === '' || mb_strlen($id) > self::MAX_ID_LENGTH) {
            throw new InvalidRunInput(400, 'invalid_input', sprintf('%s must be a string of 1 to %d characters.', $name, self::MAX_ID_LENGTH), '/' . $name);
        }
        return $id;
    }

    /**
     * MAJOR.MINOR, compared numerically. Another major line may be refused
     * before the run starts; a newer minor of 1.x must be served; a version
     * that cannot be read is served too.
     *
     * @return list<string>
     */
    private static function checkVersion(string $version): array
    {
        if (preg_match('/^(\d+)\.(\d+)$/', $version, $parts) !== 1) {
            return [sprintf('/protocolVersion: "%s" is not a MAJOR.MINOR version; served as 1.0', $version)];
        }
        $major = (int)$parts[1];
        $minor = (int)$parts[2];
        if ($major > 1) {
            throw new InvalidRunInput(400, 'unsupported_protocol_version', sprintf(
                'This agent speaks AG-UI %s; protocol version %s is from another major line.',
                EventType::PROTOCOL_VERSION,
                $version,
            ), '/protocolVersion');
        }
        if ($major === 1 && $minor > 0) {
            return [sprintf('/protocolVersion: the client speaks %s; this agent answers in %s', $version, EventType::PROTOCOL_VERSION)];
        }
        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOfObjects(mixed $list): array
    {
        $objects = [];
        foreach (is_array($list) ? $list : [] as $item) {
            if (is_array($item)) {
                $objects[] = Json::members($item);
            }
        }
        return $objects;
    }

    /**
     * @return list<array{interruptId: string, status: string, payload?: mixed, metadata?: mixed}>
     */
    private static function resumeEntries(mixed $list): array
    {
        $entries = [];
        foreach (self::listOfObjects($list) as $entry) {
            $clean = [
                'interruptId' => is_string($entry['interruptId'] ?? null) ? $entry['interruptId'] : '',
                'status' => is_string($entry['status'] ?? null) ? $entry['status'] : '',
            ];
            if (array_key_exists('payload', $entry)) {
                $clean['payload'] = $entry['payload'];
            }
            if (array_key_exists('metadata', $entry)) {
                $clean['metadata'] = $entry['metadata'];
            }
            $entries[] = $clean;
        }
        return $entries;
    }
}
