<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Eid;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\AgentNexus\Shared\Http\PluginSettings;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Ucp\Service\CheckoutRunner;
use Webconsulting\AgentNexus\Ucp\Service\OrderLogger;
use Webconsulting\AgentNexus\Ucp\Service\OrderStore;
use Webconsulting\AgentNexus\Ucp\Service\SseEncoder;

/**
 * Public (frontend) UCP checkout endpoint for the Agent Checkout content element
 * — also the manifest's advertised `checkout` endpoint.
 *
 * Streams the agent-driven checkout state machine over SSE. On an approved
 * authorization it records the SIMULATED order. Rate-limited. No payment is ever
 * taken — `reallyApply` is off by default and even when on the order stays
 * simulated unless a concrete payment integration is wired in.
 */
final class CheckoutEndpoint
{
    private const RATE_LIMIT = 25;
    private const RATE_LIMIT_LLM = 10;
    private const RATE_WINDOW = 600;

    public function run(ServerRequestInterface $request): ResponseInterface
    {
        $input = json_decode((string)$request->getBody(), true);
        $input = is_array($input) ? $input : [];
        unset($input['_settings'], $input['_llm'], $input['_freeIntent']);

        $rateLimiter = GeneralUtility::makeInstance(RateLimiter::class);
        if (!$rateLimiter->passes($request, 'ucp', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return new JsonResponse(['error' => 'Too many requests.'], 429);
        }

        // Model-written rationale only on propose runs the element allows,
        // within the shared guard and a tighter rate bucket. The free-text
        // wish (if the widget sent one) grounds the rationale.
        $settings = GeneralUtility::makeInstance(PluginSettings::class)
            ->forContentElement((int)($input['ce'] ?? 0), 'agentnexus_checkout');
        $input['_freeIntent'] = is_string($input['wish'] ?? null) ? $input['wish'] : '';
        $input['_llm'] = !is_array($input['authorization'] ?? null)
            && (string)($settings['use_llm'] ?? '1') !== '0'
            && GeneralUtility::makeInstance(LlmGuard::class)->allows('ucp')['allowed']
            && $rateLimiter->passes($request, 'ucp', self::RATE_LIMIT_LLM, self::RATE_WINDOW, 'llm');

        $runner = GeneralUtility::makeInstance(CheckoutRunner::class);
        $encoder = GeneralUtility::makeInstance(SseEncoder::class);
        $logger = GeneralUtility::makeInstance(OrderLogger::class);
        $store = GeneralUtility::makeInstance(OrderStore::class);

        $intent = is_string($input['intent'] ?? null) ? $input['intent'] : 'pro';
        $authorization = is_array($input['authorization'] ?? null) ? $input['authorization'] : null;
        $contact = is_array($authorization['contact'] ?? null) ? $authorization['contact'] : [];
        $page = (int)($input['page'] ?? 0);
        $url = (string)($input['url'] ?? '');

        $count = 0;
        $orderId = '';
        $total = 0;
        $itemCount = 0;
        $finalState = 'review';
        $cart = [];

        $events = (function () use ($runner, $input, &$count, &$orderId, &$total, &$itemCount, &$finalState, &$cart): \Generator {
            foreach ($runner->run($input, 'frontend') as $event) {
                $count++;
                $type = $event['type'] ?? '';
                if ($type === 'checkout.started') {
                    $orderId = (string)($event['orderId'] ?? '');
                } elseif ($type === 'cart.updated') {
                    $cart = $event['items'] ?? [];
                    $itemCount = count($cart);
                    $total = (int)($event['totalCents'] ?? 0);
                } elseif ($type === 'authorization.required') {
                    $finalState = 'authorization_required';
                } elseif ($type === 'order.confirmed') {
                    $finalState = 'confirmed';
                    $order = is_array($event['order'] ?? null) ? $event['order'] : [];
                    $total = (int)($order['totalCents'] ?? $total);
                    if ($cart === [] && isset($order['items']) && is_array($order['items'])) {
                        $cart = $order['items'];
                        $itemCount = count($cart);
                    }
                } elseif ($type === 'order.declined') {
                    $finalState = 'declined';
                }
                yield $event;
            }
        })();

        register_shutdown_function(static function () use ($logger, $store, &$orderId, $intent, &$finalState, &$itemCount, &$total, &$count, &$cart, $contact, $page, $url): void {
            $logger->log(OrderLogger::SOURCE_FRONTEND, $orderId, $intent, $finalState, $itemCount, $total, $count, 0);
            if ($finalState === 'confirmed') {
                $store->store($page, $url, $orderId, $intent, $total, $cart, $contact);
            }
        });

        $encoder->stream($events, 60);
    }

}
