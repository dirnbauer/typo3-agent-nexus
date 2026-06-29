<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Eid;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\AgentNexus\A2a\Protocol\Frames;
use Webconsulting\AgentNexus\A2a\Service\SseEncoder;
use Webconsulting\AgentNexus\A2a\Service\TaskLogger;
use Webconsulting\AgentNexus\A2a\Service\TaskRunner;

/**
 * The site's public **A2A JSON-RPC server** — the endpoint the Agent Card points
 * to. A genuine (if compact) A2A surface another agent can call:
 *
 *   - `message/stream` → Server-Sent Events of the Task lifecycle (the star turn).
 *   - `message/send`   → the same run, collected into a single Task response.
 *
 * Rate-limited; the agent itself is deterministic and side-effect free, so this is
 * safe to expose. Unknown methods get a JSON-RPC `-32601`.
 */
final class RpcEndpoint
{
    private const RATE_LIMIT = 30;
    private const RATE_WINDOW = 600;

    public function rpc(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string)$request->getBody(), true);
        $body = is_array($body) ? $body : [];
        $rpcId = $body['id'] ?? 1;
        $method = (string)($body['method'] ?? '');
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];

        if (!$this->passesRateLimit($request)) {
            return new JsonResponse(Frames::error($rpcId, -32000, 'Rate limit exceeded.'), 429);
        }

        $runner = GeneralUtility::makeInstance(TaskRunner::class);

        return match ($method) {
            'message/stream' => $this->stream($runner, $params, $rpcId),
            'message/send' => $this->send($runner, $params, $rpcId),
            default => new JsonResponse(Frames::error($rpcId, -32601, 'Method not found: ' . $method), 404),
        };
    }

    private function stream(TaskRunner $runner, array $params, int|string $rpcId): ResponseInterface
    {
        $encoder = GeneralUtility::makeInstance(SseEncoder::class);
        $logger = GeneralUtility::makeInstance(TaskLogger::class);
        $metadata = is_array($params['message']['metadata'] ?? null) ? $params['message']['metadata'] : [];
        $skill = (string)($metadata['skill'] ?? '');

        $count = 0;
        $artifacts = 0;
        $finalState = 'unknown';
        $taskId = '';
        $contextId = '';

        $frames = (function () use ($runner, $params, $rpcId, &$count, &$artifacts, &$finalState, &$taskId, &$contextId): \Generator {
            foreach ($runner->run($params, 'rpc', $rpcId) as $frame) {
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
        })();

        register_shutdown_function(static function () use ($logger, &$taskId, &$contextId, $skill, &$finalState, &$count, &$artifacts): void {
            $logger->log(TaskLogger::SOURCE_RPC, $taskId, $contextId, $skill, $finalState, $count, $artifacts, 0);
        });

        $encoder->stream($frames, 55);
    }

    private function send(TaskRunner $runner, array $params, int|string $rpcId): ResponseInterface
    {
        // Collect the streamed frames into a single Task response.
        $taskId = '';
        $contextId = '';
        $state = 'submitted';
        $statusMessage = null;
        $artifactText = '';
        $artifactName = 'result';
        $history = [];

        foreach ($runner->run($params, 'rpc', $rpcId) as $frame) {
            $result = is_array($frame['result'] ?? null) ? $frame['result'] : [];
            $kind = $result['kind'] ?? '';
            if ($kind === 'task') {
                $taskId = (string)($result['id'] ?? '');
                $contextId = (string)($result['contextId'] ?? '');
            } elseif ($kind === 'status-update') {
                $state = (string)($result['status']['state'] ?? $state);
                if (isset($result['status']['message'])) {
                    $statusMessage = $result['status']['message'];
                    $history[] = $result['status']['message'];
                }
            } elseif ($kind === 'artifact-update') {
                $artifactName = (string)($result['artifact']['name'] ?? $artifactName);
                foreach (($result['artifact']['parts'] ?? []) as $part) {
                    if (($part['kind'] ?? '') === 'text') {
                        $artifactText .= (string)($part['text'] ?? '');
                    }
                }
            }
        }

        $task = [
            'kind' => 'task',
            'id' => $taskId,
            'contextId' => $contextId,
            'status' => array_filter(['state' => $state, 'message' => $statusMessage]),
            'artifacts' => $artifactText !== '' ? [[
                'artifactId' => 'art-' . substr(md5($taskId), 0, 8),
                'name' => $artifactName,
                'parts' => [['kind' => 'text', 'text' => $artifactText]],
            ]] : [],
            'history' => $history,
        ];

        return new JsonResponse(Frames::result($rpcId, $task));
    }

    private function passesRateLimit(ServerRequestInterface $request): bool
    {
        try {
            $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('a2a');
        } catch (\Throwable) {
            return true;
        }
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $key = 'rl_' . sha1($ip);
        $count = (int)$cache->get($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        $cache->set($key, $count + 1, [], self::RATE_WINDOW);
        return true;
    }
}
