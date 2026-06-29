<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\AgentNexus\Ucp\Service\CheckoutRunner;
use Webconsulting\AgentNexus\Ucp\Service\OrderLogger;
use Webconsulting\AgentNexus\Ucp\Service\SseEncoder;

/**
 * Backend AJAX route target for the UCP Console: accepts a shopping intent (or an
 * authorization decision) and streams the checkout state machine back as SSE.
 * Runs in an authenticated backend context (the AJAX route carries the BE token).
 */
final class CheckoutController
{
    public function __construct(
        private readonly CheckoutRunner $runner,
        private readonly SseEncoder $encoder,
        private readonly OrderLogger $orderLogger,
    ) {}

    public function checkout(ServerRequestInterface $request): ResponseInterface
    {
        $beUser = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        $input = json_decode((string)$request->getBody(), true);
        $input = is_array($input) ? $input : [];

        $intent = is_string($input['intent'] ?? null) ? $input['intent'] : 'pro';

        $count = 0;
        $orderId = '';
        $itemCount = 0;
        $total = 0;
        $finalState = 'review';

        $events = (function () use ($input, &$count, &$orderId, &$itemCount, &$total, &$finalState): \Generator {
            foreach ($this->runner->run($input, 'backend') as $event) {
                $count++;
                $type = $event['type'] ?? '';
                if ($type === 'checkout.started') {
                    $orderId = (string)($event['orderId'] ?? '');
                } elseif ($type === 'cart.updated') {
                    $itemCount = count($event['items'] ?? []);
                    $total = (int)($event['totalCents'] ?? 0);
                } elseif ($type === 'authorization.required') {
                    $finalState = 'authorization_required';
                } elseif ($type === 'order.confirmed') {
                    $finalState = 'confirmed';
                    $order = is_array($event['order'] ?? null) ? $event['order'] : [];
                    $total = (int)($order['totalCents'] ?? $total);
                    $itemCount = $itemCount ?: count($order['items'] ?? []);
                } elseif ($type === 'order.declined') {
                    $finalState = 'declined';
                }
                yield $event;
            }
        })();

        // Log when the stream exhausts.
        register_shutdown_function(function () use (&$orderId, $intent, &$finalState, &$itemCount, &$total, &$count, $beUser): void {
            $this->orderLogger->log(OrderLogger::SOURCE_BACKEND, $orderId, $intent, $finalState, $itemCount, $total, $count, $beUser);
        });

        $this->encoder->stream($events, 70);
    }
}
