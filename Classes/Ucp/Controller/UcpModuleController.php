<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use Webconsulting\AgentNexus\Agentstack\Inspector\InspectorPresenter;
use Webconsulting\AgentNexus\Agentstack\Service\SiteLocator;
use Webconsulting\AgentNexus\Shared\Backend\ModuleFrame;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Http\Api\Route;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Protocol;
use Webconsulting\AgentNexus\Shared\Store\ObjectFilter;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Ucp\Agent\Intent;
use Webconsulting\AgentNexus\Ucp\Checkout\Money;
use Webconsulting\AgentNexus\Ucp\Payment\SandboxPaymentHandler;
use Webconsulting\AgentNexus\Ucp\Profile\ProfileBuilder;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * The UCP section of the backend: two screens.
 *
 * - Checkout console: the backend plays the visitor. It runs the shopping
 *   agent through its public endpoint — exactly what the widget does — shows
 *   the AG-UI stream with every UCP request and response, stops at the
 *   approval, and renders the checkout the business returns. A manual section
 *   calls GET and cancel on the REST binding directly.
 * - Business profile: the profile as published, checked against the rules of
 *   UCP 2026-08-25, with its services, capabilities, payment handler, the
 *   endpoints and the product catalogue. Everything is read from the code
 *   that serves it.
 */
#[AsController]
final readonly class UcpModuleController
{
    public const string LANGUAGE_DOMAIN = 'agent_nexus.ucp';

    private const string STYLESHEET = 'EXT:agent_nexus/Resources/Public/Css/modules/ucp.css';
    private const string CONSOLE_MODULE = '@webconsulting/agent-nexus/ucp-console.js';
    private const int RECENT_CHECKOUTS = 10;

    public function __construct(
        private ModuleFrame $moduleFrame,
        private RouteRegistry $routes,
        private ObjectStore $objectStore,
        private InspectorPresenter $inspector,
        private UriBuilder $uriBuilder,
        private SiteLocator $siteLocator,
        private Merchant $merchant,
        private ProfileBuilder $profiles,
        private ExtensionSettings $settings,
        private LlmGuard $llmGuard,
    ) {}

    public function consoleAction(ServerRequestInterface $request): ResponseInterface
    {
        $origin = $this->origin($request);
        $recent = array_map(
            $this->inspector->row(...),
            $this->objectStore->list(new ObjectFilter(ObjectKind::Checkout), self::RECENT_CHECKOUTS),
        );
        $model = $this->llmGuard->allows(Protocol::Ucp->value);

        $view = $this->moduleFrame->create($request, [self::STYLESHEET], [self::CONSOLE_MODULE]);
        $view->assignMultiple([
            'agentUrl' => $this->routes->url('ucp.agent', $origin),
            'restEndpoint' => ProfileBuilder::restEndpoint($origin . $this->routes->apiBasePath()),
            'profileUrl' => $this->profileUrl($origin),
            'platformProfileUrl' => $this->routes->url('ucp.platform.profile', $origin),
            'intents' => $this->intents(),
            'counts' => $this->counts(),
            'recent' => $recent,
            'modelAllowed' => $model['allowed'],
            'modelReason' => $model['reason'],
            'redacted' => $this->settings->trafficRedactPersonalData(),
            'profileScreenUri' => (string)$this->uriBuilder->buildUriFromRoute('agentnexus_ucp_profile'),
            'inspectorUri' => (string)$this->uriBuilder->buildUriFromRoute(ObjectKind::Checkout->inspectorModule()),
            'demoUrl' => $this->siteLocator->protocolUrl(Protocol::Ucp->value) ?? '',
        ]);

        return $view->renderResponse('Ucp/Console');
    }

    public function profileAction(ServerRequestInterface $request): ResponseInterface
    {
        $origin = $this->origin($request);
        $profile = $this->profiles->business($origin . $this->routes->apiBasePath());
        $checks = $this->profiles->checks($profile);
        $ucp = $profile['ucp'];

        $view = $this->moduleFrame->create($request, [self::STYLESHEET]);
        $view->assignMultiple([
            'profileUrl' => $this->profileUrl($origin),
            'wellKnown' => $this->routes->get('ucp.profile.wellknown') !== null,
            'platformProfileUrl' => $this->routes->url('ucp.platform.profile', $origin),
            'checks' => $checks,
            'valid' => array_all($checks, static fn(array $check): bool => $check['pass']),
            'version' => Spec::VERSION,
            'services' => $this->entries($ucp['services'] ?? null),
            'capabilities' => $this->entries($ucp['capabilities'] ?? null),
            'handlers' => $this->entries($ucp['payment_handlers'] ?? null),
            'tokens' => [
                ['token' => SandboxPaymentHandler::TOKEN_SUCCESS, 'outcome' => 'success'],
                ['token' => SandboxPaymentHandler::TOKEN_DECLINE, 'outcome' => 'decline'],
            ],
            'catalogue' => $this->catalogue(),
            'intents' => $this->intents(),
            'endpoints' => array_map(
                fn(Route $route): array => [
                    'method' => implode(', ', $route->methods),
                    'path' => $route->uri($this->routes->apiBasePath()),
                    'operation' => $route->operation,
                    'description' => $route->description,
                ],
                $this->routes->forProtocol(Protocol::Ucp),
            ),
            'profileJson' => json_encode($profile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'consoleUri' => (string)$this->uriBuilder->buildUriFromRoute('agentnexus_ucp_console'),
        ]);

        return $view->renderResponse('Ucp/Profile');
    }

    /**
     * What the agent can be asked to buy, priced from the catalogue.
     *
     * @return list<array{id: string, label: string, products: string, total: string}>
     */
    private function intents(): array
    {
        $intents = [];
        foreach (Intent::cases() as $intent) {
            $titles = [];
            $total = 0;
            foreach ($intent->productIds() as $productId) {
                $product = $this->merchant->product($productId);
                if ($product !== []) {
                    $titles[] = $product['name'];
                    $total += $product['price'];
                }
            }
            $intents[] = [
                'id' => $intent->value,
                'label' => $intent->label(),
                'products' => implode(', ', $titles),
                'total' => Money::format($total, Merchant::CURRENCY),
            ];
        }
        return $intents;
    }

    /**
     * @return list<array{id: string, name: string, price: string, monthly: bool, description: string}>
     */
    private function catalogue(): array
    {
        return array_map(
            static fn(array $product): array => [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => Money::format($product['price'], Merchant::CURRENCY),
                'monthly' => $product['unit'] === '/mo',
                'description' => $product['description'],
            ],
            $this->merchant->catalog(),
        );
    }

    /**
     * Sessions by state, for the tiles above the console.
     *
     * @return array{total: int, open: int, completed: int, canceled: int}
     */
    private function counts(): array
    {
        $count = fn(string $state): int => $this->objectStore->count(new ObjectFilter(ObjectKind::Checkout, $state));
        return [
            'total' => $this->objectStore->count(new ObjectFilter(ObjectKind::Checkout)),
            'open' => $count(Spec::STATUS_INCOMPLETE) + $count(Spec::STATUS_READY),
            'completed' => $count(Spec::STATUS_COMPLETED),
            'canceled' => $count(Spec::STATUS_CANCELED),
        ];
    }

    /**
     * A registry of the profile as table rows.
     *
     * @return list<array{name: string, id: string, version: string, transport: string, endpoint: string, spec: string, schema: string, config: string, instruments: string}>
     */
    private function entries(mixed $registry): array
    {
        $rows = [];
        foreach (is_array($registry) ? $registry : [] as $name => $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $instruments = is_array($entry['available_instruments'] ?? null)
                    ? array_filter(array_map(static fn(mixed $i): string => is_array($i) && is_string($i['type'] ?? null) ? $i['type'] : '', $entry['available_instruments']))
                    : [];
                $rows[] = [
                    'name' => (string)$name,
                    'id' => $this->string($entry['id'] ?? ''),
                    'version' => $this->string($entry['version'] ?? ''),
                    'transport' => $this->string($entry['transport'] ?? ''),
                    'endpoint' => $this->string($entry['endpoint'] ?? ''),
                    'spec' => $this->string($entry['spec'] ?? ''),
                    'schema' => $this->string($entry['schema'] ?? ''),
                    'config' => is_array($entry['config'] ?? null) ? json_encode($entry['config'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : '',
                    'instruments' => implode(', ', $instruments),
                ];
            }
        }
        return $rows;
    }

    /** Where the business profile of this host is published. */
    private function profileUrl(string $origin): string
    {
        return $this->routes->get('ucp.profile.wellknown') !== null
            ? $this->routes->url('ucp.profile.wellknown', $origin)
            : $this->routes->url('ucp.profile', $origin);
    }

    /**
     * The backend's own host: the API answers on every host of the
     * installation, so the console calls the one it was opened on.
     */
    private function origin(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        return $uri->getScheme() . '://' . $uri->getAuthority();
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
