<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Psr\Log\LoggerInterface;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Component;
use Webconsulting\AgentNexus\A2ui\Domain\Model\GenerationRequest;
use Webconsulting\AgentNexus\A2ui\Domain\Model\GenerationResult;
use Webconsulting\AgentNexus\A2ui\Domain\Model\PropertyDefinition;
use Webconsulting\AgentNexus\A2ui\Domain\Model\PropertyType;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Surface;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Llm\TruncatedAnswer;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * The agent: turns a one-line request into an A2UI surface.
 *
 * With a model configured, the model is shown the official basic catalogue of
 * the requested version and one worked example, and answers with components
 * and a data model — never with envelopes; the messages are built here. Its
 * answer goes through {@see SurfaceSanitizer}, so nothing outside the
 * catalogue reaches a renderer.
 *
 * Without a model — none installed, switched off, the budget used up, or an
 * answer that cannot be repaired — {@see SurfaceGenerator} answers instead.
 * The result always says which of the two it was.
 */
final readonly class AgentService
{
    /** Model answers stop being useful long before this many notes. */
    private const int MAX_NOTES = 8;

    public function __construct(
        private SurfaceGenerator $generator,
        private SurfaceSanitizer $sanitizer,
        private ComponentRegistry $registry,
        private LanguageModel $model,
        private UsageLedger $ledger,
        private LlmGuard $guard,
        private ExtensionSettings $settings,
        private LoggerInterface $logger,
    ) {}

    public function generate(GenerationRequest $request): GenerationResult
    {
        $intent = trim($request->intent);
        $notes = [];
        $reason = '';

        $maxTokens = $this->modelBudget($request, $notes);
        if ($maxTokens !== false && $intent !== '') {
            $result = $this->fromModel($request, $intent, $maxTokens, $notes, $reason);
            if ($result !== null) {
                return $result;
            }
        }

        $example = $this->generator->generate($intent);
        $clean = $this->sanitizer->sanitize($example->componentsToArray(), $example->dataModel, $request->version);
        $surface = $example
            ->withSurfaceId($this->surfaceId($example->surfaceId))
            ->withContent($clean->components, $clean->dataModel);

        return new GenerationResult($surface, GenerationResult::MODE_BUILTIN, null, $notes, $reason);
    }

    /** Whether the playground can use a model at all. */
    public function isModelAvailable(): bool
    {
        return $this->settings->bool('a2uiLlmEnabled', true) && $this->model->isAvailable();
    }

    /**
     * Whether a request of this kind could reach a model — only such a request
     * spends the tighter per-client model budget.
     */
    public function mayUseModel(bool $public): bool
    {
        if (!$this->settings->bool('a2uiLlmEnabled', true)) {
            return false;
        }
        return $public ? $this->guard->allows('a2ui')['allowed'] : $this->model->isAvailable();
    }

    /**
     * Provider, model and prices for the playground, or null without a model.
     *
     * @return array{provider: string, adapter: string, endpoint: string, model: string, modelId: string, priceInput: string, priceOutput: string, hasPricing: bool}|null
     */
    public function connectionInfo(): ?array
    {
        return $this->isModelAvailable() ? $this->model->getConnectionInfo() : null;
    }

    /**
     * A2UI model spend: today and per month, in US dollars, formatted.
     *
     * @return array{today: string, months: list<array{label: string, cost: string, requests: int}>}
     */
    public function costSummary(int $months = 3): array
    {
        $format = static fn(float $value): string => $value <= 0
            ? '$0.00'
            : ($value < 0.01 ? '$' . rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') : '$' . number_format($value, 2, '.', ','));

        return [
            'today' => $format($this->ledger->getCostToday('a2ui')),
            'months' => array_map(
                static fn(array $month): array => ['label' => $month['label'], 'cost' => $format($month['cost']), 'requests' => $month['requests']],
                $this->ledger->getMonthlyCosts($months, 'a2ui'),
            ),
        ];
    }

    /**
     * The system prompt for one version: the rules, the catalogue and a worked
     * example, all derived from the registry and the built-in generator.
     */
    public function systemPrompt(A2uiVersion $version, string $businessContext = ''): string
    {
        $headings = $version === A2uiVersion::V1_0
            ? 'Text has no heading variants in this version: start the text with "## " for a heading.'
            : 'Text variant "h2" is a heading, "caption" small print, "body" normal text.';
        $example = $this->generator->example('contact', 'A question about your services');
        $clean = $this->sanitizer->sanitize($example->componentsToArray(), $example->dataModel, $version);
        $exampleJson = (string)json_encode(
            ['title' => $example->title, 'components' => array_map(static fn(Component $component): array => $component->toArray(), $clean->components), 'dataModel' => $clean->dataModel],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $business = trim($businessContext) !== ''
            ? "\n\nAbout the business (choose fields and wording that fit it; still use only the catalogue):\n" . trim($businessContext)
            : '';

        return <<<PROMPT
            You design forms for the A2UI protocol, version {$version->value}, using its official basic catalogue.
            Answer with ONE JSON object and nothing else:
            {"title": "<a short title>", "components": [ ... ], "dataModel": { ... }}

            Rules
            - "components" is a flat list. Every component is an object with "id" (unique), "component" (a name from the catalogue below) and its properties at the top level. Never nest one component inside another: refer to other components by id.
            - Exactly one component has the id "root". Make it a Card whose "child" is a Column.
            - Row, Column and List take "children": a list of ids. Card takes one "child" id. Tabs take "tabs": [{"title", "child"}]. Modal takes a "trigger" id (a Button) and a "content" id.
            - A Button has no text of its own: its "child" is the id of a Text component that holds the label. Every Button needs "action": {"event": {"name": "<verb>", "context": {"<key>": {"path": "/<key>"}}}}. List every input's key in the context of the main button.
            - Add a second Button with "variant": "borderless", a Text child "Start over" and the action {"event": {"name": "discard"}}. Put both buttons in a Row.
            - Inputs bind their value with {"path": "/<key>"}: TextField (text), CheckBox (true or false), ChoicePicker (a list of strings), Slider (a number), DateTimeInput (an ISO 8601 string). Give every bound key a starting value in "dataModel": "" for text, false for a box, [] or one option for a choice.
            - Validation: "checks": [{"condition": {"call": "required", "args": {"value": {"path": "/email"}}}, "message": "Enter your email address."}]. Check functions: required, email, regex (args value, pattern), length (value, min or max), numeric (value, min or max).
            - {$headings}
            - TextField "variant": shortText (one line), longText (several lines), number, obscured (a password).
            - Use only the components and properties listed below. No HTML, no scripts, no links or image URLs you made up. Stay under 25 components. Plain, friendly wording.

            Catalogue (* = required)
            {$this->describeCatalogue($version)}

            Example answer for "A question about your services":
            {$exampleJson}{$business}
            PROMPT;
    }

    /**
     * Whether a model may answer: false when not, otherwise the token ceiling
     * (null for none).
     *
     * @param list<string> $notes
     */
    private function modelBudget(GenerationRequest $request, array &$notes): int|false|null
    {
        if ($request->offline) {
            return false;
        }
        if (!$this->settings->bool('a2uiLlmEnabled', true)) {
            if (!$request->public) {
                $notes[] = 'Model generation is switched off in the extension settings; the built-in generator answered.';
            }
            return false;
        }
        if (!$request->modelAllowed) {
            $notes[] = 'The model budget for this client is used up for now; the built-in generator answered.';
            return false;
        }
        if ($request->public) {
            $verdict = $this->guard->allows('a2ui');
            if (!$verdict['allowed']) {
                $notes[] = sprintf('No model call (%s); the built-in generator answered.', $verdict['reason']);
                return false;
            }
            return $this->guard->maxOutputTokens(Protocol::A2ui);
        }
        if (!$this->model->isAvailable()) {
            $notes[] = 'No model is configured (netresearch/nr-llm); the built-in generator answered.';
            return false;
        }
        return null;
    }

    /**
     * @param list<string> $notes
     * @param string $reason set to why the built-in generator answers instead, when the model's answer was unusable
     */
    private function fromModel(GenerationRequest $request, string $intent, ?int $maxTokens, array &$notes, string &$reason): ?GenerationResult
    {
        $configured = $this->settings->string('a2uiLlmModel', '');
        try {
            $completion = $this->model->completeJson(
                $this->systemPrompt($request->version, $request->businessContext),
                sprintf("Request: \"%s\"\nWrite every visible label in this language: %s.", $intent, $request->language !== '' ? $request->language : 'en'),
                $configured !== '' ? $configured : null,
                $maxTokens,
            );
        } catch (TruncatedAnswer $truncated) {
            // The tokens are spent even though the half surface is thrown away.
            $this->ledger->record(
                'a2ui',
                $request->public ? UsageLedger::SOURCE_FRONTEND : UsageLedger::SOURCE_BACKEND,
                $this->modelName($configured),
                $truncated->promptTokens,
                $truncated->completionTokens,
                $truncated->cost,
                $request->beUser,
            );
            $notes[] = $truncated->maxTokens !== null
                ? sprintf('The model answer was cut off at the output limit of %d tokens (a2uiLlmMaxOutputTokens); the built-in generator answered.', $truncated->maxTokens)
                : 'The model answer was cut off at the output limit; the built-in generator answered.';
            $reason = $truncated->reason();
            return null;
        } catch (\JsonException) {
            $notes[] = 'The model answer was not valid JSON (it may have been cut off); the built-in generator answered.';
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('A2UI generation with a model failed; the built-in generator answered.', ['exception' => $e]);
            $notes[] = $request->public
                ? 'The model call failed; the built-in generator answered.'
                : sprintf('The model call failed (%s); the built-in generator answered.', $e->getMessage());
            return null;
        }

        $model = $this->modelName($configured);
        $this->ledger->record(
            'a2ui',
            $request->public ? UsageLedger::SOURCE_FRONTEND : UsageLedger::SOURCE_BACKEND,
            $model,
            $completion['promptTokens'],
            $completion['completionTokens'],
            $completion['cost'],
            $request->beUser,
        );

        [$components, $dataModel, $title] = $this->extract($completion['data']);
        $clean = $this->sanitizer->sanitize($components, $dataModel, $request->version);
        if (!$clean->isRenderable()) {
            $notes[] = 'The model answer had no usable root component; the built-in generator answered.';
            return null;
        }
        foreach (array_slice($clean->notes, 0, self::MAX_NOTES) as $note) {
            $notes[] = 'Repaired: ' . $note;
        }
        if (count($clean->notes) > self::MAX_NOTES) {
            $notes[] = sprintf('… and %d more repairs.', count($clean->notes) - self::MAX_NOTES);
        }

        $title = $title !== '' ? $title : mb_substr($intent, 0, 80);
        $surface = new Surface($this->surfaceId($title), $clean->components, $clean->dataModel, true, $title);
        return new GenerationResult($surface, GenerationResult::MODE_LLM, $model, $notes);
    }

    /**
     * The configured model, or the provider's default model, for the ledger
     * and the provenance label.
     */
    private function modelName(string $configured): string
    {
        if ($configured !== '') {
            return $configured;
        }
        $connection = $this->model->getConnectionInfo();
        return $connection !== null && $connection['model'] !== '' ? $connection['model'] : 'default';
    }

    /**
     * Components, data model and title from a model answer. Besides the
     * requested shape, whole A2UI messages are understood too, because models
     * that know the protocol like to send them.
     *
     * @param array<string, mixed> $data
     * @return array{0: mixed, 1: mixed, 2: string}
     */
    private function extract(array $data): array
    {
        $title = is_string($data['title'] ?? null) ? mb_substr(trim($data['title']), 0, 120) : '';
        if (isset($data['components'])) {
            return [$data['components'], $data['dataModel'] ?? [], $title];
        }

        $messages = [];
        if (isset($data['messages']) && is_array($data['messages'])) {
            $messages = $data['messages'];
        } elseif (array_is_list($data)) {
            $messages = $data;
        } else {
            $messages = [$data];
        }
        $components = [];
        $dataModel = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $body = $message['createSurface'] ?? $message['updateComponents'] ?? null;
            if (is_array($body) && is_array($body['components'] ?? null)) {
                $components = [...$components, ...array_values($body['components'])];
            }
            if (is_array($message['createSurface']['dataModel'] ?? null)) {
                $dataModel = $message['createSurface']['dataModel'];
            }
            $update = $message['updateDataModel'] ?? null;
            if (is_array($update) && in_array($update['path'] ?? '/', ['/', ''], true) && is_array($update['value'] ?? null)) {
                $dataModel = $update['value'];
            }
        }
        return [$components, $dataModel, $title];
    }

    /**
     * A surface id that is never reused: a readable slug and random hex.
     */
    private function surfaceId(string $base): string
    {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($base)), '-');
        $slug = $slug !== '' ? mb_substr($slug, 0, 40) : 'surface';
        return rtrim($slug, '-') . '-' . bin2hex(random_bytes(4));
    }

    private function describeCatalogue(A2uiVersion $version): string
    {
        $lines = [];
        foreach ($this->registry->components($version) as $component) {
            $properties = array_map(
                fn(PropertyDefinition $property): string => $property->name . ($property->required ? '*' : '') . ' (' . $this->describeType($property) . ')',
                array_values($component->properties),
            );
            $lines[] = '- ' . $component->name . ': ' . implode(', ', $properties);
        }
        $lines[] = 'Every component may also have "accessibility": {"label", "description"}, and a direct child of a Row or Column a "weight" (a number).';
        return implode("\n", $lines);
    }

    private function describeType(PropertyDefinition $property): string
    {
        return match ($property->type) {
            PropertyType::Enum => implode('|', $property->values),
            PropertyType::IconName => 'an icon name such as ' . implode(', ', array_slice($property->values, 0, 12)) . ' …',
            PropertyType::DynamicString => 'text or {"path"}',
            PropertyType::DynamicNumber => 'number or {"path"}',
            PropertyType::DynamicBoolean => 'true/false or {"path"}',
            PropertyType::DynamicStringList => 'list of strings or {"path"}',
            PropertyType::DateTime => 'ISO 8601 date or date-time',
            PropertyType::ComponentId => 'a component id',
            PropertyType::ChildList => 'list of ids, or {"componentId", "path"} to repeat one component for each item of a list',
            PropertyType::Tabs => '[{"title", "child"}]',
            PropertyType::Options => '[{"label", "value"}]',
            PropertyType::Action => 'action',
            PropertyType::Checks => 'checks',
            PropertyType::Number => 'number',
            PropertyType::Integer => 'whole number',
            PropertyType::Boolean => 'true or false',
            default => $property->type->value,
        };
    }
}
