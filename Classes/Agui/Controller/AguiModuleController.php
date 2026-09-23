<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use Webconsulting\AgentNexus\Agentstack\Service\SiteLocator;
use Webconsulting\AgentNexus\Agui\Agent\Audience;
use Webconsulting\AgentNexus\Agui\Agent\Scenario;
use Webconsulting\AgentNexus\Agui\Agent\Scenarios;
use Webconsulting\AgentNexus\Agui\Http\AguiRoutes;
use Webconsulting\AgentNexus\Agui\Protocol\EventType;
use Webconsulting\AgentNexus\Agui\Protocol\OrderingRule;
use Webconsulting\AgentNexus\Agui\Service\EventCatalog;
use Webconsulting\AgentNexus\Agui\Service\RunTranscript;
use Webconsulting\AgentNexus\Shared\Backend\ModuleFrame;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;

/**
 * The AG-UI section of the Agent Nexus module.
 *
 * - Run console: the browser acts as an AG-UI 1.0 client. It starts a run of
 *   the editor agent (backend route) or of the site assistant (the public
 *   endpoint, exactly as any other client calls it), lists every event as it
 *   arrives, mirrors the shared state, and answers the run's interrupt with
 *   Approve or Reject — a resume on the next run.
 * - Event reference: the 31 event types, their fields and the ordering rules,
 *   printed from the same catalogue the event factory and the stream verifier
 *   use.
 */
#[AsController]
final readonly class AguiModuleController
{
    private const string STYLESHEET = 'EXT:agent_nexus/Resources/Public/Css/modules/agui.css';
    private const string CONSOLE_MODULE = '@webconsulting/agent-nexus/agui-console.js';

    /** Badge colour per run state; the state is always written out as well. */
    private const array STATE_BADGES = [
        RunTranscript::RUNNING => 'info',
        RunTranscript::INTERRUPTED => 'warning',
        RunTranscript::FINISHED => 'success',
        RunTranscript::ERROR => 'danger',
        RunTranscript::CANCELLED => 'default',
    ];

    public function __construct(
        private ModuleFrame $moduleFrame,
        private ObjectStore $objectStore,
        private Scenarios $scenarios,
        private EventCatalog $eventCatalog,
        private RouteRegistry $routes,
        private UriBuilder $uriBuilder,
        private SiteLocator $siteLocator,
    ) {}

    public function consoleAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleFrame->create($request, [self::STYLESHEET], [self::CONSOLE_MODULE]);
        $view->assignMultiple([
            'editorPresets' => array_map(self::preset(...), $this->scenarios->forAudience(Audience::Editor)),
            'sitePresets' => array_map(self::preset(...), $this->scenarios->forAudience(Audience::Site)),
            'endpoint' => $this->routes->url(AguiRoutes::RUN, self::origin($request)),
            'runUrl' => $this->uri('ajax_agentnexus_agui_run'),
            'recentRuns' => $this->recentRuns(),
            'inspectorUri' => $this->uri(ObjectKind::Run->inspectorModule()),
            'demoUrl' => $this->siteLocator->protocolUrl('agui'),
            'protocolVersion' => EventType::PROTOCOL_VERSION,
        ]);

        return $view->renderResponse('Agui/Console');
    }

    public function eventsAction(ServerRequestInterface $request): ResponseInterface
    {
        $endpoint = $this->routes->url(AguiRoutes::RUN, self::origin($request));
        $view = $this->moduleFrame->create($request, [self::STYLESHEET]);
        $view->assignMultiple([
            'families' => $this->eventCatalog->all(),
            'eventCount' => count(EventType::cases()),
            'rules' => array_map(static fn(OrderingRule $rule): string => $rule->value, OrderingRule::cases()),
            'endpoint' => $endpoint,
            'curl' => self::curlExample($endpoint),
            'protocolVersion' => EventType::PROTOCOL_VERSION,
        ]);

        return $view->renderResponse('Agui/Events');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentRuns(): array
    {
        $rows = [];
        foreach ($this->objectStore->list(new ObjectFilter(ObjectKind::Run), 10) as $run) {
            $rows[] = [
                'runId' => $run->objectId,
                'threadId' => $run->contextId,
                'label' => $run->label,
                'state' => $run->state,
                'badge' => self::STATE_BADGES[$run->state] ?? 'default',
                'source' => $run->source,
                'events' => is_int($run->payload['eventCount'] ?? null) ? $run->payload['eventCount'] : 0,
                'updated' => $run->tstamp,
                'detailUri' => $this->uri(ObjectKind::Run->inspectorModule() . '.detail', ['uid' => $run->uid]),
            ];
        }
        return $rows;
    }

    /**
     * @return array{id: string, message: string}
     */
    private static function preset(Scenario $scenario): array
    {
        return ['id' => $scenario->id, 'message' => $scenario->defaultMessage];
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function uri(string $route, array $parameters = []): string
    {
        try {
            return (string)$this->uriBuilder->buildUriFromRoute($route, $parameters);
        } catch (RouteNotFoundException) {
            return '';
        }
    }

    private static function origin(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        return $uri->getScheme() . '://' . $uri->getAuthority();
    }

    /**
     * A minimal run another client could send, as a shell command. The ids
     * are fresh on every page view: the endpoint refuses a run id it has
     * seen before.
     */
    private static function curlExample(string $endpoint): string
    {
        $input = [
            'threadId' => 'thread-' . bin2hex(random_bytes(4)),
            'runId' => 'run-' . bin2hex(random_bytes(4)),
            'protocolVersion' => EventType::PROTOCOL_VERSION,
            'messages' => [['id' => 'msg-1', 'role' => 'user', 'content' => 'Which plan suits a team of five?']],
        ];
        return 'curl -N ' . escapeshellarg($endpoint) . " \\\n"
            . "  -H 'Content-Type: application/json' \\\n"
            . "  -H 'Accept: text/event-stream' \\\n"
            . '  -d ' . escapeshellarg((string)json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
