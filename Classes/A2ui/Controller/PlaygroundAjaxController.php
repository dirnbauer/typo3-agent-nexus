<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\AgentNexus\A2ui\Http\A2uiEndpoint;
use Webconsulting\AgentNexus\A2ui\Http\A2uiProblem;
use Webconsulting\AgentNexus\A2ui\Http\JsonBody;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceService;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;

/**
 * The playground's two backend routes. They run the same binding as the
 * public endpoints — same request and answer shapes, same protocol objects —
 * as the logged-in editor: no rate limit, no frontend model guard, and the
 * surfaces are recorded with the backend as their source.
 */
#[Autoconfigure(public: true)]
final readonly class PlaygroundAjaxController
{
    private const int MAX_REQUEST = 262144;

    public function __construct(
        private SurfaceService $surfaces,
        private A2uiEndpoint $endpoint,
    ) {}

    public function generate(ServerRequestInterface $request): ResponseInterface
    {
        $capture = $this->capture($request);
        try {
            $result = $this->surfaces->create(JsonBody::read($request, self::MAX_REQUEST), false, true, $this->backendUser());
            $capture?->correlate($result['surfaceId']);
            return new JsonResponse($result, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (A2uiProblem $problem) {
            return $this->endpoint->problem($problem, $capture);
        }
    }

    public function action(ServerRequestInterface $request): ResponseInterface
    {
        $capture = $this->capture($request);
        try {
            $result = $this->surfaces->receive(JsonBody::read($request, self::MAX_REQUEST));
            $capture?->correlate($result['surfaceId']);
            return new JsonResponse(['messages' => $result['messages']], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (A2uiProblem $problem) {
            return $this->endpoint->problem($problem, $capture);
        }
    }

    private function capture(ServerRequestInterface $request): ?TrafficCapture
    {
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        return $capture instanceof TrafficCapture ? $capture : null;
    }

    private function backendUser(): int
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        return $user instanceof BackendUserAuthentication && is_array($user->user) ? (int)($user->user['uid'] ?? 0) : 0;
    }
}
