<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\Agui\Event\Events;
use Webconsulting\AgentNexus\Shared\Llm\LlmClient;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Llm\LlmUsageTracker;

/**
 * The "agent": an AG-UI event emitter.
 *
 * It turns a RunAgentInput into a strictly-ordered stream of AG-UI events.
 * On the frontend, the assistant's answer text can come from a real model
 * (nr-llm, soft dependency) streamed chunk by chunk; everything around it —
 * and the whole run when no LLM is available/allowed — stays scripted per
 * task preset so the demo always works without an API key.
 *
 * The human-in-the-loop pause is the centrepiece either way: the first run
 * proposes and ends with a `confirm_apply`/`confirm_booking` tool call; only
 * a second run carrying the user's approval triggers the apply phase. The
 * gate, the apply phase and the lead write are NEVER model-controlled.
 */
final class AgentRunner implements SingletonInterface
{
    public function __construct(
        private readonly Applier $applier,
        private readonly LlmClient $llmClient,
        private readonly LlmGuard $llmGuard,
        private readonly LlmUsageTracker $usageTracker,
    ) {}

    /**
     * @param array<string, mixed> $input RunAgentInput {threadId, runId, preset, messages, state, approval}
     *                                    plus server-injected keys: _settings (FlexForm), _llm (bool)
     * @return \Generator<int, array<string, mixed>>
     */
    public function run(array $input, string $source): \Generator
    {
        $preset = is_string($input['preset'] ?? null) && $input['preset'] !== '' ? $input['preset'] : 'seo';
        $config = $this->presets($source)[$preset] ?? $this->presets($source)['seo'] ?? $this->presets('backend')['seo'];
        $threadId = (string)($input['threadId'] ?? ('t-' . substr(md5($source . $preset), 0, 8)));
        $approval = is_array($input['approval'] ?? null) ? $input['approval'] : null;

        if ($approval !== null) {
            yield from $this->applyPhase($threadId, $preset, $config, $approval);
            return;
        }
        yield from $this->proposePhase($threadId, $config, $source, $input);
    }

    /**
     * @param array<string, mixed> $c preset config
     * @param array<string, mixed> $input full RunAgentInput
     * @return \Generator<int, array<string, mixed>>
     */
    private function proposePhase(string $threadId, array $c, string $source, array $input): \Generator
    {
        $runId = 'r-' . substr(md5($threadId . microtime(false)), 0, 8);
        $intent = trim((string)($input['intent'] ?? ''));
        $settings = is_array($input['_settings'] ?? null) ? $input['_settings'] : [];
        $useLlm = (bool)($input['_llm'] ?? false) && $intent !== '';

        yield Events::runStarted($threadId, $runId);

        // Provenance first, so the UI can label the whole run.
        if ($useLlm) {
            $connection = $this->llmClient->getConnectionInfo();
            $model = $connection['model'] ?? 'default model';
            yield Events::custom('provenance', ['mode' => 'llm', 'model' => $model, 'label' => 'Live model · ' . $model]);
        } else {
            yield Events::custom('provenance', ['mode' => 'scripted', 'model' => '', 'label' => 'Scripted demo']);
        }

        yield Events::stepStarted('analyze');

        // Reasoning (chain-of-thought summary)
        yield Events::reasoningStart();
        foreach ($this->chunks($c['reasoning']) as $part) {
            yield Events::reasoningContent($part);
        }
        yield Events::reasoningEnd();

        // Optional shared-state demo (e.g. the translation preset)
        if (isset($c['state'])) {
            yield Events::stateSnapshot($c['state']);
        }
        if (isset($c['stateDelta'])) {
            yield Events::stateDelta($c['stateDelta']);
        }

        yield Events::stepFinished('analyze');
        yield Events::stepStarted('draft');

        // Assistant text: a real model answer when allowed, the scripted
        // draft otherwise — the event shape is identical.
        $messageId = 'm-' . substr(md5($runId), 0, 6);
        yield Events::textStart($messageId);
        $streamed = $useLlm ? yield from $this->streamLlmAnswer($messageId, $c, $intent, $settings) : false;
        if (!$streamed) {
            foreach ($this->words($c['draft']) as $word) {
                yield Events::textContent($messageId, $word);
            }
        }
        yield Events::textEnd($messageId);
        yield Events::stepFinished('draft');

        // Generative-UI tool call (frontend) — agent picks the widget to render
        if (isset($c['uiTool'])) {
            $uiId = 'tc-ui-' . substr(md5($runId), 0, 5);
            yield Events::toolStart($uiId, $c['uiTool']['name']);
            yield Events::toolArgs($uiId, json_encode($c['uiTool']['args'], JSON_UNESCAPED_SLASHES));
            yield Events::toolEnd($uiId);
        }

        // Human-in-the-loop: propose the change behind a confirm tool, then end
        // the run. Nothing is written — the UI now shows Approve / Reject.
        $toolId = 'tc-' . substr(md5($runId . 'confirm'), 0, 6);
        yield Events::toolStart($toolId, $c['tool']);
        yield Events::toolArgs($toolId, json_encode($c['toolArgs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        yield Events::toolEnd($toolId);

        yield Events::runFinished($threadId, $runId, ['awaiting' => 'approval', 'toolCallId' => $toolId]);
    }

    /**
     * @param array<string, mixed> $c preset config
     * @param array<string, mixed> $approval {toolCallId, decision}
     * @return \Generator<int, array<string, mixed>>
     */
    private function applyPhase(string $threadId, string $preset, array $c, array $approval): \Generator
    {
        $runId = 'r-' . substr(md5($threadId . 'apply' . microtime(false)), 0, 8);
        $decision = (string)($approval['decision'] ?? 'approved');
        $toolCallId = (string)($approval['toolCallId'] ?? 'tc');

        yield Events::runStarted($threadId, $runId);

        if ($decision !== 'approved') {
            yield Events::toolResult('m-rej', $toolCallId, 'rejected');
            $messageId = 'm-' . substr(md5($runId), 0, 6);
            yield Events::textStart($messageId);
            foreach ($this->words('No problem — I discarded that change and wrote nothing.') as $w) {
                yield Events::textContent($messageId, $w);
            }
            yield Events::textEnd($messageId);
            yield Events::runFinished($threadId, $runId, ['updated' => 0, 'decision' => 'rejected']);
            return;
        }

        yield Events::toolResult('m-app', $toolCallId, 'approved');
        yield Events::stepStarted('apply');

        // The write happens ONLY here, after a verified approval.
        $result = $this->applier->apply($preset, is_array($c['toolArgs'] ?? null) ? $c['toolArgs'] : []);

        $messageId = 'm-' . substr(md5($runId), 0, 6);
        yield Events::textStart($messageId);
        foreach ($this->words($c['applyText'] . ($result['simulated'] ? ' (simulated — safe demo mode)' : '')) as $w) {
            yield Events::textContent($messageId, $w);
        }
        yield Events::textEnd($messageId);
        yield Events::stepFinished('apply');
        yield Events::runFinished($threadId, $runId, $result);
    }

    /**
     * Task presets per surface (backend module vs frontend assistant).
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function presets(string $source): array
    {
        $backend = [
            'seo' => [
                'reasoning' => 'The page emphasises flexible team plans and transparent pricing, so the description should lead with the value and a clear call to action, and stay under 160 characters.',
                'draft' => 'Compare flexible team plans with transparent pricing — start free, scale as you grow, and cancel anytime.',
                'tool' => 'confirm_apply',
                'toolArgs' => ['field' => 'description', 'target' => 'page 42 “Pricing”', 'value' => 'Compare flexible team plans with transparent pricing — start free, scale as you grow, and cancel anytime.'],
                'applyText' => 'Done — the meta description was written to the page.',
            ],
            'translate' => [
                'reasoning' => 'Three pages need English titles. I will propose translations but keep them editable, because brand terms sometimes stay in German.',
                'state' => ['targetLang' => 'en', 'pages' => [['uid' => 7, 'title' => 'Über uns'], ['uid' => 8, 'title' => 'Leistungen'], ['uid' => 9, 'title' => 'Kontakt']]],
                'stateDelta' => [
                    ['op' => 'replace', 'path' => '/pages/0/title', 'value' => 'About us'],
                    ['op' => 'replace', 'path' => '/pages/1/title', 'value' => 'Services'],
                    ['op' => 'replace', 'path' => '/pages/2/title', 'value' => 'Contact'],
                ],
                'draft' => 'I translated the three page titles. Review the live state on the right — edit any title before you approve.',
                'tool' => 'confirm_apply',
                'toolArgs' => ['action' => 'translate_titles', 'count' => 3, 'targetLang' => 'en'],
                'applyText' => 'Applied — three page titles were translated to English.',
            ],
            'news' => [
                'reasoning' => 'The brief is about a product launch. I will draft a concise news lede with the date and a quote slot, in the house tone.',
                'draft' => 'Today we launched our redesigned team workspace, bringing real-time collaboration and transparent pricing to growing companies.',
                'tool' => 'confirm_apply',
                'toolArgs' => ['action' => 'create_news', 'table' => 'tx_news_domain_model_news', 'title' => 'New team workspace launches'],
                'applyText' => 'Created — a new news draft is ready for review.',
            ],
        ];

        $frontend = [
            'plan' => [
                'reasoning' => 'They need a plan for 5 people under €50. The Team plan at €39 fits; I will render a comparison card and let them confirm before I capture anything.',
                'draft' => 'For a team of 5 under €50, the Team plan is the best fit at €39 / month. Here is a quick comparison.',
                'uiTool' => ['name' => 'render_plan_card', 'args' => ['recommended' => 'Team', 'plans' => [['name' => 'Starter', 'price' => 0, 'seats' => 2], ['name' => 'Team', 'price' => 39, 'seats' => 5], ['name' => 'Business', 'price' => 79, 'seats' => 15]]]],
                'state' => ['selection' => null],
                'stateDelta' => [['op' => 'replace', 'path' => '/selection', 'value' => 'Team']],
                'tool' => 'confirm_booking',
                'toolArgs' => ['plan' => 'Team', 'price' => 39, 'seats' => 5, 'needs' => ['name', 'email']],
                'applyText' => 'Thanks — your interest in the Team plan was sent to our team.',
            ],
            'support' => [
                'reasoning' => 'They want to book a consultation. I will gather the essentials and confirm before creating the request.',
                'draft' => 'I can set up a consultation. Tell me a good time and I will confirm the details before sending anything.',
                'tool' => 'confirm_booking',
                'toolArgs' => ['action' => 'book_consultation', 'needs' => ['name', 'email', 'preferredTime']],
                'applyText' => 'Done — your consultation request was sent. We will be in touch shortly.',
            ],
        ];

        return $source === 'frontend' ? $frontend : $backend;
    }

    /**
     * Stream a real model answer as TEXT_MESSAGE_CONTENT events.
     *
     * Returns true when at least one chunk was streamed; false lets the
     * caller fall back to the scripted draft. A failure mid-stream degrades
     * gracefully: whatever arrived stays, a short scripted close follows.
     *
     * @param array<string, mixed> $c preset config
     * @param array<string, mixed> $settings FlexForm settings (server-loaded)
     * @return \Generator<int, array<string, mixed>, mixed, bool>
     */
    private function streamLlmAnswer(string $messageId, array $c, string $intent, array $settings): \Generator
    {
        $systemPrompt = trim((string)($settings['llm_system_prompt'] ?? ''));
        if ($systemPrompt === '') {
            $systemPrompt = $this->buildSystemPrompt($c);
        }
        $maxTokens = $this->llmGuard->maxOutputTokens((int)($settings['llm_max_tokens'] ?? 0));

        $text = '';
        try {
            foreach ($this->llmClient->streamText($systemPrompt, $intent, $maxTokens) as $chunk) {
                if ($chunk === '') {
                    continue;
                }
                $text .= $chunk;
                yield Events::textContent($messageId, $chunk);
            }
        } catch (\Throwable) {
            if ($text === '') {
                return false;
            }
            yield Events::textContent($messageId, ' — and to keep things simple, let me hand over to the summary below.');
        }

        if ($text === '') {
            return false;
        }

        $promptTokens = $this->llmClient->estimateTokens($systemPrompt . ' ' . $intent);
        $completionTokens = $this->llmClient->estimateTokens($text);
        $this->usageTracker->record(
            'agui',
            LlmUsageTracker::SOURCE_FRONTEND,
            'default',
            $promptTokens,
            $completionTokens,
            $this->llmClient->estimateCost($promptTokens, $completionTokens),
        );

        return true;
    }

    /**
     * Built-in system prompt embedding the scenario's domain knowledge, used
     * when the FlexForm does not override it.
     *
     * @param array<string, mixed> $c preset config
     */
    private function buildSystemPrompt(array $c): string
    {
        $knowledge = [
            'scenario_summary' => (string)($c['reasoning'] ?? ''),
            'confirm_tool' => (string)($c['tool'] ?? 'confirm_booking'),
            'confirm_details' => $c['toolArgs'] ?? [],
        ];
        if (isset($c['uiTool']['args'])) {
            $knowledge['offer_data'] = $c['uiTool']['args'];
        }

        return 'You are the live assistant on this website, answering one visitor question. '
            . 'Answer helpfully and concretely in 2-4 short sentences of plain text — no markdown, no lists, no links. '
            . 'Ground every claim in this scenario data (never invent prices or features beyond it): '
            . json_encode($knowledge, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ' After your answer the interface offers the visitor an explicit confirmation step; '
            . 'do not tell them to click anything — just end with a sentence that naturally leads toward confirming.';
    }

    /** @return list<string> words with trailing spaces, for token streaming */
    private function words(string $text): array
    {
        $out = [];
        foreach (preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $out[] = $token;
        }
        return $out;
    }

    /** @return list<string> coarse chunks for reasoning streaming */
    private function chunks(string $text): array
    {
        return array_map(static fn(string $s): string => $s . ' ', array_filter(explode(' ', $text), static fn($s) => $s !== ''));
    }
}
