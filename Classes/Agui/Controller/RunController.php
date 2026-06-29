<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\AgentNexus\Agui\Service\AgentRunner;
use Webconsulting\AgentNexus\Agui\Service\EventEncoder;
use Webconsulting\AgentNexus\Agui\Service\RunLogger;

/**
 * Backend AJAX route target for the Run Console: accepts a RunAgentInput (JSON
 * POST) and streams the agent's AG-UI events back as Server-Sent Events. Runs in
 * an authenticated backend context (the AJAX route carries the BE token).
 */
final class RunController
{
    public function __construct(
        private readonly AgentRunner $runner,
        private readonly EventEncoder $encoder,
        private readonly RunLogger $runLogger,
    ) {}

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $beUser = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $input = json_decode((string)$request->getBody(), true);
        $input = is_array($input) ? $input : [];

        $preset = is_string($input['preset'] ?? null) ? $input['preset'] : 'seo';
        $threadId = is_string($input['threadId'] ?? null) ? $input['threadId'] : 't-be';
        $runId = is_string($input['runId'] ?? null) ? $input['runId'] : '';
        $approved = is_array($input['approval'] ?? null);

        $count = 0;
        $outcome = 'finished';

        // Wrap the runner so we can count events and log the run on completion
        // (the generator's finally fires when the SSE loop exhausts it).
        $events = (function () use ($input, &$count, &$outcome, $threadId, $runId, $preset, $approved, $beUser): \Generator {
            try {
                foreach ($this->runner->run($input, 'backend') as $event) {
                    $count++;
                    $type = $event['type'] ?? '';
                    if ($type === 'RUN_ERROR') {
                        $outcome = 'error';
                    } elseif ($type === 'RUN_FINISHED' && ($event['result']['decision'] ?? null) === 'rejected') {
                        $outcome = 'rejected';
                    }
                    yield $event;
                }
            } finally {
                $this->runLogger->log(RunLogger::SOURCE_BACKEND, $threadId, $runId, $preset, $count, $approved, $outcome, $beUser);
            }
        })();

        // Streams + exits (SSE cannot use the normal PSR-7 response emission).
        $this->encoder->stream($events, 70);
    }
}
