<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\AgentNexus\Ap2\Service\MandateLog;
use Webconsulting\AgentNexus\Ap2\Service\MandateService;

/**
 * Backend AJAX targets for the Mandate Studio.
 *
 * - mint:   mint an Intent or Cart Mandate (returns the signed token + claims).
 * - verify: verify a single token, or walk an Intent → Cart authorization chain.
 *
 * Runs in an authenticated backend context. Everything is sandbox-signed; no real
 * payment is ever initiated.
 */
final class MandateController
{
    public function __construct(
        private readonly MandateService $mandates,
        private readonly MandateLog $mandateLog,
    ) {}

    public function mint(ServerRequestInterface $request): ResponseInterface
    {
        $beUser = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $body = $this->body($request);
        $step = (string)($body['step'] ?? 'intent');

        if ($step === 'cart') {
            $cart = is_array($body['cart'] ?? null) ? $body['cart'] : [];
            $intentRef = (string)($body['intentRef'] ?? '');
            $result = $this->mandates->mintCartMandate($cart, $intentRef);
            $this->mandateLog->log(MandateLog::SOURCE_BACKEND, 'mint', 'CartMandate', false, (int)($result['claims']['cart']['totalCents'] ?? 0), $beUser);
        } else {
            $constraints = is_array($body['constraints'] ?? null) ? $body['constraints'] : [];
            $result = $this->mandates->mintIntentMandate($constraints);
            $this->mandateLog->log(MandateLog::SOURCE_BACKEND, 'mint', 'IntentMandate', false, (int)($result['claims']['constraints']['maxAmountCents'] ?? 0), $beUser);
        }

        return new JsonResponse(['jwt' => $result['jwt'], 'claims' => $result['claims']]);
    }

    public function verify(ServerRequestInterface $request): ResponseInterface
    {
        $beUser = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $body = $this->body($request);

        $intentJwt = (string)($body['intentJwt'] ?? '');
        $cartJwt = (string)($body['cartJwt'] ?? '');

        if ($intentJwt !== '' && $cartJwt !== '') {
            $result = $this->mandates->verifyChain($intentJwt, $cartJwt);
            $this->mandateLog->log(MandateLog::SOURCE_BACKEND, 'verify', 'chain', (bool)$result['authorized'], (int)($result['cart']['cart']['totalCents'] ?? 0), $beUser);
            return new JsonResponse($result);
        }

        // Single-token inspection (e.g. after the operator tampers with a token).
        $single = $this->mandates->inspect((string)($body['jwt'] ?? ''));
        $this->mandateLog->log(MandateLog::SOURCE_BACKEND, 'verify', (string)($single['claims']['typ'] ?? 'token'), (bool)$single['valid'], 0, $beUser);
        return new JsonResponse($single);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $body = json_decode((string)$request->getBody(), true);
        return is_array($body) ? $body : [];
    }
}
