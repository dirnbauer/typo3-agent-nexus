<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Eid;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\AgentNexus\Ap2\Service\AuthorizationStore;
use Webconsulting\AgentNexus\Ap2\Service\MandateLog;
use Webconsulting\AgentNexus\Ap2\Service\MandateService;
use Webconsulting\AgentNexus\Shared\Http\PluginSettings;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Llm\LlmClient;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Llm\LlmUsageTracker;

/**
 * Public (frontend) endpoint for the Trusted Surface content element.
 *
 * The visitor authorizes a purchase: this mints an Intent Mandate (their spending
 * cap) and a Cart Mandate (the exact cart), walks the authorization chain, and
 * returns the verified result with the signed mandate tokens. Rate-limited.
 * Mandates and verification are ALWAYS deterministic; the only optional model
 * output is a plain-language explanation of the verified chain (off by default).
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

        if (!GeneralUtility::makeInstance(RateLimiter::class)->passes($request, 'ap2', self::RATE_LIMIT, self::RATE_WINDOW)) {
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
            'explanation' => $this->explainChain($body, $chain, $capCents, $total),
            'simulated' => true,
        ]);
    }

    /**
     * Optional model-written plain-language explanation of the verified chain
     * (element opt-in AND ap2LlmEnabled, which defaults to off). Null when
     * disabled or on any failure — the receipt is complete without it.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $chain
     */
    private function explainChain(array $body, array $chain, int $capCents, int $totalCents): ?string
    {
        $settings = GeneralUtility::makeInstance(PluginSettings::class)
            ->forContentElement((int)($body['ce'] ?? 0), 'agentnexus_trustedsurface');
        if ((string)($settings['use_llm_explainer'] ?? '0') !== '1'
            || !GeneralUtility::makeInstance(LlmGuard::class)->allows('ap2')['allowed']
        ) {
            return null;
        }

        try {
            $client = GeneralUtility::makeInstance(LlmClient::class);
            $completion = $client->completeText(
                'Explain an AP2 mandate verification to a website visitor in 2 short plain-text sentences. '
                . 'No jargon beyond "spending cap" and "signature"; use only this data: '
                . json_encode([
                    'authorized' => (bool)$chain['authorized'],
                    'checks' => $chain['checks'],
                    'capCents' => $capCents,
                    'cartTotalCents' => $totalCents,
                ], JSON_UNESCAPED_SLASHES),
                'Explain the result.',
                null,
                120,
            );
            $text = trim($completion['text']);
            if ($text === '') {
                return null;
            }
            GeneralUtility::makeInstance(LlmUsageTracker::class)->record(
                'ap2',
                LlmUsageTracker::SOURCE_FRONTEND,
                'default',
                (int)$completion['promptTokens'],
                (int)$completion['completionTokens'],
                $completion['cost'] !== null ? (float)$completion['cost'] : null,
            );
            return $text;
        } catch (\Throwable) {
            return null;
        }
    }
}
