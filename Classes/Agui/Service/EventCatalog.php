<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use Webconsulting\AgentNexus\Agui\Event\EventFactory;
use Webconsulting\AgentNexus\Agui\Protocol\EventFamily;
use Webconsulting\AgentNexus\Agui\Protocol\EventType;
use Webconsulting\AgentNexus\Agui\Protocol\FieldType;

/**
 * The AG-UI 1.0 event catalogue as screens and facts show it: the 31 event
 * types grouped into their eight families, each with its fields and an
 * example built by the event factory.
 *
 * Nothing here is typed twice — families and fields come from
 * {@see EventType}, the examples from {@see EventFactory}, and the
 * conformance tests validate every example against the specification's
 * schema.
 */
final class EventCatalog
{
    /**
     * Families by name, in the specification's order.
     *
     * @return array<string, array{family: string, events: list<array{type: string, anchor: string, required: array<string, string>, optional: array<string, string>, attributable: bool, example: string}>}>
     */
    public function all(): array
    {
        $families = [];
        foreach (EventFamily::cases() as $family) {
            $families[$family->title()] = [
                'family' => $family->value,
                'events' => array_map($this->describe(...), $family->events()),
            ];
        }
        return $families;
    }

    /**
     * @return array{type: string, anchor: string, required: array<string, string>, optional: array<string, string>, attributable: bool, example: string}
     */
    public function describe(EventType $type): array
    {
        $label = static fn(FieldType $field): string => $field->label();
        return [
            'type' => $type->value,
            'anchor' => $type->schemaAnchor(),
            'required' => array_map($label, $type->required()),
            'optional' => array_map($label, $type->optional()),
            'attributable' => $type->attributable(),
            'example' => (string)json_encode(self::example($type), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * A valid event of the given type.
     *
     * @return array<string, mixed>
     */
    public static function example(EventType $type): array
    {
        $usage = [EventFactory::tokenUsage('openai', 'gpt-5.2', 812, 144)];
        return match ($type) {
            EventType::RunStarted => EventFactory::runStarted('thread-1', 'run-1'),
            EventType::RunFinished => EventFactory::runFinished('thread-1', 'run-1', EventFactory::interrupted([
                EventFactory::interrupt('int-1', AgentRunner::INTERRUPT_REASON, 'Confirm and send your request to our team.', 'call-1', [
                    'type' => 'object',
                    'properties' => ['approved' => ['type' => 'boolean']],
                    'required' => ['approved'],
                ]),
            ]), usage: $usage),
            EventType::RunError => EventFactory::runError('The model provider did not answer.', 'upstream_timeout'),
            EventType::StepStarted => EventFactory::stepStarted('analyse'),
            EventType::StepFinished => EventFactory::stepFinished('analyse'),
            EventType::TextMessageStart => EventFactory::textMessageStart('msg-2'),
            EventType::TextMessageContent => EventFactory::textMessageContent('msg-2', 'The Team plan fits best. '),
            EventType::TextMessageEnd => EventFactory::textMessageEnd('msg-2'),
            EventType::TextMessageChunk => EventFactory::textMessageChunk('msg-3', 'Hello', 'assistant'),
            EventType::ToolCallStart => EventFactory::toolCallStart('call-1', 'confirm_booking', 'msg-2'),
            EventType::ToolCallArgs => EventFactory::toolCallArgs('call-1', '{"plan":"Team","seats":5}'),
            EventType::ToolCallEnd => EventFactory::toolCallEnd('call-1'),
            EventType::ToolCallChunk => EventFactory::toolCallChunk('call-2', 'search', '{"q":"plans"}', 'msg-2'),
            EventType::ToolCallResult => EventFactory::toolCallResult('msg-4', 'call-1', '{"status":"sent"}'),
            EventType::ReasoningStart => EventFactory::reasoningStart('span-1'),
            EventType::ReasoningMessageStart => EventFactory::reasoningMessageStart('msg-1'),
            EventType::ReasoningMessageContent => EventFactory::reasoningMessageContent('msg-1', 'Five people, under €50. '),
            EventType::ReasoningMessageEnd => EventFactory::reasoningMessageEnd('msg-1'),
            EventType::ReasoningMessageChunk => EventFactory::reasoningMessageChunk('msg-5', 'Checking the plans. '),
            EventType::ReasoningEnd => EventFactory::reasoningEnd('span-1'),
            EventType::ReasoningEncryptedValue => EventFactory::reasoningEncryptedValue('message', 'msg-1', 'gAAAAABo…'),
            EventType::StateSnapshot => EventFactory::stateSnapshot(['selection' => null]),
            EventType::StateDelta => EventFactory::stateDelta([['op' => 'replace', 'path' => '/selection', 'value' => 'Team']]),
            EventType::MessagesSnapshot => EventFactory::messagesSnapshot([
                ['id' => 'msg-0', 'role' => 'user', 'content' => 'I need a plan for five people.'],
                ['id' => 'msg-2', 'role' => 'assistant', 'content' => 'The Team plan fits best.'],
            ]),
            EventType::ActivitySnapshot => EventFactory::activitySnapshot('act-1', AgentRunner::PLAN_COMPARISON, [
                'recommended' => 'Team',
                'plans' => [['name' => 'Team', 'price' => 39, 'seats' => 5]],
            ]),
            EventType::ActivityDelta => EventFactory::activityDelta('act-1', AgentRunner::PLAN_COMPARISON, [
                ['op' => 'replace', 'path' => '/recommended', 'value' => 'Business'],
            ]),
            EventType::SubagentStarted => EventFactory::subagentStarted('sub-1', 'pricing', 'Looks up current prices.'),
            EventType::SubagentFinished => EventFactory::subagentFinished('sub-1'),
            EventType::SubagentError => EventFactory::subagentError('sub-1', 'The price list is not available.'),
            EventType::Raw => EventFactory::raw(['kind' => 'heartbeat'], 'provider'),
            EventType::Custom => EventFactory::custom(AgentRunner::PROVENANCE, ['mode' => 'scripted', 'label' => 'Scripted demo']),
        };
    }
}
