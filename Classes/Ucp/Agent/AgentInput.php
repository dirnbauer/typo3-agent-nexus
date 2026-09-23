<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

use Webconsulting\AgentNexus\Shared\Http\WidgetContext;

/**
 * An AG-UI RunAgentInput, read for the shopping agent.
 *
 * Only threadId, runId and messages are required by AG-UI. The widget adds
 * its context under `forwardedProps.agentNexus`:
 *
 *     {"ce": 12, "page": 3, "url": "https://…", "intent": "pro",
 *      "email": "ada@example.org", "payment": "decline"}
 *
 * `intent` picks what to buy (a generic client that sends only a user message
 * gets the wish read from its text), `email` fills the buyer, and
 * `payment: "decline"` makes the agent submit the sandbox token that fails.
 * Nothing here is trusted beyond its type.
 */
final readonly class AgentInput
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<ResumeEntry> $resume
     * @param array{ce: int, page: int, url: string}|null $widget
     */
    public function __construct(
        public string $threadId,
        public string $runId,
        public string $parentRunId = '',
        public array $messages = [],
        public array $resume = [],
        public Intent $intent = Intent::Pro,
        public string $email = '',
        public bool $declinePayment = false,
        public ?array $widget = null,
    ) {}

    /**
     * @throws InvalidAgentInput
     */
    public static function fromBody(string $body, WidgetContext $widgetContext): self
    {
        try {
            $input = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidAgentInput('The body must be a RunAgentInput JSON object.', 1758700601);
        }
        if (!is_array($input) || array_is_list($input)) {
            throw new InvalidAgentInput('The body must be a RunAgentInput JSON object.', 1758700602);
        }
        $threadId = self::identifier($input['threadId'] ?? null, 'threadId');
        $runId = self::identifier($input['runId'] ?? null, 'runId');
        $parentRunId = isset($input['parentRunId']) ? self::identifier($input['parentRunId'], 'parentRunId') : '';

        $messages = $input['messages'] ?? null;
        if (!is_array($messages) || !array_is_list($messages)) {
            throw new InvalidAgentInput('messages must be an array.', 1758700603);
        }
        $messageList = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                throw new InvalidAgentInput('Every message must be an object.', 1758700604);
            }
            $messageList[] = self::stringKeys($message);
        }

        $resume = [];
        $entries = $input['resume'] ?? [];
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new InvalidAgentInput('resume must be an array.', 1758700605);
        }
        foreach ($entries as $entry) {
            $status = is_array($entry) ? ($entry['status'] ?? null) : null;
            if (!is_array($entry) || !in_array($status, [ResumeEntry::RESOLVED, ResumeEntry::CANCELLED], true)) {
                throw new InvalidAgentInput('Every resume entry needs an interruptId and a status of "resolved" or "cancelled".', 1758700606);
            }
            $resume[] = new ResumeEntry(self::identifier($entry['interruptId'] ?? null, 'interruptId'), $status, $entry['payload'] ?? null);
        }

        $forwarded = is_array($input['forwardedProps'] ?? null) ? $input['forwardedProps'] : [];
        $extras = is_array($forwarded[WidgetContext::KEY] ?? null) ? $forwarded[WidgetContext::KEY] : [];
        $intent = is_string($extras['intent'] ?? null) ? Intent::tryFrom($extras['intent']) : null;
        $email = is_string($extras['email'] ?? null) ? mb_substr(trim($extras['email']), 0, 254) : '';

        return new self(
            $threadId,
            $runId,
            $parentRunId,
            $messageList,
            $resume,
            $intent ?? Intent::fromText(self::textOf($messageList)),
            $email,
            ($extras['payment'] ?? null) === 'decline',
            $widgetContext->from($forwarded),
        );
    }

    /** What the visitor last wrote, for a model to ground its explanation. */
    public function lastUserText(): string
    {
        return self::textOf($this->messages);
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private static function textOf(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }
            $content = $message['content'] ?? null;
            if (is_string($content)) {
                return mb_substr(trim($content), 0, 500);
            }
            if (is_array($content)) {
                $texts = [];
                foreach ($content as $part) {
                    if (is_array($part) && ($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                        $texts[] = $part['text'];
                    }
                }
                return mb_substr(trim(implode(' ', $texts)), 0, 500);
            }
        }
        return '';
    }

    private static function identifier(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[\x21-\x7e]{1,128}$/', $value) !== 1) {
            throw new InvalidAgentInput(sprintf('%s must be a string of 1 to 128 visible characters.', $field), 1758700607);
        }
        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string)$key] = $value;
        }
        return $result;
    }
}
