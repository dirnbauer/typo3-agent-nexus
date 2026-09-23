<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Http\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Routing\Route as BackendRoute;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Webconsulting\AgentNexus\Shared\Protocol;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficRecorder;

/**
 * Records the backend consoles' protocol calls in the traffic log.
 *
 * An AJAX route opts in with an `agentnexus` option in
 * Configuration/Backend/AjaxRoutes.php:
 *
 *     'agentnexus' => ['protocol' => 'agui', 'operation' => 'RunAgent'],
 *
 * Routes without it — the traffic log's own live poll, for one — are never
 * recorded.
 */
final readonly class BackendTrafficRecorder implements MiddlewareInterface
{
    public function __construct(
        private TrafficRecorder $recorder,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute('route');
        $option = $route instanceof BackendRoute ? $route->getOption('agentnexus') : null;
        $protocol = is_array($option) && is_string($option['protocol'] ?? null) ? Protocol::tryFrom($option['protocol']) : null;
        if ($protocol === null || !$route instanceof BackendRoute) {
            return $handler->handle($request);
        }

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $capture = $this->recorder->start(
            $request,
            $protocol,
            Channel::Backend,
            strtoupper($request->getMethod()) . ' ' . $route->getPath(),
            $backendUser instanceof BackendUserAuthentication ? (int)($backendUser->user['uid'] ?? 0) : 0,
        );
        if ($capture === null) {
            return $handler->handle($request);
        }
        $capture->describe(is_string($option['operation'] ?? null) ? $option['operation'] : '');

        try {
            $response = $handler->handle($request->withAttribute(TrafficCapture::ATTRIBUTE, $capture));
        } catch (\Throwable $e) {
            $capture->fail($e->getMessage());
            throw $e;
        }
        return $this->recorder->finish($capture, $response);
    }
}
