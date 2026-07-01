<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Shared\Llm\LlmClient;
use Webconsulting\AgentNexus\Shared\Llm\LlmUsageTracker;
use Webconsulting\AgentNexus\Ucp\Protocol\Events;

/**
 * The shopping agent: a UCP checkout executor.
 *
 * It turns a shopping intent into a streamed commerce state machine — discover,
 * assemble a cart, review, then PAUSE at `authorization.required`. Nothing is
 * "purchased" until a human authorizes it, and even then the order is SIMULATED
 * by default (no payment is taken). The split mirrors a propose → authorize →
 * confirm flow: the first run proposes the order; a second run carrying the
 * human's authorization confirms it.
 *
 * Cart contents, prices and totals are ALWAYS deterministic — a model never
 * invents commerce numbers. The only model-written piece (when allowed) is the
 * agent's recommendation rationale, grounded in the fixed cart data.
 */
final class CheckoutRunner implements SingletonInterface
{
    public function __construct(
        private readonly Merchant $merchant,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LlmClient $llmClient,
        private readonly LlmUsageTracker $usageTracker,
    ) {}

    /**
     * @param array<string, mixed> $input {intent, orderId?, authorization?, contact?} plus server-injected _llm/_freeIntent
     * @return \Generator<int, array<string, mixed>>
     */
    public function run(array $input, string $source): \Generator
    {
        $intent = is_string($input['intent'] ?? null) && $input['intent'] !== '' ? $input['intent'] : 'pro';
        $config = $this->intents()[$intent] ?? $this->intents()['pro'];
        $orderId = is_string($input['orderId'] ?? null) && $input['orderId'] !== ''
            ? $input['orderId']
            : 'ord-' . substr(md5($source . $intent . microtime(false)), 0, 10);
        $authorization = is_array($input['authorization'] ?? null) ? $input['authorization'] : null;

        $items = $this->buildCart($config['items']);
        $total = array_sum(array_map(static fn($i) => (int)$i['price'] * (int)$i['qty'], $items));
        $order = ['orderId' => $orderId, 'items' => $items, 'totalCents' => $total, 'currency' => Merchant::CURRENCY];

        if ($authorization !== null) {
            yield from $this->confirmPhase($orderId, $order, $authorization);
            return;
        }
        yield from $this->proposePhase($orderId, $config, $items, $total, $order, (bool)($input['_llm'] ?? false), mb_substr(trim((string)($input['_freeIntent'] ?? '')), 0, 600));
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $order
     * @return \Generator<int, array<string, mixed>>
     */
    private function proposePhase(string $orderId, array $config, array $items, int $total, array $order, bool $useLlm = false, string $freeIntent = ''): \Generator
    {
        yield Events::started($orderId, (string)$config['label']);
        yield Events::step('discovering', 'Reading the merchant manifest and catalog.');
        yield Events::reasoning($this->reasoningText($config, $items, $total, $useLlm, $freeIntent));
        yield Events::step('building_cart');
        yield Events::cart($orderId, $items, $total, Merchant::CURRENCY);
        yield Events::step('review', 'Cart assembled — ready for your authorization.');
        // The human-in-the-loop gate: nothing is purchased yet.
        yield Events::authorizationRequired($orderId, $order);
    }

    /**
     * The agent's recommendation rationale: model-written and grounded in the
     * fixed cart when allowed, scripted otherwise. Never touches numbers.
     *
     * @param array<string, mixed> $config
     * @param array<int, array<string, mixed>> $items
     */
    private function reasoningText(array $config, array $items, int $total, bool $useLlm, string $freeIntent): string
    {
        if (!$useLlm) {
            return (string)$config['reasoning'];
        }
        try {
            $money = static fn(int $cents): string => number_format($cents / 100, 2, '.', ' ') . ' ' . Merchant::CURRENCY;
            $cart = array_map(static fn(array $i): array => [
                'name' => $i['name'] ?? '',
                'qty' => $i['qty'] ?? 1,
                'price' => $money((int)($i['price'] ?? 0)),
            ], $items);
            $completion = $this->llmClient->completeText(
                'You are a shopping agent explaining a package recommendation on a website. '
                . 'In exactly 2 short sentences of plain text, explain why this cart fits the shopper. '
                . 'Use ONLY this data and quote prices exactly as written, never invent products or amounts: '
                . json_encode(['scenario' => (string)$config['label'], 'cart' => $cart, 'total' => $money($total)], JSON_UNESCAPED_SLASHES),
                $freeIntent !== '' ? $freeIntent : (string)$config['label'],
                null,
                160,
            );
            if (trim($completion['text']) !== '') {
                $this->usageTracker->record(
                    'ucp',
                    LlmUsageTracker::SOURCE_FRONTEND,
                    'default',
                    (int)$completion['promptTokens'],
                    (int)$completion['completionTokens'],
                    $completion['cost'] !== null ? (float)$completion['cost'] : null,
                );
                return trim($completion['text']);
            }
        } catch (\Throwable) {
            // fall through to the scripted rationale
        }
        return (string)$config['reasoning'];
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $authorization {decision, contact?}
     * @return \Generator<int, array<string, mixed>>
     */
    private function confirmPhase(string $orderId, array $order, array $authorization): \Generator
    {
        $decision = (string)($authorization['decision'] ?? 'approved');
        if ($decision !== 'approved') {
            yield Events::step('declined');
            yield Events::declined($orderId);
            return;
        }

        // A simulated payment-authorization mandate (AP2 would mint a real one).
        $mandate = 'mandate-sim-' . substr(md5($orderId . 'auth'), 0, 12);
        yield Events::step('authorizing', 'Creating a (simulated) payment authorization mandate.');
        yield Events::authorized($orderId, $mandate);

        $simulated = !$this->reallyApply();
        yield Events::step($simulated ? 'placing_simulated' : 'placing');
        $order['mandate'] = $mandate;
        $order['placedAt'] = 'now';
        yield Events::confirmed($orderId, $order, $simulated);
    }

    /**
     * @param array<int, string> $ids
     * @return array<int, array<string, mixed>>
     */
    private function buildCart(array $ids): array
    {
        $items = [];
        foreach ($ids as $id) {
            $p = $this->merchant->product($id);
            if ($p !== []) {
                $items[] = ['id' => $p['id'], 'name' => $p['name'], 'price' => (int)$p['price'], 'unit' => $p['unit'], 'qty' => 1];
            }
        }
        return $items;
    }

    private function reallyApply(): bool
    {
        try {
            $settings = (array)$this->extensionConfiguration->get('agent_nexus');
            return (bool)($settings['ucpReallyApply'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Shopping intents → which products the agent recommends.
     *
     * @return array<string, array<string, mixed>>
     */
    private function intents(): array
    {
        return [
            'pro' => [
                'label' => 'Set me up with Pro',
                'reasoning' => 'A single team on Pro gets priority support and early element drops — the best value for most teams. I will add the Pro licence.',
                'items' => ['pro-license'],
            ],
            'agency' => [
                'label' => 'Full agency kit',
                'reasoning' => 'For an agency running many projects, the Agency bundle plus a guided onboarding gets the team productive fastest. I will add both.',
                'items' => ['agency-bundle', 'onboarding-addon'],
            ],
            'support' => [
                'label' => 'Add priority support',
                'reasoning' => 'You want a faster response SLA without changing tiers. The Priority Support Pack is the right add-on.',
                'items' => ['support-pack'],
            ],
        ];
    }
}
