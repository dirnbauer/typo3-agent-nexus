<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Protocol;

/**
 * The eleven A2A operations, named as the JSON-RPC binding of 1.0 names them,
 * with their 0.3 method names and HTTP+JSON endpoints (specification section
 * 5.3). ListTasks is new in 1.0 and has no 0.3 name.
 */
enum Operation: string
{
    case SendMessage = 'SendMessage';
    case SendStreamingMessage = 'SendStreamingMessage';
    case GetTask = 'GetTask';
    case ListTasks = 'ListTasks';
    case CancelTask = 'CancelTask';
    case SubscribeToTask = 'SubscribeToTask';
    case CreateTaskPushNotificationConfig = 'CreateTaskPushNotificationConfig';
    case GetTaskPushNotificationConfig = 'GetTaskPushNotificationConfig';
    case ListTaskPushNotificationConfigs = 'ListTaskPushNotificationConfigs';
    case DeleteTaskPushNotificationConfig = 'DeleteTaskPushNotificationConfig';
    case GetExtendedAgentCard = 'GetExtendedAgentCard';

    /** The JSON-RPC method name in A2A 0.3, or null when 0.3 had no such method. */
    public function legacyMethod(): ?string
    {
        return match ($this) {
            self::SendMessage => 'message/send',
            self::SendStreamingMessage => 'message/stream',
            self::GetTask => 'tasks/get',
            self::ListTasks => null,
            self::CancelTask => 'tasks/cancel',
            self::SubscribeToTask => 'tasks/resubscribe',
            self::CreateTaskPushNotificationConfig => 'tasks/pushNotificationConfig/set',
            self::GetTaskPushNotificationConfig => 'tasks/pushNotificationConfig/get',
            self::ListTaskPushNotificationConfigs => 'tasks/pushNotificationConfig/list',
            self::DeleteTaskPushNotificationConfig => 'tasks/pushNotificationConfig/delete',
            self::GetExtendedAgentCard => 'agent/getAuthenticatedExtendedCard',
        };
    }

    /** HTTP method and path of the HTTP+JSON binding, relative to its base URL. */
    public function restEndpoint(): string
    {
        return match ($this) {
            self::SendMessage => 'POST /message:send',
            self::SendStreamingMessage => 'POST /message:stream',
            self::GetTask => 'GET /tasks/{id}',
            self::ListTasks => 'GET /tasks',
            self::CancelTask => 'POST /tasks/{id}:cancel',
            self::SubscribeToTask => 'GET|POST /tasks/{id}:subscribe',
            self::CreateTaskPushNotificationConfig => 'POST /tasks/{id}/pushNotificationConfigs',
            self::GetTaskPushNotificationConfig => 'GET /tasks/{id}/pushNotificationConfigs/{configId}',
            self::ListTaskPushNotificationConfigs => 'GET /tasks/{id}/pushNotificationConfigs',
            self::DeleteTaskPushNotificationConfig => 'DELETE /tasks/{id}/pushNotificationConfigs/{configId}',
            self::GetExtendedAgentCard => 'GET /extendedAgentCard',
        };
    }

    public function isStreaming(): bool
    {
        return $this === self::SendStreamingMessage || $this === self::SubscribeToTask;
    }

    public function isPushNotificationConfig(): bool
    {
        return match ($this) {
            self::CreateTaskPushNotificationConfig, self::GetTaskPushNotificationConfig,
            self::ListTaskPushNotificationConfigs, self::DeleteTaskPushNotificationConfig => true,
            default => false,
        };
    }

    /** The operation behind a JSON-RPC method name in the given version. */
    public static function fromMethod(string $method, ProtocolVersion $version): ?self
    {
        if ($version === ProtocolVersion::V1_0) {
            return self::tryFrom($method);
        }
        return array_find(self::cases(), static fn(self $operation): bool => $operation->legacyMethod() === $method);
    }
}
