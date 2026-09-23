<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Webconsulting\AgentNexus\Agui\Agent\Approval;
use Webconsulting\AgentNexus\Agui\Agent\Scenario;
use Webconsulting\AgentNexus\Agui\Event\EventFactory;
use Webconsulting\AgentNexus\Agui\Protocol\Json;
use Webconsulting\AgentNexus\Agui\Protocol\RunInput;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\TruncatedAnswer;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;

/**
 * The demo agents: they turn a RunAgentInput into AG-UI 1.0 events.
 *
 * A run either proposes or resumes.
 *
 * - propose: reasoning (a span with one reasoning message), shared state, the
 *   answer — streamed from the live model when the site assistant's element
 *   allows it, scripted otherwise — the plan comparison as an activity, and
 *   the proposed change as a tool call. The run then ends with an interrupt
 *   outcome: it waits for a person.
 * - resume: the next run answers that interrupt in RunAgentInput.resume. Only
 *   an approval carries out the change; the result comes back as
 *   TOOL_CALL_RESULT for the original tool call, then RUN_FINISHED.
 *
 * The approval gate, the tool arguments and the write are never
 * model-controlled; a model can only word the answer.
 */
final readonly class AgentRunner
{
    /** Prefix of this installation's own names in CUSTOM events, activity types and metadata. */
    public const string VENDOR_KEY = 'at.webconsulting.agentnexus';

    /** CUSTOM event saying whether the answer comes from a live model or the script. */
    public const string PROVENANCE = self::VENDOR_KEY . '.provenance';

    /** Activity type of the site assistant's plan comparison. */
    public const string PLAN_COMPARISON = self::VENDOR_KEY . '.plan-comparison';

    /** Interrupt reason: a person confirms the proposed change. */
    public const string INTERRUPT_REASON = 'confirmation';

    /** A visitor's question beyond this is cut before it reaches a model. */
    private const int MAX_QUESTION_LENGTH = 600;

    /** The sentence that closes a model answer which broke off. */
    private const string UNFINISHED = ' I could not finish this answer. The details below are correct.';

    public function __construct(
        private LanguageModel $model,
        private UsageLedger $ledger,
        private Applier $applier,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function propose(RunInput $input, Scenario $scenario, ?LlmPlan $llm = null, string $scriptedReason = ''): \Generator
    {
        $question = mb_substr($input->lastUserText(), 0, self::MAX_QUESTION_LENGTH);
        $connection = $llm !== null && $question !== '' ? $this->model->getConnectionInfo() : null;

        yield EventFactory::runStarted($input->threadId, $input->runId);
        yield EventFactory::custom(self::PROVENANCE, $connection !== null
            ? ['mode' => 'llm', 'model' => $connection['model'], 'label' => 'Live model · ' . $connection['model']]
            : ['mode' => 'scripted', 'label' => 'Scripted demo'] + ($scriptedReason !== '' ? ['reason' => $scriptedReason] : []));

        yield EventFactory::stepStarted('analyse');
        $spanId = EventFactory::mintId('rsn');
        $reasoningId = EventFactory::mintId('msg');
        yield EventFactory::reasoningStart($spanId);
        yield EventFactory::reasoningMessageStart($reasoningId);
        foreach (self::words($scenario->reasoning) as $word) {
            yield EventFactory::reasoningMessageContent($reasoningId, $word);
        }
        yield EventFactory::reasoningMessageEnd($reasoningId);
        yield EventFactory::reasoningEnd($spanId);
        if ($scenario->state !== null) {
            yield EventFactory::stateSnapshot($scenario->state);
        }
        if ($scenario->stateDelta !== []) {
            yield EventFactory::stateDelta($scenario->stateDelta);
        }
        yield EventFactory::stepFinished('analyse');

        yield EventFactory::stepStarted('answer');
        $answerId = EventFactory::mintId('msg');
        yield EventFactory::textMessageStart($answerId);
        $fallback = $connection !== null && $llm !== null
            ? yield from $this->streamAnswer($answerId, $scenario, $question, $llm, $connection)
            : '';
        $streamed = $connection !== null && $fallback === '';
        if (!$streamed) {
            if ($connection !== null) {
                // The label said "Live model"; say what really answers.
                yield EventFactory::custom(self::PROVENANCE, ['mode' => 'scripted', 'label' => 'Scripted demo', 'reason' => $fallback]);
            }
            foreach (self::words($scenario->answer) as $word) {
                yield EventFactory::textMessageContent($answerId, $word);
            }
        }
        yield EventFactory::textMessageEnd($answerId);
        if ($scenario->activity !== null) {
            yield EventFactory::activitySnapshot(EventFactory::mintId('act'), self::PLAN_COMPARISON, $scenario->activity);
        }
        yield EventFactory::stepFinished('answer');

        yield EventFactory::stepStarted('propose');
        $toolCallId = EventFactory::mintId('call');
        yield EventFactory::toolCallStart($toolCallId, $scenario->tool, $answerId);
        foreach (mb_str_split(Json::encode(Json::object($scenario->toolArgs)), 40) as $delta) {
            yield EventFactory::toolCallArgs($toolCallId, $delta);
        }
        yield EventFactory::toolCallEnd($toolCallId);
        yield EventFactory::stepFinished('propose');

        $interrupt = EventFactory::interrupt(
            id: EventFactory::mintId('int'),
            reason: self::INTERRUPT_REASON,
            message: $scenario->approvalPrompt,
            toolCallId: $toolCallId,
            responseSchema: $scenario->responseSchema(),
            metadata: [self::VENDOR_KEY => ['preset' => $scenario->id]],
        );
        $usage = $streamed && $connection !== null
            ? [EventFactory::tokenUsage(provider: $connection['adapter'] !== '' ? strtolower($connection['adapter']) : null, model: $connection['modelId'])]
            : [];
        yield EventFactory::runFinished($input->threadId, $input->runId, EventFactory::interrupted([$interrupt]), usage: $usage);
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function resume(RunInput $input, Scenario $scenario, Resumption $resumption): \Generator
    {
        yield EventFactory::runStarted($input->threadId, $input->runId);
        try {
            $approval = Approval::read($resumption->entry, $scenario, $resumption->proposal);
        } catch (\InvalidArgumentException $e) {
            // The interrupt stays open: a corrected answer may follow.
            yield EventFactory::runError($e->getMessage(), 'invalid_answer');
            return;
        }

        yield EventFactory::stepStarted('apply');
        if ($approval->approved) {
            $result = $this->applier->apply($scenario, $approval);
            $simulated = ($result['simulated'] ?? false) === true;
            $toolResult = ['status' => $result['status'], 'simulated' => $simulated];
            $text = $scenario->doneText . ($simulated ? ' ' . $scenario->simulatedNote : '');
        } else {
            $result = $this->applier->decline($scenario, $approval);
            $toolResult = ['status' => 'declined', 'decision' => $approval->decision];
            $text = $scenario->declinedText;
        }
        // The call was proposed in the interrupted run; only its result belongs here.
        yield EventFactory::toolCallResult(EventFactory::mintId('msg'), $resumption->toolCallId, Json::encode($toolResult));
        $messageId = EventFactory::mintId('msg');
        yield EventFactory::textMessageStart($messageId);
        foreach (self::words($text) as $word) {
            yield EventFactory::textMessageContent($messageId, $word);
        }
        yield EventFactory::textMessageEnd($messageId);
        yield EventFactory::stepFinished('apply');
        yield EventFactory::runFinished($input->threadId, $input->runId, EventFactory::success(), $result);
    }

    /**
     * Stream the answer from the model. Returns why the script has to answer
     * instead when nothing usable arrived, or an empty string when the model
     * answered. A failure after the first chunk keeps what arrived and closes
     * with one scripted sentence.
     *
     * Streams report no finish reason, so an answer that stops without
     * finishing its sentence is taken as cut off at the output budget: it is
     * closed the same way and the provenance says so.
     *
     * @param array{provider: string, adapter: string, endpoint: string, model: string, modelId: string, priceInput: string, priceOutput: string, hasPricing: bool} $connection
     * @return \Generator<int, array<string, mixed>, mixed, string>
     */
    private function streamAnswer(string $messageId, Scenario $scenario, string $question, LlmPlan $llm, array $connection): \Generator
    {
        $systemPrompt = $llm->systemPrompt !== '' ? $llm->systemPrompt : $this->systemPrompt($scenario);
        $text = '';
        $closed = false;
        try {
            foreach ($this->model->streamText($systemPrompt, $question, $llm->maxTokens) as $chunk) {
                if ($chunk === '') {
                    // 1.0 allows empty deltas; older clients reject them.
                    continue;
                }
                $text .= $chunk;
                yield EventFactory::textMessageContent($messageId, $chunk);
            }
        } catch (TruncatedAnswer $truncated) {
            // Only the non-streaming fallback knows its finish reason; its text was never sent.
            $this->ledger->record('agui', UsageLedger::SOURCE_FRONTEND, $connection['modelId'], $truncated->promptTokens, $truncated->completionTokens, $truncated->cost);
            $this->logger->warning('AG-UI: {message} The script takes over.', ['message' => $truncated->getMessage()]);
            return $truncated->reason();
        } catch (\Throwable $e) {
            $this->logger->warning('AG-UI: the model stopped answering ({message}); the script takes over.', ['message' => $e->getMessage(), 'exception' => $e]);
            if ($text === '') {
                return 'the model did not answer';
            }
            yield EventFactory::textMessageContent($messageId, self::UNFINISHED);
            $closed = true;
        }
        if ($text === '') {
            $this->logger->warning('AG-UI: the model returned no text within {tokens} output tokens; the script takes over.', ['tokens' => $llm->maxTokens]);
            return 'the model did not answer';
        }
        if (!$closed && !self::endsASentence($text)) {
            $this->logger->notice('AG-UI: the answer stopped mid-sentence, most likely at the output budget of {tokens} tokens.', ['tokens' => $llm->maxTokens]);
            yield EventFactory::textMessageContent($messageId, '…' . self::UNFINISHED);
            yield EventFactory::custom(self::PROVENANCE, [
                'mode' => 'llm',
                'model' => $connection['model'],
                'label' => 'Live model · ' . $connection['model'],
                'reason' => sprintf('the answer stopped mid-sentence, most likely at the output limit of %d tokens', $llm->maxTokens),
            ]);
        }

        $promptTokens = $this->model->estimateTokens($systemPrompt . ' ' . $question);
        $completionTokens = $this->model->estimateTokens($text);
        $this->ledger->record(
            'agui',
            UsageLedger::SOURCE_FRONTEND,
            $connection['modelId'],
            $promptTokens,
            $completionTokens,
            $this->model->estimateCost($promptTokens, $completionTokens),
        );
        return '';
    }

    /**
     * Whether a streamed answer ends like a finished sentence: a full stop,
     * question or exclamation mark or ellipsis, optionally followed by a
     * closing quote or bracket.
     */
    public static function endsASentence(string $text): bool
    {
        return preg_match('/[.!?…。！？]["\'”’»)\]]*\s*$/u', $text) === 1;
    }

    /**
     * The built-in system prompt: the task's own facts, so the model has
     * nothing to invent. A content element may replace it.
     */
    private function systemPrompt(Scenario $scenario): string
    {
        $facts = ['summary' => $scenario->reasoning, 'proposal_tool' => $scenario->tool, 'proposal' => $scenario->toolArgs];
        if ($scenario->activity !== null) {
            $facts['offer'] = $scenario->activity;
        }
        return 'You are the assistant on this website and answer one visitor question. '
            . 'Answer in two to four short sentences of plain text: no Markdown, no lists, no links. '
            . 'Use only these facts and never invent prices or features: ' . Json::encode($facts)
            . ' After your answer the page asks the visitor to confirm the proposal. '
            . 'Do not tell them to click anything; end with a sentence that leads towards confirming.';
    }

    /**
     * Words with their trailing space, the pieces a scripted run streams.
     *
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return preg_match_all('/\S+\s*/u', $text, $matches) > 0 ? $matches[0] : [];
    }
}
