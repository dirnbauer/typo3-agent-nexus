<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Service;

use Psr\Http\Message\ServerRequestInterface;
use Webconsulting\AgentNexus\Shared\Http\PluginSettings;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Llm\TruncatedAnswer;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * An optional two-sentence explanation of a verified purchase, written by a
 * language model for the visitor.
 *
 * Off unless the content element asks for it (`use_llm_explainer`, read
 * from its own FlexForm, never from the request) and the `ap2LlmEnabled`
 * extension setting allows it. The model only ever explains results the
 * deterministic verifiers produced; it decides nothing, and any failure just
 * means no explanation.
 */
final readonly class MandateExplainer
{
    private const int RATE_LIMIT = 10;
    private const int RATE_WINDOW = 600;

    public function __construct(
        private PluginSettings $pluginSettings,
        private LlmGuard $guard,
        private LanguageModel $model,
        private UsageLedger $ledger,
        private RateLimiter $rateLimiter,
    ) {}

    /**
     * @param array<string, mixed> $result the flow result
     */
    public function explain(ServerRequestInterface $request, int $contentElement, array $result): ?string
    {
        $settings = $this->pluginSettings->forContentElement($contentElement, 'agentnexus_trustedsurface');
        if ((string)($settings['use_llm_explainer'] ?? '0') !== '1'
            || !$this->guard->allows('ap2')['allowed']
            || !$this->rateLimiter->passes($request, 'ap2.llm', self::RATE_LIMIT, self::RATE_WINDOW)
        ) {
            return null;
        }

        $facts = [
            'authorised' => $result['authorised'] ?? false,
            'summary' => $result['summary'] ?? '',
            'error' => $result['error'] ?? null,
            'cap' => $result['cap'] ?? null,
            'cartTotal' => is_array($result['cart'] ?? null) ? ($result['cart']['total'] ?? null) : null,
            'checks' => $result['verifications'] ?? [],
        ];
        try {
            $completion = $this->model->completeText(
                'You explain an AP2 payment authorisation to a website visitor in two short, plain sentences. '
                . 'Use British English. Mention only facts from the data. Never invent amounts, merchants or results. '
                . 'Amounts are in cents. Data: ' . json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'Explain the result.',
                null,
                $this->guard->maxOutputTokens(Protocol::Ap2),
            );
        } catch (TruncatedAnswer $truncated) {
            // No explanation beats half of one; the spend still counts.
            $this->ledger->record('ap2', UsageLedger::SOURCE_FRONTEND, $truncated->model, $truncated->promptTokens, $truncated->completionTokens, $truncated->cost);
            return null;
        } catch (\Throwable) {
            return null;
        }
        $text = trim($completion['text']);
        if ($text === '') {
            return null;
        }
        $this->ledger->record('ap2', UsageLedger::SOURCE_FRONTEND, $completion['model'], $completion['promptTokens'], $completion['completionTokens'], $completion['cost']);
        return mb_substr($text, 0, 600);
    }
}
