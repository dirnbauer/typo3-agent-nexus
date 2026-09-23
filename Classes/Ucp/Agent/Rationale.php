<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Webconsulting\AgentNexus\Shared\Http\RateLimiter;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Llm\TruncatedAnswer;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * Why the agent picked these products: two sentences for the visitor.
 *
 * A language model may write them when every gate allows it — the element's
 * own setting, {@see LlmGuard} (installed, enabled for UCP, within the daily
 * budget) and the `ucp.llm` rate limit. The model is only ever given facts
 * that are already formatted (titles, "€49.00", "per month"); it never sees a
 * number it could compute with. Its answer is thrown away if it mentions a
 * number that is not in those facts, and every failure falls back to the
 * scripted text of the {@see Intent}.
 */
final readonly class Rationale
{
    public const string RATE_BUCKET = 'ucp.llm';
    public const int RATE_LIMIT = 10;
    public const int RATE_WINDOW = 600;

    private const string SYSTEM_PROMPT = 'You are a shopping agent on a website. In two short sentences of plain British English, '
        . 'tell the visitor ("you") why these products fit their wish. Use only the facts below. '
        . 'Do not write any price, amount or number that is not in the facts. No lists, no exclamation marks. Facts: ';

    public function __construct(
        private LanguageModel $model,
        private LlmGuard $guard,
        private UsageLedger $ledger,
        private RateLimiter $rateLimiter,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<array{title: string, price: string, billing: string, description: string}> $products
     * @return array{text: string, mode: string, label: string, model: string, usage: list<array<string, int|string>>, reason?: string}
     */
    public function explain(Intent $intent, array $products, string $total, string $wish, bool $modelAllowed, ?ServerRequestInterface $request): array
    {
        $scripted = ['text' => $intent->rationale(), 'mode' => 'scripted', 'label' => 'Scripted demo', 'model' => '', 'usage' => []];
        if (!$modelAllowed || $request === null || !$this->guard->allows('ucp')['allowed']) {
            return $scripted;
        }
        if (!$this->rateLimiter->passes($request, self::RATE_BUCKET, self::RATE_LIMIT, self::RATE_WINDOW)) {
            return $scripted;
        }

        try {
            $facts = json_encode(
                ['wish' => $intent->label(), 'products' => $products, 'total' => $total],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            $completion = $this->model->completeText(
                self::SYSTEM_PROMPT . $facts,
                $wish !== '' ? $wish : $intent->label(),
                null,
                $this->guard->maxOutputTokens(Protocol::Ucp),
            );
        } catch (TruncatedAnswer $truncated) {
            // Half a sentence would read as a broken agent: the script answers, and says why.
            $this->ledger->record('ucp', UsageLedger::SOURCE_FRONTEND, $truncated->model, $truncated->promptTokens, $truncated->completionTokens, $truncated->cost);
            return $scripted + ['reason' => $truncated->reason()];
        } catch (\Throwable $e) {
            $this->logger->warning('The UCP rationale model call failed; using the scripted text.', ['exception' => $e]);
            return $scripted;
        }

        $this->ledger->record(
            'ucp',
            UsageLedger::SOURCE_FRONTEND,
            $completion['model'],
            $completion['promptTokens'],
            $completion['completionTokens'],
            $completion['cost'],
        );

        $text = trim(preg_replace('/\s+/u', ' ', $completion['text']) ?? $completion['text']);
        if ($text === '' || mb_strlen($text) > 500 || !self::onlyKnownNumbers($text, $facts)) {
            return $scripted;
        }

        $usage = ['provider' => $this->model->getConnectionInfo()['provider'] ?? 'unknown', 'model' => $completion['model']];
        if ($completion['promptTokens'] > 0 && $completion['completionTokens'] > 0) {
            $usage += [
                'inputTokens' => $completion['promptTokens'],
                'outputTokens' => $completion['completionTokens'],
                'totalTokens' => $completion['promptTokens'] + $completion['completionTokens'],
            ];
        }
        return [
            'text' => $text,
            'mode' => 'llm',
            'label' => 'Live model · ' . $completion['model'],
            'model' => $completion['model'],
            'usage' => [$usage],
        ];
    }

    /**
     * True when every number in the text also appears in the facts — the
     * guard that keeps a model from inventing a price.
     */
    public static function onlyKnownNumbers(string $text, string $facts): bool
    {
        preg_match_all('/\d+(?:[.,]\d+)*/', $text, $matches);
        foreach ($matches[0] as $number) {
            if (!str_contains($facts, $number)) {
                return false;
            }
        }
        return true;
    }
}
