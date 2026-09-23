<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Fixtures;

use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\SiteFinder;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteMatch;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Http\WidgetContext;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRecorder;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRedactor;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRepository;
use Webconsulting\AgentNexus\Ucp\Agent\AgentInput;
use Webconsulting\AgentNexus\Ucp\Agent\AgentSession;
use Webconsulting\AgentNexus\Ucp\Agent\Rationale;
use Webconsulting\AgentNexus\Ucp\Agent\ShoppingAgent;
use Webconsulting\AgentNexus\Ucp\Agent\UcpClient;
use Webconsulting\AgentNexus\Ucp\Checkout\CheckoutService;
use Webconsulting\AgentNexus\Ucp\Checkout\CompletionGuard;
use Webconsulting\AgentNexus\Ucp\Checkout\IdempotencyStore;
use Webconsulting\AgentNexus\Ucp\Http\CheckoutEndpoint;
use Webconsulting\AgentNexus\Ucp\Http\InProcessCaller;
use Webconsulting\AgentNexus\Ucp\Http\ProfileEndpoint;
use Webconsulting\AgentNexus\Ucp\Http\UcpRoutes;
use Webconsulting\AgentNexus\Ucp\Payment\SandboxPaymentHandler;
use Webconsulting\AgentNexus\Ucp\Profile\ProfileBuilder;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;

/**
 * The whole UCP business and its shopping agent, wired by hand: checkouts in
 * memory, idempotency records and rate limits in a transient cache, the
 * traffic log switched off. What the API router would do — match the route,
 * attach the RouteMatch — {@see send()} does.
 */
final class UcpStack
{
    public const string ORIGIN = 'https://shop.example';
    public const string PLATFORM = 'profile="https://platform.example/.well-known/ucp"';

    public readonly InMemoryCheckoutStorage $storage;
    public readonly CacheManager $cacheManager;
    public readonly Merchant $merchant;
    public readonly SandboxPaymentHandler $payments;
    public readonly ExtensionSettings $settings;
    public readonly CheckoutService $service;
    public readonly RouteRegistry $routes;
    public readonly CheckoutEndpoint $checkouts;
    public readonly ProfileEndpoint $profiles;
    public readonly ProfileBuilder $profileBuilder;
    public readonly ScriptedLanguageModel $model;
    public readonly RecordingUsageLedger $ledger;
    public readonly ShoppingAgent $agent;

    /**
     * @param list<CompletionGuard> $guards
     * @param array<string, mixed> $configuration extension configuration
     */
    public function __construct(
        array $guards = [],
        ?VariableFrontend $cache = null,
        array $configuration = [],
        ?ScriptedLanguageModel $model = null,
    ) {
        $extensionConfiguration = new FixedExtensionConfiguration($configuration + ['trafficEnabled' => '0']);
        $this->settings = new ExtensionSettings($extensionConfiguration);
        $this->storage = new InMemoryCheckoutStorage();
        $this->cacheManager = new CacheManager();
        $this->cacheManager->registerCache($cache ?? new VariableFrontend(IdempotencyStore::CACHE, new TransientMemoryBackend()));
        $this->merchant = new Merchant();
        $this->payments = new SandboxPaymentHandler();
        $this->service = new CheckoutService($this->merchant, $this->payments, $this->settings, $guards);
        $this->routes = new RouteRegistry([new UcpRoutes()], $this->settings);
        $logger = new NullLogger();
        // Rate limits live in a cache of their own here, so a test can break
        // the idempotency records alone.
        $rateLimits = new CacheManager();
        $rateLimits->registerCache(new VariableFrontend(IdempotencyStore::CACHE, new TransientMemoryBackend()));
        $rateLimiter = new RateLimiter($rateLimits);
        $this->checkouts = new CheckoutEndpoint(
            $this->service,
            $this->storage,
            new IdempotencyStore($this->cacheManager),
            $rateLimiter,
            $this->routes,
            $logger,
        );
        $this->profileBuilder = new ProfileBuilder($this->payments);
        $this->profiles = new ProfileEndpoint($this->profileBuilder);
        $this->model = $model ?? new ScriptedLanguageModel();
        $this->ledger = new RecordingUsageLedger();
        $redactor = new TrafficRedactor();
        $recorder = new TrafficRecorder($this->settings, $redactor, new TrafficRepository(new ConnectionPool()), $logger);
        $this->agent = new ShoppingAgent(
            new UcpClient($this->routes, $this->checkouts, $this->profiles, $recorder),
            $this->storage,
            $this->merchant,
            new Rationale($this->model, new LlmGuard($extensionConfiguration, $this->model, $this->ledger), $this->ledger, $rateLimiter, $logger),
            $this->payments,
            $this->routes,
            $redactor,
            $logger,
        );
    }

    /**
     * One HTTP request to the UCP API, the way the router hands it over.
     *
     * @param array<string, string> $headers
     */
    public function send(string $method, string $path, array $headers = [], string $body = ''): ResponseInterface
    {
        $match = $this->routes->match($method, $path);
        $route = $match['route'];
        if ($route === null) {
            throw new \RuntimeException('No UCP route for ' . $method . ' ' . $path, 1758709101);
        }
        $stream = new Stream('php://temp', 'rw');
        $stream->write($body);
        $stream->rewind();
        $request = (new ServerRequest(self::ORIGIN . $path, $method, $stream, $headers, ['REMOTE_ADDR' => '192.0.2.1']))
            ->withAttribute(RouteMatch::ATTRIBUTE, new RouteMatch($route, $match['parameters'], self::ORIGIN, self::ORIGIN . '/api/agent-nexus'));

        [$class, $action] = explode('::', $route->handler, 2);
        return match ($class) {
            CheckoutEndpoint::class => match ($action) {
                'create' => $this->checkouts->create($request),
                'get' => $this->checkouts->get($request),
                'update' => $this->checkouts->update($request),
                'complete' => $this->checkouts->complete($request),
                default => $this->checkouts->cancel($request),
            },
            ProfileEndpoint::class => $action === 'platform' ? $this->profiles->platform($request) : $this->profiles->business($request),
            default => throw new \RuntimeException('Not a REST route: ' . $route->id, 1758709102),
        };
    }

    /**
     * The headers of a well-behaved platform request.
     *
     * @return array<string, string>
     */
    public static function headers(string $idempotencyKey = ''): array
    {
        $headers = ['UCP-Agent' => self::PLATFORM, 'Request-Id' => self::uuid(), 'Content-Type' => 'application/json'];
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        return $headers;
    }

    public static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        return sprintf('%s-%s-4%s-a%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), substr($hex, 17, 3), substr($hex, 20, 12));
    }

    /**
     * Run the shopping agent and collect every event it emits.
     *
     * @param array<string, mixed> $input a RunAgentInput
     * @return list<array<string, mixed>>
     */
    public function runAgent(array $input, bool $modelAllowed = false): array
    {
        $agentInput = AgentInput::fromBody(json_encode($input, JSON_THROW_ON_ERROR), self::widgetContext());
        $session = new AgentSession(
            self::ORIGIN,
            self::ORIGIN . '/api/agent-nexus',
            self::ORIGIN . '/api/agent-nexus/ucp/platform-profile',
            new InProcessCaller(Channel::Agent, 0, $agentInput->threadId),
            null,
            $modelAllowed,
            true,
            new ServerRequest(self::ORIGIN . '/api/agent-nexus/ucp/agent', 'POST', 'php://temp', [], ['REMOTE_ADDR' => '192.0.2.1']),
        );
        $events = [];
        foreach ($this->agent->run($agentInput, $session) as $event) {
            // What goes over the wire: a JSON round trip.
            $decoded = json_decode(json_encode($event, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $events[] = is_array($decoded) ? self::stringKeys($decoded) : [];
        }
        return $events;
    }

    /**
     * The widget context reader. Its site lookup is never used here, so the
     * site finder is built without its dependencies.
     */
    public static function widgetContext(): WidgetContext
    {
        return new WidgetContext((new \ReflectionClass(SiteFinder::class))->newInstanceWithoutConstructor());
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    public static function stringKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[(string)$key] = $value;
        }
        return $result;
    }

    /**
     * The decoded JSON body of a response.
     *
     * @return array<string, mixed>
     */
    public static function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? self::stringKeys($decoded) : [];
    }
}
