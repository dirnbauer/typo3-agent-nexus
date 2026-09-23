<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\ActionOutcome;
use Webconsulting\AgentNexus\A2ui\Domain\Model\RendererMessage;
use Webconsulting\AgentNexus\A2ui\Domain\Model\SurfaceBuilder;
use Webconsulting\AgentNexus\A2ui\Http\A2uiProblem;

/**
 * The agent's answer to what a renderer reports.
 *
 * Actions are fire-and-forget in A2UI: the renderer sends one and the agent
 * may answer with messages for the surface. This agent answers the main
 * action of a form with a confirmation view — `updateComponents` replaces the
 * root with a summary card, `updateDataModel` writes the reference number and
 * the summary the card binds to — and the "discard" action with
 * `deleteSurface`. A click on a Modal's trigger needs no answer (the renderer
 * opens the dialog itself); a reported error is only recorded.
 */
final readonly class ActionHandler
{
    public const string DEFAULT_CONFIRMATION = 'Thank you. We have received your request and will reply soon.';

    /** Summary lines shown on the confirmation, at most. */
    private const int MAX_SUMMARY = 8;

    public function __construct(
        private MessageBuilder $messages,
        private SurfaceSanitizer $sanitizer,
    ) {}

    /**
     * @param list<array<string, mixed>> $sent the messages the agent sent to this surface so far
     * @throws A2uiProblem when the action does not belong to the surface
     */
    public function answer(RendererMessage $message, array $sent, string $confirmation = ''): ActionOutcome
    {
        if (!$message->isAction()) {
            $code = is_string($message->body['code'] ?? null) ? $message->body['code'] : 'error';
            return new ActionOutcome([], null, 'The renderer reported ' . $code . '.');
        }

        $surfaceId = $message->surfaceId();
        $components = $this->components($sent);
        $source = $components[$message->sourceComponentId()] ?? null;
        if ($source === null) {
            throw A2uiProblem::invalid('/action/sourceComponentId', sprintf('The surface has no component "%s".', $message->sourceComponentId()), $surfaceId, $message->version);
        }
        $event = is_array($source['action'] ?? null) && is_array($source['action']['event'] ?? null) ? $source['action']['event'] : [];
        if (($event['name'] ?? null) !== $message->name()) {
            throw A2uiProblem::invalid('/action/name', sprintf('Component "%s" does not send the action "%s".', $message->sourceComponentId(), $message->name()), $surfaceId, $message->version);
        }

        if ($message->name() === SurfaceGenerator::DISCARD_EVENT) {
            return new ActionOutcome([$this->messages->deleteSurface($surfaceId, $message->version)], 'deleted', 'Discarded by the user.');
        }
        if ($this->isModalTrigger($message->sourceComponentId(), $components)) {
            return new ActionOutcome([], null, 'Opened a dialog.');
        }

        $reference = 'A2UI-' . strtoupper(bin2hex(random_bytes(3)));
        $summary = $this->summary($message, $event, $components);

        return new ActionOutcome([
            $this->messages->updateComponents($surfaceId, $this->confirmation($confirmation, $message->version), $message->version),
            $this->messages->updateDataModel($surfaceId, '/confirmation', ['reference' => $reference, 'summary' => $summary], $message->version),
        ], 'submitted', sprintf('%s: %s, confirmation %s.', $message->name(), $message->sourceComponentId(), $reference));
    }

    /**
     * The confirmation card: a heading, the confirmation text, one line per
     * submitted value (a List template over /confirmation/summary with
     * relative bindings) and the reference number (formatString).
     *
     * @return list<\Webconsulting\AgentNexus\A2ui\Domain\Model\Component>
     */
    private function confirmation(string $text, A2uiVersion $version): array
    {
        $b = new SurfaceBuilder();
        $item = $b->column('confirmation_item', [
            $b->text('confirmation_item_label', ['path' => 'label'], 'caption'),
            $b->text('confirmation_item_value', ['path' => 'value']),
        ]);
        $b->card('root', $b->column('confirmation', [
            $b->text('confirmation_title', 'Request received', 'h2'),
            $b->text('confirmation_text', trim($text) !== '' ? trim($text) : self::DEFAULT_CONFIRMATION),
            $b->list('confirmation_summary', '/confirmation/summary', $item),
            $b->text('confirmation_reference', [
                'call' => 'formatString',
                'args' => ['value' => 'Your reference: ${/confirmation/reference}'],
            ], 'caption'),
        ]));
        $surface = $b->build('confirmation', 'Request received');
        return $this->sanitizer->sanitize($surface->componentsToArray(), [], $version)->components;
    }

    /**
     * One line per value of the action context: the label of the field it
     * came from, and the option labels of a choice rather than its values.
     *
     * @param array<array-key, mixed> $event the event definition of the source component
     * @param array<string, array<string, mixed>> $components
     * @return list<array{label: string, value: string}>
     */
    private function summary(RendererMessage $message, array $event, array $components): array
    {
        $fields = [];
        foreach ($components as $component) {
            $binding = is_array($component['value'] ?? null) ? ($component['value']['path'] ?? null) : null;
            if (is_string($binding)) {
                $fields[$binding] = $component;
            }
        }
        $bindings = is_array($event['context'] ?? null) ? $event['context'] : [];

        $summary = [];
        foreach ($message->context() as $key => $value) {
            $path = is_array($bindings[$key] ?? null) && is_string($bindings[$key]['path'] ?? null) ? $bindings[$key]['path'] : null;
            $field = $path !== null ? ($fields[$path] ?? []) : [];
            $text = $this->display($value, $field);
            if ($text === '') {
                continue;
            }
            $label = is_string($field['label'] ?? null) && $field['label'] !== ''
                ? $field['label']
                : ucfirst(strtolower(trim((string)preg_replace('/(?<=[a-z])(?=[A-Z])|[_\-.]+/', ' ', $key))));
            $summary[] = ['label' => mb_substr($label, 0, 120), 'value' => mb_substr($text, 0, 200)];
            if (count($summary) === self::MAX_SUMMARY) {
                break;
            }
        }
        return $summary;
    }

    /**
     * @param array<string, mixed> $field the input component the value came from
     */
    private function display(mixed $value, array $field): string
    {
        $labels = [];
        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null) && is_string($option['label'] ?? null)) {
                $labels[$option['value']] = $option['label'];
            }
        }
        return match (true) {
            is_bool($value) => $value ? 'Yes' : 'No',
            is_string($value) => trim($labels[$value] ?? $value),
            is_int($value), is_float($value) => (string)$value,
            is_array($value) && array_is_list($value) => implode(', ', array_map(
                static fn(mixed $item): string => is_scalar($item) ? ($labels[(string)$item] ?? (string)$item) : '',
                $value,
            )),
            default => '',
        };
    }

    /**
     * @param array<string, array<string, mixed>> $components
     */
    private function isModalTrigger(string $id, array $components): bool
    {
        return array_any(
            $components,
            static fn(array $component): bool => ($component['component'] ?? null) === 'Modal' && ($component['trigger'] ?? null) === $id,
        );
    }

    /**
     * The surface's components as the renderer holds them: the latest
     * definition of every id.
     *
     * @param list<array<string, mixed>> $sent
     * @return array<string, array<string, mixed>>
     */
    private function components(array $sent): array
    {
        $components = [];
        foreach ($sent as $message) {
            $body = $message['updateComponents'] ?? $message['createSurface'] ?? null;
            if (!is_array($body) || !is_array($body['components'] ?? null)) {
                continue;
            }
            foreach ($body['components'] as $component) {
                if (is_array($component) && is_string($component['id'] ?? null)) {
                    $clean = [];
                    foreach ($component as $key => $value) {
                        $clean[(string)$key] = $value;
                    }
                    $components[$component['id']] = $clean;
                }
            }
        }
        return $components;
    }
}
