<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * A SendMessageRequest (used by SendMessage and SendStreamingMessage), read
 * and checked.
 *
 * `configuration.returnImmediately` defaults to false: SendMessage blocks until
 * the task is finished or waits for input. An inline push-notification config
 * is refused with PushNotificationNotSupportedError, like every other push
 * operation — this agent never calls a webhook.
 */
final readonly class SendMessageParams
{
    /**
     * @param list<string> $acceptedOutputModes
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public Message $message,
        public array $acceptedOutputModes = [],
        public ?int $historyLength = null,
        public bool $returnImmediately = false,
        public array $metadata = [],
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public static function fromArray(array $params): self
    {
        if (!isset($params['message'])) {
            throw A2aException::invalidParams('message', 'is required.');
        }
        $message = Message::fromArray($params['message'], 'message');
        if ($message->role !== Role::User) {
            throw A2aException::invalidParams('message.role', 'must be ROLE_USER for a message a client sends.');
        }

        $configuration = Json::struct($params, 'configuration', '');
        if (($configuration['taskPushNotificationConfig'] ?? null) !== null) {
            throw A2aException::pushNotificationsNotSupported();
        }
        $historyLength = Json::int($configuration, 'historyLength', 'configuration');
        if ($historyLength !== null && $historyLength < 0) {
            throw A2aException::invalidParams('configuration.historyLength', 'must be 0 or more.');
        }

        return new self(
            $message,
            Json::stringList($configuration, 'acceptedOutputModes', 'configuration'),
            $historyLength,
            Json::bool($configuration, 'returnImmediately', 'configuration') ?? false,
            Json::struct($params, 'metadata', ''),
        );
    }
}
