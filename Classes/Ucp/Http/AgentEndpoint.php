<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteMatch;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Http\EventStream;
use Webconsulting\AgentNexus\Shared\Http\PluginSettings;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Http\WidgetContext;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;
use Webconsulting\AgentNexus\Ucp\Agent\AgentInput;
use Webconsulting\AgentNexus\Ucp\Agent\AgentSession;
use Webconsulting\AgentNexus\Ucp\Agent\AgUi;
use Webconsulting\AgentNexus\Ucp\Agent\InvalidAgentInput;
use Webconsulting\AgentNexus\Ucp\Agent\ShoppingAgent;

/**
 * POST {api}/ucp/agent — the shopping agent, as an AG-UI 1.0 endpoint.
 *
 * The body is a RunAgentInput, the response a Server-Sent-Events stream of
 * AG-UI events. The checkout widget and the backend console both talk to it;
 * so can any AG-UI client. A body that is not a RunAgentInput, or a client
 * over its rate limit, gets a stream holding a single RUN_ERROR with a 400 or
 * 429 status.
 *
 * Prompt and model settings are never read from the request: when a widget
 * names its content element, that element's FlexForm is loaded server side.
 */
#[Autoconfigure(public: true)]
final readonly class AgentEndpoint
{
    public const string RATE_BUCKET = 'ucp.agent';
    public const int RATE_LIMIT = 15;
    public const int RATE_WINDOW = 600;

    /** Pacing between frames, so a person can follow the agent working. */
    private const int FRAME_DELAY_MS = 40;

    public function __construct(
        private ShoppingAgent $agent,
        private WidgetContext $widgetContext,
        private PluginSettings $pluginSettings,
        private RateLimiter $rateLimiter,
        private RouteRegistry $routes,
        private ExtensionSettings $settings,
    ) {}

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        if (!$match instanceof RouteMatch) {
            throw new \LogicException('The UCP shopping agent is served through the API router only.', 1758700901);
        }
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        $capture = $capture instanceof TrafficCapture ? $capture : null;

        if (!$this->rateLimiter->passes($request, self::RATE_BUCKET, self::RATE_LIMIT, self::RATE_WINDOW)) {
            return $this->refuse(429, 'Too many runs. Try again in a few minutes.', 'rate_limited', ['Retry-After' => (string)self::RATE_WINDOW]);
        }
        try {
            $input = AgentInput::fromBody((string)$request->getBody(), $this->widgetContext);
        } catch (InvalidAgentInput $e) {
            return $this->refuse(400, $e->getMessage(), 'invalid_input');
        }

        $capture?->describe($input->resume === [] ? 'RunAgent' : 'ResumeRun');
        $capture?->via($input->widget !== null ? Channel::Widget : Channel::Api);

        $settings = $input->widget !== null
            ? $this->pluginSettings->forContentElement($input->widget['ce'], 'agentnexus_checkout')
            : [];
        $modelAllowed = !in_array($settings['use_llm'] ?? '1', ['0', 0, false], true);

        $session = new AgentSession(
            $match->origin,
            $match->apiBaseUrl,
            $this->routes->url('ucp.platform.profile', $match->origin),
            new InProcessCaller(
                Channel::Agent,
                $input->widget !== null ? $this->widgetContext->storagePid($input->widget['page']) : 0,
                $input->threadId,
            ),
            $capture,
            $modelAllowed,
            $this->settings->trafficRedactPersonalData(),
            $request,
        );

        return EventStream::response($this->agent->run($input, $session), 'ucp shopping agent', self::FRAME_DELAY_MS);
    }

    /**
     * @param array<string, string> $headers
     */
    private function refuse(int $status, string $message, string $code, array $headers = []): ResponseInterface
    {
        $response = EventStream::response([AgUi::runError($message, $code)], 'ucp shopping agent')->withStatus($status);
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
