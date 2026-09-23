<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Http;

use Webconsulting\AgentNexus\Shared\Http\Api\Route;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteProvider;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * The endpoints of the A2A agent: discovery, the JSON-RPC 2.0 binding and the
 * HTTP+JSON binding. The descriptions are what the backend and the "Protocol
 * info" element list for each endpoint.
 */
final readonly class A2aRoutes implements RouteProvider
{
    /** Where the HTTP+JSON binding lives, below the API base path. */
    public const string REST_BASE = '/a2a/rest';

    public const string DISCOVERY = 'Discovery';
    public const string JSON_RPC = 'JSON-RPC 2.0';
    public const string HTTP_JSON = 'HTTP+JSON';

    #[\Override]
    public function routes(): array
    {
        $card = AgentCardEndpoint::class . '::card';
        $rest = RestEndpoint::class;

        return [
            new Route('a2a.card.wellknown', Protocol::A2a, ['GET'], '/.well-known/agent-card.json', $card, 'GetAgentCard', self::DISCOVERY, 'The Agent Card at the address A2A reserves for it: who this agent is, where to reach it and what it can do.', wellKnown: true),
            new Route('a2a.card', Protocol::A2a, ['GET'], '/a2a/agent-card.json', $card, 'GetAgentCard', self::DISCOVERY, 'The same Agent Card, below the API path.'),
            new Route('a2a.jsonrpc', Protocol::A2a, ['POST'], '/a2a/jsonrpc', JsonRpcEndpoint::class . '::handle', '', self::JSON_RPC, 'Every A2A method as a JSON-RPC 2.0 call, streaming ones as Server-Sent Events. Send A2A-Version: 1.0; without it the endpoint answers in A2A 0.3.'),
            new Route('a2a.rest.send', Protocol::A2a, ['POST'], self::REST_BASE . '/message:send', $rest . '::send', 'SendMessage', self::HTTP_JSON, 'Send a message and wait until the task finishes or asks for input.'),
            new Route('a2a.rest.stream', Protocol::A2a, ['POST'], self::REST_BASE . '/message:stream', $rest . '::stream', 'SendStreamingMessage', self::HTTP_JSON, 'Send a message and follow the task as Server-Sent Events.'),
            new Route('a2a.rest.list', Protocol::A2a, ['GET'], self::REST_BASE . '/tasks', $rest . '::list', 'ListTasks', self::HTTP_JSON, 'List tasks, most recently updated first, filtered by context or state, a page at a time.'),
            new Route('a2a.rest.get', Protocol::A2a, ['GET'], self::REST_BASE . '/tasks/{id}', $rest . '::get', 'GetTask', self::HTTP_JSON, 'Read one task: its status, artifacts and message history.'),
            new Route('a2a.rest.cancel', Protocol::A2a, ['POST'], self::REST_BASE . '/tasks/{id}:cancel', $rest . '::cancel', 'CancelTask', self::HTTP_JSON, 'Cancel a task that has not finished yet.'),
            new Route('a2a.rest.subscribe', Protocol::A2a, ['GET', 'POST'], self::REST_BASE . '/tasks/{id}:subscribe', $rest . '::subscribe', 'SubscribeToTask', self::HTTP_JSON, 'Follow a task that has not finished yet as Server-Sent Events.'),
            new Route('a2a.rest.push', Protocol::A2a, ['GET', 'POST'], self::REST_BASE . '/tasks/{id}/pushNotificationConfigs', $rest . '::pushConfigs', '', self::HTTP_JSON, 'Push notification settings of a task. This agent sends no push notifications, so it answers with an error.'),
            new Route('a2a.rest.push.item', Protocol::A2a, ['GET', 'DELETE'], self::REST_BASE . '/tasks/{id}/pushNotificationConfigs/{configId}', $rest . '::pushConfig', '', self::HTTP_JSON, 'One push notification setting. Answers with an error, like the list.'),
            new Route('a2a.rest.card', Protocol::A2a, ['GET'], self::REST_BASE . '/extendedAgentCard', $rest . '::extendedCard', 'GetExtendedAgentCard', self::HTTP_JSON, 'The extended Agent Card for signed-in clients. This agent has none, so it answers with an error.'),
        ];
    }
}
