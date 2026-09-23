<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use Webconsulting\AgentNexus\A2a\Protocol\Artifact;
use Webconsulting\AgentNexus\A2a\Protocol\Ids;
use Webconsulting\AgentNexus\A2a\Protocol\Message;
use Webconsulting\AgentNexus\A2a\Protocol\Part;
use Webconsulting\AgentNexus\A2a\Protocol\Task;
use Webconsulting\AgentNexus\A2a\Protocol\TaskState;
use Webconsulting\AgentNexus\A2a\Server\CallContext;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\TruncatedAnswer;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;

/**
 * The site's A2A agent: decides what to do with a message.
 *
 * A new task is routed to one of the catalogue's skills — the skill the
 * client pinned in `message.metadata.skill`, else the model's choice (with a
 * rationale), else keyword matching, which always works. The skill then either
 * pauses for input (TASK_STATE_INPUT_REQUIRED) or produces its artifact and
 * completes. A message that answers a paused task continues the skill it was
 * routed to.
 *
 * The plan says what happens; {@see \Webconsulting\AgentNexus\A2a\Server\A2aServer}
 * carries it out, so the lifecycle is identical for every client and binding.
 * A model can only change two things: which skill is chosen and the artifact
 * text. The scripted text is the fallback for every model failure.
 */
final readonly class TaskRunner
{
    public const string SKILL_METADATA_KEY = 'skill';

    public function __construct(
        private SkillCatalog $skills,
        private LanguageModel $languageModel,
        private UsageLedger $usageLedger,
    ) {}

    public function plan(Task $task, Message $incoming, bool $resuming, CallContext $context): TurnPlan
    {
        if ($resuming) {
            return $this->continuePlan($task, $incoming, $context);
        }

        $request = mb_substr($incoming->text(), 0, 600);
        [$skillId, $routedBy, $rationale, $fallback] = $this->route($incoming, $request, $context);
        $skill = $this->skills->get($skillId);

        $workingText = $routedBy === 'model' && $context->showRationale && $rationale !== ''
            ? sprintf('Routed to “%s” — %s', $skill['name'], $rationale)
            : $skill['workingText'];
        $metadata = ['skillId' => $skill['id'], 'routedBy' => $routedBy];
        if ($fallback !== '') {
            $metadata['routingFallback'] = $fallback;
        }

        $steps = [PlanStep::status(TaskState::Working, $workingText, $metadata)];
        if ($skill['inputPrompt'] !== null && $skill['inputPrompt'] !== '') {
            $steps[] = PlanStep::status(TaskState::InputRequired, $skill['inputPrompt']);
        } else {
            $steps[] = PlanStep::artifact(fn(): Artifact => $this->artifact($skill['id'], $request, '', $context));
            $steps[] = PlanStep::status(TaskState::Completed, $skill['completedText']);
        }

        return new TurnPlan($skill['id'], $skill['name'], $steps, $metadata);
    }

    /**
     * The answer to a question the skill asked: acknowledge it, write the
     * artifact for the original request plus the answer, complete.
     */
    private function continuePlan(Task $task, Message $incoming, CallContext $context): TurnPlan
    {
        $skill = $this->skills->get($task->metadataString('skillId'));
        $answer = mb_substr($incoming->text(), 0, 600);
        $acknowledgement = $skill['resumeText'] ?? 'Got it — finishing the task now…';
        if ($answer !== '') {
            $acknowledgement .= ' (for: ' . mb_substr($answer, 0, 80) . ')';
        }
        $request = mb_substr($task->request(), 0, 600);

        return new TurnPlan($skill['id'], $skill['name'], [
            PlanStep::status(TaskState::Working, $acknowledgement),
            PlanStep::artifact(fn(): Artifact => $this->artifact($skill['id'], $request, $answer, $context)),
            PlanStep::status(TaskState::Completed, $skill['completedText']),
        ]);
    }

    /**
     * @return array{0: string, 1: 'request'|'model'|'keywords', 2: string, 3: string} skill id, how it was chosen, rationale, why the model's choice was not used
     */
    private function route(Message $incoming, string $request, CallContext $context): array
    {
        $pinned = $incoming->metadata[self::SKILL_METADATA_KEY] ?? null;
        if (is_string($pinned) && $this->skills->has($pinned)) {
            return [$pinned, 'request', '', ''];
        }
        $fallback = '';
        if ($context->useModel && $request !== '') {
            $routing = $this->routeWithModel($request, $context);
            if (isset($routing['skill'])) {
                return [$routing['skill'], 'model', $routing['rationale'], ''];
            }
            $fallback = $routing['fallback'] ?? '';
        }
        return [$this->routeByKeywords($request), 'keywords', '', $fallback];
    }

    public function routeByKeywords(string $request): string
    {
        $text = mb_strtolower($request);
        if (str_contains($text, 'email') || str_contains($text, 'outreach') || str_contains($text, 'reach out')) {
            return 'draft_outreach';
        }
        if (str_contains($text, 'onboard') || str_contains($text, 'plan')) {
            return 'plan_onboarding';
        }
        return 'summarize_page';
    }

    /**
     * Ask the model which catalogue skill fits. Any malformed or failed
     * answer returns null and keyword routing takes over; an answer cut off at
     * the output budget says so in `fallback`.
     *
     * @return array{skill: string, rationale: string}|array{fallback: string}|null
     */
    private function routeWithModel(string $request, CallContext $context): ?array
    {
        $catalog = [];
        foreach ($this->skills->all() as $id => $skill) {
            $catalog[] = ['id' => $id, 'description' => $skill['description']];
        }

        try {
            $completion = $this->languageModel->completeJson(
                'You route requests to exactly one skill of a website agent. Skills: '
                . json_encode($catalog, JSON_UNESCAPED_SLASHES)
                . ' Reply as JSON: {"skill": "<id>", "rationale": "<one short sentence, plain text>"}.',
                $request,
                null,
                min(160, $context->maxOutputTokens),
            );
        } catch (TruncatedAnswer $truncated) {
            $this->recordTruncated($truncated, $context);
            return ['fallback' => $truncated->reason()];
        } catch (\Throwable) {
            return null;
        }
        $this->recordUsage($completion, $context);

        $skillId = $completion['data']['skill'] ?? null;
        if (!is_string($skillId) || !$this->skills->has($skillId)) {
            return null;
        }
        $rationale = $completion['data']['rationale'] ?? null;

        return [
            'skill' => $skillId,
            'rationale' => mb_substr(trim(is_string($rationale) ? $rationale : ''), 0, 240),
        ];
    }

    /**
     * The skill's deliverable: written by the model for this request when the
     * caller may use one, the scripted example otherwise. The artifact says
     * which in its metadata.
     */
    private function artifact(string $skillId, string $request, string $answer, CallContext $context): Artifact
    {
        $skill = $this->skills->get($skillId);
        $text = null;
        $model = '';
        $fallback = '';
        if ($context->useModel && ($request !== '' || $answer !== '')) {
            try {
                $completion = $this->languageModel->completeText(
                    'You are the "' . $skill['name'] . '" skill of a website agent. '
                    . 'Produce the deliverable for the request as concise markdown (no preamble, no code fences), '
                    . 'matching the tone and shape of this example deliverable: ' . $skill['artifactText'],
                    trim($request . ($answer !== '' ? "\nAdditional detail: " . $answer : '')),
                    null,
                    $context->maxOutputTokens,
                );
                $this->recordUsage($completion, $context);
                if (trim($completion['text']) !== '') {
                    $text = trim($completion['text']);
                    $model = $completion['model'];
                }
            } catch (TruncatedAnswer $truncated) {
                // Half a deliverable is no deliverable: the script answers, and says why.
                $this->recordTruncated($truncated, $context);
                $fallback = $truncated->reason();
            } catch (\Throwable) {
                $text = null;
            }
        }

        $metadata = $text !== null ? ['writtenBy' => 'model', 'model' => $model] : ['writtenBy' => 'script'];
        if ($text === null && $fallback !== '') {
            $metadata['fallback'] = $fallback;
        }
        return new Artifact(
            Ids::uuid(),
            [Part::text($text ?? $skill['artifactText'], 'text/markdown')],
            $skill['artifactName'],
            $skill['description'],
            $metadata,
        );
    }

    /**
     * A cut-off answer is thrown away, but its tokens were spent.
     */
    private function recordTruncated(TruncatedAnswer $truncated, CallContext $context): void
    {
        $this->recordUsage([
            'promptTokens' => $truncated->promptTokens,
            'completionTokens' => $truncated->completionTokens,
            'cost' => $truncated->cost,
            'model' => $truncated->model,
        ], $context);
    }

    /**
     * @param array{promptTokens: int, completionTokens: int, cost: ?float, model: string} $completion
     */
    private function recordUsage(array $completion, CallContext $context): void
    {
        $this->usageLedger->record(
            'a2a',
            $context->channel === Channel::Backend ? UsageLedger::SOURCE_BACKEND : UsageLedger::SOURCE_FRONTEND,
            $completion['model'] !== '' ? $completion['model'] : 'default',
            $completion['promptTokens'],
            $completion['completionTokens'],
            $completion['cost'],
        );
    }
}
