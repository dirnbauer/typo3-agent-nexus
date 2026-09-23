<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Service\AgentService;
use Webconsulting\AgentNexus\A2ui\Service\CatalogueDocument;
use Webconsulting\AgentNexus\A2ui\Service\MessageBuilder;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceService;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteMatch;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Http\WidgetContext;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;

/**
 * The A2UI HTTP binding: plain JSON over HTTP, A2UI messages inside.
 *
 * - `POST /a2ui/surfaces` — a one-line request in, the ordered messages that
 *   create the surface out (`{"messages": [...], "surfaceId", "version",
 *   "provenance", "notes"}`).
 * - `POST /a2ui/actions` — a renderer-to-agent message in (an action with its
 *   context, the data model in `metadata`), the agent's answer out
 *   (`{"messages": [...]}`, which is the official list wrapper).
 * - `GET /a2ui/catalog` — the versions, catalogues and capabilities this
 *   agent generates.
 *
 * The transport contract of A2UI holds: messages arrive in order, one JSON
 * value each, and metadata travels next to them. Refusals are A2UI error
 * messages with an HTTP status.
 */
#[Autoconfigure(public: true)]
final readonly class A2uiEndpoint
{
    public const string BUCKET = 'a2ui';
    public const string MODEL_BUCKET = 'a2ui.llm';
    public const int LIMIT = 20;
    public const int MODEL_LIMIT = 8;
    public const int WINDOW = 600;

    private const int MAX_SURFACE_REQUEST = 16384;
    private const int MAX_ACTION_REQUEST = 262144;
    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;

    public function __construct(
        private SurfaceService $surfaces,
        private AgentService $agent,
        private CatalogueDocument $catalogue,
        private MessageBuilder $messages,
        private RateLimiter $rateLimiter,
        private WidgetContext $widgetContext,
    ) {}

    public function surfaces(ServerRequestInterface $request): ResponseInterface
    {
        $capture = $this->capture($request, 'createSurface');
        try {
            $this->limit($request);
            $body = JsonBody::read($request, self::MAX_SURFACE_REQUEST);
            if ($this->widgetContext->from($body) !== null) {
                $capture?->via(Channel::Widget);
            }
            $modelAllowed = !$this->agent->mayUseModel(true)
                || $this->rateLimiter->passes($request, self::MODEL_BUCKET, self::MODEL_LIMIT, self::WINDOW);
            $result = $this->surfaces->create($body, true, $modelAllowed);
            $capture?->correlate($result['surfaceId']);
            return new JsonResponse($result, 200, [], self::JSON_FLAGS);
        } catch (A2uiProblem $problem) {
            return $this->problem($problem, $capture);
        }
    }

    public function actions(ServerRequestInterface $request): ResponseInterface
    {
        $capture = $this->capture($request, 'action');
        try {
            $this->limit($request);
            $body = JsonBody::read($request, self::MAX_ACTION_REQUEST);
            if ($this->widgetContext->from($body) !== null) {
                $capture?->via(Channel::Widget);
            }
            if (isset($body['error']) && !isset($body['action'])) {
                $capture?->describe('error');
            }
            $result = $this->surfaces->receive($body);
            $capture?->correlate($result['surfaceId']);
            return new JsonResponse(['messages' => $result['messages']], 200, [], self::JSON_FLAGS);
        } catch (A2uiProblem $problem) {
            return $this->problem($problem, $capture);
        }
    }

    public function catalog(ServerRequestInterface $request): ResponseInterface
    {
        $this->capture($request, 'catalog');
        $match = $request->getAttribute(RouteMatch::ATTRIBUTE);
        return new JsonResponse(
            $this->catalogue->document($match instanceof RouteMatch ? $match->origin : ''),
            200,
            ['Cache-Control' => 'public, max-age=300'],
            self::JSON_FLAGS,
        );
    }

    /**
     * An A2UI error message with the problem's HTTP status.
     */
    public function problem(A2uiProblem $problem, ?TrafficCapture $capture = null): ResponseInterface
    {
        if ($problem->surfaceId !== '') {
            $capture?->correlate($problem->surfaceId);
        }
        $headers = $problem->retryAfter > 0 ? ['Retry-After' => (string)$problem->retryAfter] : [];
        return new JsonResponse(
            $this->messages->error($problem->version ?? A2uiVersion::DEFAULT, $problem->errorCode, $problem->surfaceId, $problem->getMessage(), $problem->path),
            $problem->status,
            $headers,
            self::JSON_FLAGS,
        );
    }

    private function limit(ServerRequestInterface $request): void
    {
        if (!$this->rateLimiter->passes($request, self::BUCKET, self::LIMIT, self::WINDOW)) {
            throw new A2uiProblem(429, 'RATE_LIMITED', 'Too many requests. Wait a few minutes and try again.', retryAfter: self::WINDOW);
        }
    }

    private function capture(ServerRequestInterface $request, string $operation): ?TrafficCapture
    {
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        if (!$capture instanceof TrafficCapture) {
            return null;
        }
        $capture->describe($operation);
        return $capture;
    }
}
