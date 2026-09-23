<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\AgentNexus\Ap2\Crypto\CryptoException;
use Webconsulting\AgentNexus\Ap2\Crypto\Json;
use Webconsulting\AgentNexus\Ap2\Sandbox\AutonomousFlow;
use Webconsulting\AgentNexus\Ap2\Sandbox\KeyRing;
use Webconsulting\AgentNexus\Ap2\Sandbox\Parties;
use Webconsulting\AgentNexus\Ap2\Sandbox\RecordingContext;
use Webconsulting\AgentNexus\Ap2\Service\MandateExplainer;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Http\WidgetContext;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;
use Webconsulting\AgentNexus\Shared\Traffic\TrafficCapture;

/**
 * Handlers of the AP2 routes ({@see Ap2Routes}).
 *
 * `POST /ap2/authorize` is the Trusted Surface demo. The body names a spending
 * cap in cents and, optionally, the scenario ("within" or "over" the cap) and
 * the merchant the person allows:
 *
 *     {"capCents": 50000, "intent": "over", "agentNexus": {"ce": 12, "page": 3, "url": "…"}}
 *
 * Every role runs in this request with its sandbox key; the answer carries
 * every artefact, every check and both receipts. `simulated` is always true.
 */
#[Autoconfigure(public: true)]
final readonly class Ap2Endpoint
{
    public const int RATE_LIMIT = 30;
    public const int RATE_WINDOW = 600;
    public const int MAX_CAP = 10000000;
    private const int MAX_BODY_BYTES = 16384;

    public function __construct(
        private KeyRing $keys,
        private AutonomousFlow $flow,
        private RateLimiter $rateLimiter,
        private WidgetContext $widgetContext,
        private MandateExplainer $explainer,
    ) {}

    public function jwks(ServerRequestInterface $request): ResponseInterface
    {
        return new JsonResponse($this->keys->jwks(), 200, ['Cache-Control' => 'public, max-age=300']);
    }

    public function authorize(ServerRequestInterface $request): ResponseInterface
    {
        $capture = $request->getAttribute(TrafficCapture::ATTRIBUTE);
        $capture = $capture instanceof TrafficCapture ? $capture : null;

        if (!$this->rateLimiter->passes($request, 'ap2', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return self::error(429, 'Too many requests. Try again in a few minutes.', ['Retry-After' => (string)self::RATE_WINDOW]);
        }

        $raw = (string)$request->getBody();
        try {
            if (strlen($raw) > self::MAX_BODY_BYTES) {
                throw new CryptoException('The request body is too large.', 1758700501);
            }
            $body = Json::decodeObject($raw === '' ? '{}' : $raw);
        } catch (CryptoException) {
            return self::error(400, 'The request body must be a JSON object.');
        }

        $widget = $this->widgetContext->from($body);
        if ($widget === null) {
            $capture?->via(Channel::Api);
        }

        $cap = $body['capCents'] ?? null;
        if (is_string($cap) && ctype_digit($cap)) {
            $cap = (int)$cap;
        }
        if (!is_int($cap) || $cap < 1 || $cap > self::MAX_CAP) {
            return self::error(422, sprintf('capCents must be a whole number of cents from 1 to %d.', self::MAX_CAP));
        }
        $scenario = $body['intent'] ?? AutonomousFlow::WITHIN;
        if (!in_array($scenario, [AutonomousFlow::WITHIN, AutonomousFlow::OVER], true)) {
            return self::error(422, 'intent must be "within" or "over".');
        }
        $merchantId = $body['merchantId'] ?? Parties::MERCHANT['id'];
        if (!is_string($merchantId) || Parties::merchant($merchantId) === null) {
            return self::error(422, sprintf('merchantId must be one of: %s.', implode(', ', array_column(Parties::merchants(), 'id'))));
        }

        $result = $this->flow->run($cap, $scenario, $merchantId, new RecordingContext(
            $widget !== null ? Channel::Widget : Channel::Api,
            $widget !== null ? $this->widgetContext->storagePid($widget['page']) : 0,
        ));
        $capture?->correlate(Json::string($result['chainId'] ?? null));
        $result['explanation'] = $widget !== null ? $this->explainer->explain($request, $widget['ce'], $result) : null;

        return new JsonResponse($result);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function error(int $status, string $message, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $status, 'message' => $message], 'simulated' => true], $status, $headers);
    }
}
