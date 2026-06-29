<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\A2a\Protocol\Frames;

/**
 * The site's A2A agent: a deterministic task executor.
 *
 * It turns an incoming A2A message into a strictly-ordered stream of JSON-RPC
 * frames that walk a Task through its lifecycle:
 *
 *   submitted → working → (input-required → … → working) → completed
 *
 * producing an Artifact along the way. Skills whose `inputPrompt` is set pause in
 * `input-required` and ask the caller for one more detail before finishing — the
 * cooperative loop that makes A2A more than fire-and-forget. The script is fixed
 * so the demo always works with no API key; a real LLM (nr-llm, a soft
 * dependency) can be slotted behind the same frames without touching any client.
 */
final class TaskRunner implements SingletonInterface
{
    public function __construct(
        private readonly SkillCatalog $skillCatalog,
    ) {}

    /**
     * @param array<string, mixed> $params A2A MessageSendParams (`{message:{…}}`)
     * @return \Generator<int, array<string, mixed>>
     */
    public function run(array $params, string $source, int|string $rpcId = 1): \Generator
    {
        $message = is_array($params['message'] ?? null) ? $params['message'] : [];
        $metadata = is_array($message['metadata'] ?? null) ? $message['metadata'] : [];

        $skillId = is_string($metadata['skill'] ?? null) && $metadata['skill'] !== ''
            ? $metadata['skill']
            : $this->inferSkill($this->textOf($message));
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
            yield Frames::result($rpcId, Frames::status($taskId, $contextId, 'working', (string)$skill['workingText']));

            // Cooperative pause: ask the caller for the missing detail, then end
            // THIS turn without a terminal state. The task is not done.
            if (!empty($skill['inputPrompt'])) {
                yield Frames::result($rpcId, Frames::status($taskId, $contextId, 'input-required', (string)$skill['inputPrompt'], true));
                return;
            }
        } else {
            $ack = (string)($skill['resumeText'] ?? 'Got it — finishing the task now…');
            if ($input !== '') {
                $ack .= ' (for: ' . mb_substr($input, 0, 80) . ')';
            }
            yield Frames::result($rpcId, Frames::status($taskId, $contextId, 'working', $ack));
        }

        // Produce the artifact, streamed chunk by chunk (append).
        $artifactId = 'art-' . substr(md5($taskId), 0, 8);
        yield Frames::result($rpcId, Frames::artifactStart($taskId, $artifactId, (string)$skill['artifactName'], (string)$skill['description']));
        foreach ($this->chunks((string)$skill['artifactText']) as $chunk) {
            yield Frames::result($rpcId, Frames::artifactChunk($taskId, $artifactId, $chunk));
        }
        yield Frames::result($rpcId, Frames::artifactEnd($taskId, $artifactId));

        yield Frames::result($rpcId, Frames::status($taskId, $contextId, 'completed', (string)$skill['completedText'], true));
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
