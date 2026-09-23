<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\AgentNexus\Agui\Agent\Audience;
use Webconsulting\AgentNexus\Agui\Http\AguiEndpoint;
use Webconsulting\AgentNexus\Agui\Protocol\InvalidRunInput;
use Webconsulting\AgentNexus\Agui\Protocol\RunInput;
use Webconsulting\AgentNexus\Agui\Service\RunConflict;
use Webconsulting\AgentNexus\Agui\Service\RunPlan;
use Webconsulting\AgentNexus\Agui\Service\RunService;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;

/**
 * The run console's own endpoint (AJAX route `agentnexus_agui_run`): the
 * editor agent, for backend users only.
 *
 * It speaks exactly what the public endpoint speaks — a RunAgentInput in, an
 * AG-UI 1.0 event stream out, approval as an interrupt answered by `resume` —
 * with the task in `forwardedProps.agentNexus.preset` (seo, translate, news).
 * Its interrupts can only be answered here, never through the public
 * endpoint.
 */
#[Autoconfigure(public: true)]
final readonly class RunController
{
    public function __construct(
        private RunService $runs,
    ) {}

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $input = RunInput::fromJson((string)$request->getBody());
        } catch (InvalidRunInput $e) {
            return AguiEndpoint::error($e->status, $e->reason, $e->getMessage(), $e->pointer);
        }
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        if ($capture instanceof TrafficCapture) {
            $capture->correlate($input->runId);
        }
        $preset = $input->agentNexus()['preset'] ?? '';
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        try {
            return $this->runs->start($input, new RunPlan(
                audience: Audience::Editor,
                preset: is_string($preset) ? $preset : '',
                channel: Channel::Backend,
                beUser: $backendUser instanceof BackendUserAuthentication ? (int)($backendUser->user['uid'] ?? 0) : 0,
                delayMs: 60,
            ));
        } catch (RunConflict $e) {
            return AguiEndpoint::error(409, $e->reason, $e->getMessage());
        }
    }
}
