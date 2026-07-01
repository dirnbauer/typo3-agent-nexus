<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\A2a\Protocol\Frames;
use Webconsulting\AgentNexus\Shared\Llm\LlmClient;
use Webconsulting\AgentNexus\Shared\Llm\LlmUsageTracker;

/**
 * The site's A2A agent: a task executor.
 *
 * It turns an incoming A2A message into a strictly-ordered stream of JSON-RPC
 * frames that walk a Task through its lifecycle:
 *
 *   submitted → working → (input-required → … → working) → completed
 *
 * producing an Artifact along the way. Skills whose `inputPrompt` is set pause in
 * `input-required` and ask the caller for one more detail before finishing — the
 * cooperative loop that makes A2A more than fire-and-forget.
 *
 * On the frontend a real model (nr-llm, soft dependency) can take over the two
 * content decisions — which skill to route to (with a visible rationale) and
 * the artifact text — while the lifecycle frames stay identical for every
 * client. Keyword routing and scripted artifacts remain the always-working
 * fallback.
 */
final class TaskRunner implements SingletonInterface
{
    public function __construct(
        private readonly SkillCatalog $skillCatalog,
        private readonly LlmClient $llmClient,
        private readonly LlmUsageTracker $usageTracker,
    ) {}

    /**
     * @param array<string, mixed> $params A2A MessageSendParams (`{message:{…}}`)
     *                                     plus server-injected _settings/_llm
     * @return \Generator<int, array<string, mixed>>
     */
    public function run(array $params, string $source, int|string $rpcId = 1): \Generator
    {
        $message = is_array($params['message'] ?? null) ? $params['message'] : [];
        $metadata = is_array($message['metadata'] ?? null) ? $message['metadata'] : [];
        $settings = is_array($params['_settings'] ?? null) ? $params['_settings'] : [];
        $useLlm = (bool)($params['_llm'] ?? false);
        $text = mb_substr($this->textOf($message), 0, 600);

        $routing = null;
        $skillId = is_string($metadata['skill'] ?? null) && $metadata['skill'] !== ''
            ? $metadata['skill']
            : null;
        if ($skillId === null && $useLlm && $text !== '') {
            $routing = $this->routeWithLlm($text);
            $skillId = $routing['skill'] ?? null;
        }
        $skillId ??= $this->inferSkill($text);
        $skill = $this->skillCatalog->get($skillId);

        $resume = ($metadata['resume'] ?? false) === true;
        $input = trim((string)($metadata['input'] ?? ''));

        $taskId = is_string($message['taskId'] ?? null) && $message['taskId'] !== ''
            ? $message['taskId']
            : 'task-' . substr(md5($source . $skillId . microtime(false)), 0, 10);
        $contextId = is_string($message['contextId'] ?? null) && $message['contextId'] !== ''
            ? $message['contextId']
            : 'ctx-' . substr(md5($taskId), 0, 8);

        if (!$resume) {
            yield Frames::result($rpcId, Frames::task($taskId, $contextId));

            // A model-routed task explains itself: the routing rationale rides
            // in the working status so callers can show *why* it routed.
            $workingText = ($routing !== null && ($settings['show_rationale'] ?? '1') !== '0')
                ? sprintf('Routed to “%s” — %s', (string)$skill['name'], $routing['rationale'])
                : (string)$skill['workingText'];
            yield Frames::result($rpcId, Frames::status($taskId, $contextId, 'working', $workingText, false, ['skill' => $skillId]));

            // Cooperative pause: ask the caller for the missing detail, then end
            // THIS turn without a terminal state. The task is not done. The
            // resolved skill travels in the metadata so the resume targets it
            // even when the server picked it (auto-routing).
            if (!empty($skill['inputPrompt'])) {
                yield Frames::result($rpcId, Frames::status($taskId, $contextId, 'input-required', (string)$skill['inputPrompt'], true, ['skill' => $skillId]));
                return;
            }
        } else {
            $ack = (string)($skill['resumeText'] ?? 'Got it — finishing the task now…');
            if ($input !== '') {
                $ack .= ' (for: ' . mb_substr($input, 0, 80) . ')';
            }
            yield Frames::result($rpcId, Frames::status($taskId, $contextId, 'working', $ack));
        }

        // Produce the artifact, streamed chunk by chunk (append). When allowed,
        // a real model writes it for the actual request; the scripted text is
        // the ever-working fallback.
        $artifactText = ($useLlm && ($settings['use_llm'] ?? '1') !== '0')
            ? $this->writeArtifactWithLlm($skill, $text, $input)
            : null;
        $artifactText ??= (string)$skill['artifactText'];

        $artifactId = 'art-' . substr(md5($taskId), 0, 8);
        yield Frames::result($rpcId, Frames::artifactStart($taskId, $artifactId, (string)$skill['artifactName'], (string)$skill['description']));
        foreach ($this->chunks($artifactText) as $chunk) {
            yield Frames::result($rpcId, Frames::artifactChunk($taskId, $artifactId, $chunk));
        }
        yield Frames::result($rpcId, Frames::artifactEnd($taskId, $artifactId));

        yield Frames::result($rpcId, Frames::status($taskId, $contextId, 'completed', (string)$skill['completedText'], true));
    }

    /**
     * Ask the model which catalog skill fits the request. Any malformed or
     * failed answer returns null and keyword routing takes over.
     *
     * @return array{skill: string, rationale: string}|null
     */
    private function routeWithLlm(string $text): ?array
    {
        $catalog = [];
        foreach ($this->skillCatalog->all() as $id => $skill) {
            $catalog[] = ['id' => $id, 'description' => (string)$skill['description']];
        }

        try {
            $completion = $this->llmClient->completeJson(
                'You route requests to exactly one skill of a website agent. Skills: '
                . json_encode($catalog, JSON_UNESCAPED_SLASHES)
                . ' Reply as JSON: {"skill": "<id>", "rationale": "<one short sentence, plain text>"}.',
                $text,
                null,
                160,
            );
            $skillId = (string)($completion['data']['skill'] ?? '');
            if (!array_key_exists($skillId, $this->skillCatalog->all())) {
                return null;
            }
            $this->recordUsage($completion);
            return [
                'skill' => $skillId,
                'rationale' => mb_substr(trim((string)($completion['data']['rationale'] ?? 'best match in the skill catalog')), 0, 240),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Model-written artifact grounded in the skill's scripted example; null on
     * any failure so the scripted artifact ships instead.
     *
     * @param array<string, mixed> $skill
     */
    private function writeArtifactWithLlm(array $skill, string $text, string $input): ?string
    {
        if ($text === '' && $input === '') {
            return null;
        }
        try {
            $completion = $this->llmClient->completeText(
                'You are the "' . (string)$skill['name'] . '" skill of a website agent. '
                . 'Produce the deliverable for the request as concise markdown (no preamble, no code fences), '
                . 'matching the tone and shape of this example deliverable: ' . (string)$skill['artifactText'],
                trim($text . ($input !== '' ? "\nAdditional detail: " . $input : '')),
                null,
                400,
            );
            $this->recordUsage($completion);
            return $completion['text'] !== '' ? $completion['text'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{promptTokens: int, completionTokens: int, cost: ?float} $completion
     */
    private function recordUsage(array $completion): void
    {
        $this->usageTracker->record(
            'a2a',
            LlmUsageTracker::SOURCE_FRONTEND,
            'default',
            (int)$completion['promptTokens'],
            (int)$completion['completionTokens'],
            $completion['cost'] !== null ? (float)$completion['cost'] : null,
        );
    }

    private function textOf(array $message): string
    {
        $parts = is_array($message['parts'] ?? null) ? $message['parts'] : [];
        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && ($part['kind'] ?? '') === 'text') {
                $text .= ' ' . (string)($part['text'] ?? '');
            }
        }
        return trim($text);
    }

    private function inferSkill(string $text): string
    {
        $t = mb_strtolower($text);
        if (str_contains($t, 'email') || str_contains($t, 'outreach') || str_contains($t, 'reach out')) {
            return 'draft_outreach';
        }
        if (str_contains($t, 'onboard') || str_contains($t, 'plan')) {
            return 'plan_onboarding';
        }
        return 'summarize_page';
    }

    /** @return list<string> word chunks with trailing spaces, for streamed artifacts */
    private function chunks(string $text): array
    {
        $out = [];
        foreach (preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $out[] = $token;
        }
        return $out;
    }
}
