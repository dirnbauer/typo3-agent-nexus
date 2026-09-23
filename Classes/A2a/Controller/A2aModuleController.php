<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use Webconsulting\AgentNexus\A2a\Protocol\A2aError;
use Webconsulting\AgentNexus\A2a\Protocol\Json;
use Webconsulting\AgentNexus\A2a\Protocol\Operation;
use Webconsulting\AgentNexus\A2a\Protocol\TaskState;
use Webconsulting\AgentNexus\A2a\Service\AgentCard;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\Agentstack\Inspector\InspectorPresenter;
use Webconsulting\AgentNexus\Agentstack\Service\SiteLocator;
use Webconsulting\AgentNexus\Shared\Backend\ModuleFrame;
use Webconsulting\AgentNexus\Shared\Http\Api\Route;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Protocol;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;

/**
 * The A2A section of the backend: two screens.
 *
 * - Task console: the backend plays a client agent. It reads the Agent Card,
 *   sends messages to the public JSON-RPC endpoint from the browser — exactly
 *   what another agent does — and shows every frame and the task as it moves.
 * - Agent Card: the published card, and the tables a client needs next to it
 *   (interfaces, skills, capabilities, endpoints, methods, task states, error
 *   codes), every one read from the code that implements it.
 */
#[AsController]
final readonly class A2aModuleController
{
    public const string LANGUAGE_DOMAIN = 'agent_nexus.a2a';

    private const string STYLESHEET = 'EXT:agent_nexus/Resources/Public/Css/modules/a2a.css';
    private const string CONSOLE_MODULE = '@webconsulting/agent-nexus/a2a-console.js';
    private const int RECENT_TASKS = 10;

    public function __construct(
        private ModuleFrame $moduleFrame,
        private AgentCard $agentCard,
        private SkillCatalog $skills,
        private RouteRegistry $routes,
        private ObjectStore $objectStore,
        private InspectorPresenter $inspector,
        private UriBuilder $uriBuilder,
        private SiteLocator $siteLocator,
    ) {}

    public function consoleAction(ServerRequestInterface $request): ResponseInterface
    {
        $origin = $this->origin($request);
        $recent = array_map(
            $this->inspector->row(...),
            $this->objectStore->list(new ObjectFilter(ObjectKind::Task), self::RECENT_TASKS),
        );

        $view = $this->moduleFrame->create($request, [self::STYLESHEET], [self::CONSOLE_MODULE]);
        $view->assignMultiple([
            'skills' => array_values($this->skills->all()),
            'jsonRpcUrl' => $this->routes->url('a2a.jsonrpc', $origin),
            'cardUrl' => $this->cardUrl($origin),
            'cardScreenUri' => (string)$this->uriBuilder->buildUriFromRoute('agentnexus_a2a_card'),
            'inspectorUri' => (string)$this->uriBuilder->buildUriFromRoute(ObjectKind::Task->inspectorModule()),
            'recent' => $recent,
            'demoUrl' => $this->siteLocator->protocolUrl(Protocol::A2a->value) ?? '',
        ]);

        return $view->renderResponse('A2a/Console');
    }

    public function cardAction(ServerRequestInterface $request): ResponseInterface
    {
        $origin = $this->origin($request);
        $card = $this->agentCard->build($origin);
        $apiBasePath = $this->routes->apiBasePath();

        $view = $this->moduleFrame->create($request, [self::STYLESHEET]);
        $view->assignMultiple([
            'card' => $card,
            'cardJson' => Json::encode($card, true),
            'cardUrl' => $this->cardUrl($origin),
            'consoleUri' => (string)$this->uriBuilder->buildUriFromRoute('agentnexus_a2a_console'),
            'skills' => array_values($this->skills->all()),
            'endpoints' => array_map(static fn(Route $route): array => [
                'methods' => implode(', ', $route->methods),
                'path' => $route->uri($apiBasePath),
                'binding' => $route->binding,
                'description' => $route->description,
            ], $this->routes->forProtocol(Protocol::A2a)),
            'operations' => array_map(static fn(Operation $operation): array => [
                'name' => $operation->value,
                'legacy' => $operation->legacyMethod() ?? '',
                'rest' => $operation->restEndpoint(),
                'streaming' => $operation->isStreaming(),
            ], Operation::cases()),
            'states' => array_map(static fn(TaskState $state): array => [
                'value' => $state->value,
                'legacy' => $state->legacyValue(),
                'kind' => $state->isTerminal() ? 'terminal' : ($state->isInterrupted() ? 'interrupted' : 'active'),
                'badge' => InspectorPresenter::stateBadge(ObjectKind::Task, $state->value),
            ], TaskState::cases()),
            'errors' => array_map(static fn(A2aError $error): array => [
                'name' => $error->specName(),
                // Only the errors that carry a google.rpc.ErrorInfo have a reason.
                'reason' => $error->isA2aSpecific() || $error === A2aError::RateLimited ? $error->value : '',
                'code' => $error->code(),
                'httpStatus' => $error->httpStatus(),
                'rpcStatus' => $error->rpcStatus(),
            ], A2aError::cases()),
        ]);

        return $view->renderResponse('A2a/Card');
    }

    /** The published card: the well-known address when it is published, else the API one. */
    private function cardUrl(string $origin): string
    {
        return $this->routes->get('a2a.card.wellknown') !== null
            ? $this->routes->url('a2a.card.wellknown', $origin)
            : $this->routes->url('a2a.card', $origin);
    }

    /**
     * The backend's own host: the console calls the public endpoints from the
     * browser, so they must be on the host the browser is on.
     */
    private function origin(ServerRequestInterface $request): string
    {
        return $request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority();
    }
}
