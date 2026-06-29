<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\AgentNexus\A2a\Service\SseEncoder;
use Webconsulting\AgentNexus\A2a\Service\TaskLogger;
use Webconsulting\AgentNexus\A2a\Service\TaskRunner;

/**
 * Backend AJAX route target for the A2A Console.
 *
 * The console plays the role of a *client agent*: it sends an A2A `message/stream`
 * request (a JSON-RPC body) and renders the streamed Task lifecycle. This
 * controller accepts that body and streams the site agent's frames back as SSE.
 * Runs in an authenticated backend context (the AJAX route carries the BE token).
 */
final class SendController
{
    public function __construct(
        private readonly TaskRunner $runner,
        private readonly SseEncoder $encoder,
        private readonly TaskLogger $taskLogger,
    ) {}

    public function send(ServerRequestInterface $request): ResponseInterface
    {
        $beUser = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $body = json_decode((string)$request->getBody(), true);
        $body = is_array($body) ? $body : [];

        $rpcId = $body['id'] ?? 1;
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $message = is_array($params['message'] ?? null) ? $params['message'] : [];
        $metadata = is_array($message['metadata'] ?? null) ? $message['metadata'] : [];
        $skill = (string)($metadata['skill'] ?? '');

        $count = 0;
        $artifacts = 0;
        $finalState = 'unknown';
        $taskId = '';
        $contextId = '';

        $frames = (function () use ($params, $rpcId, &$count, &$artifacts, &$finalState, &$taskId, &$contextId, $skill, $beUser): \Generator {
            try {
                foreach ($this->runner->run($params, 'backend', $rpcId) as $frame) {
                    $count++;
                    $result = is_array($frame['result'] ?? null) ? $frame['result'] : [];
                    $kind = $result['kind'] ?? '';
                    if ($kind === 'task') {
                        $taskId = (string)($result['id'] ?? '');
                        $contextId = (string)($result['contextId'] ?? '');
                    } elseif ($kind === 'artifact-update' && ($result['lastChunk'] ?? false) === true) {
                        $artifacts++;
                    } elseif ($kind === 'status-update') {
                        $finalState = (string)($result['status']['state'] ?? $finalState);
                    }
                    yield $frame;
                }
            } finally {
                $this->taskLogger->log(TaskLogger::SOURCE_BACKEND, $taskId, $contextId, $skill, $finalState, $count, $artifacts, $beUser);
            }
        })();

        $this->encoder->stream($frames, 60);
    }
}
