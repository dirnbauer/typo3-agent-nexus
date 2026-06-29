<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Eid;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\AgentNexus\Ap2\Service\AuthorizationStore;
use Webconsulting\AgentNexus\Ap2\Service\MandateLog;
use Webconsulting\AgentNexus\Ap2\Service\MandateService;

/**
 * Public (frontend) endpoint for the Trusted Surface content element.
 *
 * The visitor authorizes a purchase: this mints an Intent Mandate (their spending
 * cap) and a Cart Mandate (the exact cart), walks the authorization chain, and
 * returns the verified result with the signed mandate tokens. Rate-limited.
 *
 * SANDBOX: tokens are demo-signed and no real payment is initiated.
 */
final class AuthorizeEndpoint
{
    private const RATE_LIMIT = 30;
    private const RATE_WINDOW = 600;

    public function authorize(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string)$request->getBody(), true);
        $body = is_array($body) ? $body : [];

        if (!$this->passesRateLimit($request)) {
            return new JsonResponse(['error' => 'Too many requests.'], 429);
        }

        $cart = is_array($body['cart'] ?? null) ? $body['cart'] : [];
        $capCents = (int)($body['capCents'] ?? 50000);
        $merchant = (string)($cart['merchant'] ?? 'desiderio-store');
        $page = (int)($body['page'] ?? 0);
        $url = (string)($body['url'] ?? '');

        $mandates = GeneralUtility::makeInstance(MandateService::class);

        $intent = $mandates->mintIntentMandate([
            'maxAmountCents' => $capCents,
            'currency' => (string)($cart['currency'] ?? 'EUR'),
            'merchants' => [$merchant],
            'humanPresent' => true,
        ]);
        $cartMandate = $mandates->mintCartMandate($cart, (string)$intent['claims']['jti']);
        $chain = $mandates->verifyChain($intent['jwt'], $cartMandate['jwt']);

        $total = (int)($cart['totalCents'] ?? 0);
        GeneralUtility::makeInstance(MandateLog::class)->log(MandateLog::SOURCE_FRONTEND, 'verify', 'chain', (bool)$chain['authorized'], $total, 0);
        GeneralUtility::makeInstance(AuthorizationStore::class)->store(
            $page,
            $url,
            (string)($intent['claims']['jti'] ?? ''),
            (string)($cartMandate['claims']['jti'] ?? ''),
            (bool)$chain['authorized'],
            $total,
            (array)($cart['items'] ?? []),
        );

        return new JsonResponse([
            'authorized' => $chain['authorized'],
            'checks' => $chain['checks'],
            'intentJwt' => $intent['jwt'],
            'cartJwt' => $cartMandate['jwt'],
            'intentClaims' => $intent['claims'],
            'cartClaims' => $cartMandate['claims'],
            'simulated' => true,
        ]);
    }

    private function passesRateLimit(ServerRequestInterface $request): bool
    {
        try {
            $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('ap2');
        } catch (\Throwable) {
            return true;
        }
        $ip = (string)($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');
        $key = 'rl_' . sha1($ip);
        $count = (int)$cache->get($key);
        if ($count >= self::RATE_LIMIT) {
            return false;
        }
        $cache->set($key, $count + 1, [], self::RATE_WINDOW);
        return true;
    }
}
