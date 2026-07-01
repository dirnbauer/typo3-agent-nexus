<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Component;
use Webconsulting\AgentNexus\A2ui\Domain\Model\GenerationResult;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Surface;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\Shared\Llm\LlmClient;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Llm\LlmUsageTracker;

/**
 * The "agent": turns a natural-language intent into an A2UI v1.0 surface.
 *
 * Primary path - a real LLM (via {@see LlmClient}) is asked to emit a surface
 * using ONLY the trusted catalog; its output is parsed and hardened against the
 * {@see ComponentRegistry} so nothing outside the catalog can be rendered.
 *
 * Fallback path - a deterministic, offline generator covers a handful of common
 * backend intents so the demo always works without an API key, and so an LLM
 * hiccup never leaves the user with a blank screen.
 */
final class AgentService implements SingletonInterface
{
    public function __construct(
        private readonly ComponentRegistry $registry,
        private readonly LlmClient $llmClient,
        private readonly LlmUsageTracker $usageTracker,
        private readonly LlmGuard $llmGuard,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $context Optional context (page id, be user, language, ...)
     */
    public function generate(string $intent, array $context = []): GenerationResult
    {
        $intent = trim($intent);
        $notes = [];
        $settings = $this->getSettings();

        $llmEnabled = (bool)($settings['llmEnabled'] ?? true);
        // Frontend calls additionally pass the shared guard (global switch,
        // per-protocol toggle, daily budget) — the backend playground only
        // needs the module toggle.
        if ($llmEnabled && ($context['source'] ?? '') === LlmUsageTracker::SOURCE_FRONTEND) {
            $verdict = $this->llmGuard->allows('a2ui');
            if (!$verdict['allowed']) {
                $llmEnabled = false;
                $notes[] = 'LLM skipped (' . $verdict['reason'] . '); used the built-in generator.';
            }
        }
        if ($intent !== '' && $llmEnabled && $this->llmClient->isAvailable()) {
            $model = (string)($settings['llmModel'] ?? '');
            try {
                $completion = $this->llmClient->completeJson(
                    $this->buildSystemPrompt($context),
                    $this->buildUserPrompt($intent, $context),
                    $model !== '' ? $model : null,
                );
                $surface = $this->buildSurfaceFromLlm($completion['data'], $intent);
                if ($surface !== null) {
                    $this->recordUsage($completion, $context);
                    return new GenerationResult($surface, GenerationResult::SOURCE_LLM, $model !== '' ? $model : 'default', $notes);
                }
                $notes[] = 'The model response was not a usable A2UI surface; used the built-in generator instead.';
            } catch (\Throwable $e) {
                $this->logger->warning('A2UI LLM generation failed, falling back.', ['exception' => $e, 'intent' => $intent]);
                $notes[] = 'LLM generation failed (' . $e->getMessage() . '); used the built-in generator instead.';
            }
        } elseif ($intent !== '' && $llmEnabled && !$this->llmClient->isAvailable()) {
            $notes[] = 'No LLM provider available (netresearch/nr-llm not installed); using the built-in generator.';
        }

        return new GenerationResult($this->buildFallback($intent, $context), GenerationResult::SOURCE_BUILTIN, null, $notes);
    }

    public function isLlmAvailable(): bool
    {
        return (bool)($this->getSettings()['llmEnabled'] ?? true) && $this->llmClient->isAvailable();
    }

    /**
     * Deterministic, offline surface generation (no LLM) — used by the component
     * gallery so examples are stable and fast regardless of provider state.
     */
    public function generateOffline(string $intent): Surface
    {
        return $this->buildFallback($intent, []);
    }

    /**
     * Live provider/model/pricing info for the UI, or null when no LLM is active.
     *
     * @return array<string, mixed>|null
     */
    public function getConnectionInfo(): ?array
    {
        if (!$this->isLlmAvailable()) {
            return null;
        }
        return $this->llmClient->getConnectionInfo();
    }

    /**
     * Combined A2UI spend (backend module + frontend plugin): cost today and per
     * month for the last N months, plus the instance-wide nr-llm total for
     * context. Costs are US dollars.
     *
     * @return array{today: float, months: array<int, array{label: string, cost: float, requests: int}>, instanceToday: ?float, instanceRange: ?float}
     */
    public function getCostSummary(int $months = 3): array
    {
        $now = new \DateTimeImmutable('now');
        $todayStart = new \DateTimeImmutable('today 00:00:00');
        $rangeStart = (new \DateTimeImmutable('first day of this month 00:00:00'))->modify('-' . ($months - 1) . ' months');

        $fmt = static function (?float $value): ?string {
            if ($value === null) {
                return null;
            }
            if ($value <= 0) {
                return '$0.00';
            }
            // LLM costs are tiny; keep 4 significant decimals under a cent, 2 above.
            return $value < 0.01
                ? '$' . rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.')
                : '$' . number_format($value, 2, '.', ',');
        };

        $months = array_map(
            static fn(array $m): array => [
                'label' => $m['label'],
                'cost' => $fmt($m['cost']),
                'requests' => $m['requests'],
            ],
            $this->usageTracker->getMonthlyCosts($months, 'a2ui'),
        );

        return [
            'today' => $fmt($this->usageTracker->getCostToday('a2ui')),
            'months' => $months,
            'instanceToday' => $fmt($this->llmClient->getInstanceCost($todayStart, $now)),
            'instanceRange' => $fmt($this->llmClient->getInstanceCost($rangeStart, $now)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getSettings(): array
    {
        try {
            $settings = (array)$this->extensionConfiguration->get('agent_nexus');
            return [
                'llmEnabled' => $settings['a2uiLlmEnabled'] ?? true,
                'llmModel' => $settings['a2uiLlmModel'] ?? '',
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    // -----------------------------------------------------------------
    // LLM path
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $completion
     * @param array<string, mixed> $context
     */
    private function recordUsage(array $completion, array $context): void
    {
        $source = ($context['source'] ?? '') === LlmUsageTracker::SOURCE_FRONTEND
            ? LlmUsageTracker::SOURCE_FRONTEND
            : LlmUsageTracker::SOURCE_BACKEND;
        $this->usageTracker->record(
            'a2ui',
            $source,
            $completion['model'] !== '' ? (string)$completion['model'] : 'default',
            (int)$completion['promptTokens'],
            (int)$completion['completionTokens'],
            $completion['cost'] !== null ? (float)$completion['cost'] : null,
            (int)($GLOBALS['BE_USER']->user['uid'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildSystemPrompt(array $context = []): string
    {
        $catalog = $this->describeCatalog();

        $businessContext = trim((string)($context['businessContext'] ?? ''));
        $businessBlock = $businessContext !== ''
            ? "\n\nBusiness context (tailor field choices, labels and wording to this, but still only use the catalog):\n" . $businessContext
            : '';

        return <<<PROMPT
You are an A2UI v1.0 agent. You receive a natural-language request from a TYPO3
backend editor and you answer ONLY with a single JSON object: an A2UI v1.0
`createSurface` message that describes the user interface the editor needs.

A2UI v1.0 rules you MUST follow:
- The top-level object is: {"version": "v1.0", "createSurface": { ... }}.
- `createSurface` has: "surfaceId" (string), "catalogId" (keep it as
  "https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json"),
  "sendDataModel": true, "components" (a FLAT array), and "dataModel" (object).
- "components" is a FLAT adjacency list. Each component has a unique "id", a
  "component" name from the catalog below, its properties at the TOP level, and -
  for containers only - a "children" array that references child ids (NEVER nest
  component objects).
- EXACTLY ONE component must have "id": "root". Make it a "Column" or "Card".
- Input components bind their value to the data model with a JSON Pointer object:
  "value": {"path": "/fieldName"}. Add a matching key to "dataModel" with a
  sensible default (usually "").
- The primary action button must carry an action:
  "action": {"event": {"name": "<verb>", "context": {"<field>": {"path": "/<field>"}}, "wantResponse": true}}.
- Add validation with "checks" on inputs - an array of
  {"type":"required"|"email"|"length"|"numeric"|"regex","error":"..."} (length/
  numeric accept "min"/"max"; regex accepts "pattern"). The form will not submit
  until all checks pass.
- For a repeating set of fields over an array, use a "List" whose children is a
  template: "children": {"path": "/items", "componentId": "<templateId>"}. The
  referenced component is rendered once per array item; inside it, relative paths
  (no leading "/") resolve within the current item and {"path":"@index"} is the
  0-based position. Only use this when the data model actually has that array.
- A display value may be a computed function instead of a literal/binding, e.g.
  "text": {"function":"formatCurrency","args":[{"path":"/total"},"EUR"]}. Available:
  formatString, formatNumber, formatCurrency, formatDate, pluralize, and, or, not.
- Prefer simple forms. Most requests need only TextField/Textarea/ChoicePicker/
  CheckBox/DateTimeInput inside a Card; reach for List/Tabs/Modal/Slider/media only
  when the request clearly calls for them.
- Use ONLY the components and properties listed below. Do not invent components,
  properties, HTML, scripts, or URLs. Output JSON only - no markdown fences, no prose.

Catalog (component: allowed properties):
{$catalog}{$businessBlock}

Example:
{"version":"v1.0","createSurface":{"surfaceId":"page_form","catalogId":"https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json","sendDataModel":true,"components":[{"id":"root","component":"Card","title":"New page","children":["title","submit"]},{"id":"title","component":"TextField","label":"Page title","value":{"path":"/title"},"required":true},{"id":"submit","component":"Button","text":"Create page","variant":"primary","action":{"event":{"name":"createPage","context":{"title":{"path":"/title"}},"wantResponse":true}}}],"dataModel":{"title":""}}}
PROMPT;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildUserPrompt(string $intent, array $context): string
    {
        $language = (string)($context['language'] ?? 'en');
        return sprintf(
            "Editor request: \"%s\"\nWrite all visible labels in this language code: %s.\nReturn the A2UI v1.0 createSurface JSON now.",
            $intent,
            $language !== '' ? $language : 'en',
        );
    }

    private function describeCatalog(): string
    {
        $lines = [];
        foreach ($this->registry->getCatalogManifest() as $name => $config) {
            $suffix = $config['container'] ? ' [container, uses children]' : '';
            $props = $config['allowedProps'] === [] ? '(any)' : implode(', ', $config['allowedProps']);
            $lines[] = sprintf('- %s: %s%s', $name, $props, $suffix);
        }
        return implode("\n", $lines);
    }

    /**
     * Parse + harden an LLM response into a renderable surface, or null if it
     * cannot be salvaged (caller then falls back).
     *
     * @param array<string, mixed> $raw
     */
    private function buildSurfaceFromLlm(array $raw, string $intent): ?Surface
    {
        $surface = Surface::fromMessage($raw);

        // Drop anything outside the trusted catalog; strip disallowed props.
        $clean = [];
        $validIds = [];
        foreach ($surface->getComponents() as $component) {
            $safe = $this->registry->sanitize($component);
            if ($safe !== null) {
                $clean[] = $safe;
                $validIds[$safe->getId()] = true;
            }
        }
        if ($clean === []) {
            return null;
        }

        // Re-point children at surviving ids only, so a dropped component never
        // leaves a dangling reference.
        $resolved = [];
        foreach ($clean as $component) {
            if (!$component->hasChildren()) {
                $resolved[] = $component;
                continue;
            }
            $template = $component->getChildrenTemplate();
            if ($template !== null) {
                // List template: keep it only if the referenced template survived.
                $children = isset($validIds[$template['componentId']]) ? $component->getChildren() : [];
            } else {
                $children = array_values(array_filter(
                    $component->getChildIds(),
                    static fn(string $id): bool => isset($validIds[$id]),
                ));
            }
            $resolved[] = new Component(
                $component->getId(),
                $component->getComponent(),
                $component->getProperties(),
                $children,
                $component->getAction(),
            );
        }

        $rebuilt = new Surface(
            $surface->getSurfaceId() !== '' ? $surface->getSurfaceId() : 'surface',
            $resolved,
            $surface->getDataModel(),
            true,
            Surface::DEFAULT_CATALOG,
        );

        // A surface is only usable if it has a root the renderer can start from.
        return $rebuilt->getRoot() !== null ? $rebuilt : null;
    }

    // -----------------------------------------------------------------
    // Deterministic fallback (offline, no API key required)
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $context
     */
    private function buildFallback(string $intent, array $context): Surface
    {
        $needle = strtolower($intent);
        return match (true) {
            str_contains($needle, 'page') || str_contains($needle, 'seite')
                => $this->pageForm(),
            str_contains($needle, 'content') || str_contains($needle, 'inhalt')
                => $this->contentForm(),
            str_contains($needle, 'seo')
                => $this->seoForm(),
            str_contains($needle, 'schedul') || str_contains($needle, 'termin') || str_contains($needle, 'publish')
                => $this->scheduleForm(),
            str_contains($needle, 'event') || str_contains($needle, 'registration') || str_contains($needle, 'anmeld')
                => $this->eventRegistrationForm(),
            str_contains($needle, 'newsletter') || str_contains($needle, 'signup') || str_contains($needle, 'subscribe')
                => $this->newsletterForm(),
            str_contains($needle, 'quote') || str_contains($needle, 'angebot') || str_contains($needle, 'offer') || str_contains($needle, 'estimate') || str_contains($needle, 'price')
                => $this->quoteForm(),
            str_contains($needle, 'call') || str_contains($needle, 'phone') || str_contains($needle, 'rückruf') || str_contains($needle, 'ruckruf')
                => $this->callbackForm(),
            str_contains($needle, 'job') || str_contains($needle, 'apply') || str_contains($needle, 'career') || str_contains($needle, 'bewerb')
                => $this->applicationForm(),
            default
                => $this->contactForm($intent),
        };
    }

    /**
     * Assemble a surface from a list of [id, component, props, action?] children
     * wrapped in a Card root.
     *
     * @param array<int, Component> $children
     * @param array<string, mixed>  $dataModel
     */
    private function form(string $surfaceId, string $title, array $children, array $dataModel): Surface
    {
        $childIds = array_map(static fn(Component $c): string => $c->getId(), $children);
        $root = new Component('root', 'Card', ['title' => $title], $childIds);
        return new Surface($surfaceId, array_merge([$root], $children), $dataModel);
    }

    private function pageForm(): Surface
    {
        return $this->form('create_page', 'Create a new page', [
            new Component('pageTitle', 'TextField', [
                'label' => 'Page title', 'placeholder' => 'A clear, descriptive title',
                'required' => true, 'maxlength' => 255, 'value' => ['path' => '/pageTitle'],
            ]),
            new Component('pageType', 'ChoicePicker', [
                'label' => 'Page type', 'value' => ['path' => '/pageType'],
                'options' => [
                    ['value' => 'standard', 'label' => 'Standard'],
                    ['value' => 'link', 'label' => 'Link to external URL'],
                    ['value' => 'shortcut', 'label' => 'Shortcut'],
                    ['value' => 'spacer', 'label' => 'Spacer'],
                ],
            ]),
            new Component('pageSlug', 'TextField', [
                'label' => 'URL segment (slug)', 'placeholder' => 'auto-generated', 'value' => ['path' => '/pageSlug'],
            ]),
            new Component('hideInMenu', 'CheckBox', [
                'label' => 'Hide in menu', 'value' => ['path' => '/hideInMenu'],
            ]),
            new Component('publishDate', 'DateTimeInput', [
                'label' => 'Publish date', 'mode' => 'date', 'value' => ['path' => '/publishDate'],
            ]),
            new Component('submit', 'Button', ['text' => 'Create page', 'variant' => 'primary'], [], [
                'event' => ['name' => 'createPage', 'context' => [
                    'title' => ['path' => '/pageTitle'], 'type' => ['path' => '/pageType'], 'slug' => ['path' => '/pageSlug'],
                ], 'wantResponse' => true],
            ]),
        ], [
            'pageTitle' => '', 'pageType' => 'standard', 'pageSlug' => '', 'hideInMenu' => false, 'publishDate' => '',
        ]);
    }

    private function contentForm(): Surface
    {
        return $this->form('edit_content', 'Edit content element', [
            new Component('heading', 'TextField', [
                'label' => 'Heading', 'placeholder' => 'H1, H2 or H3', 'required' => true, 'value' => ['path' => '/heading'],
            ]),
            new Component('type', 'ButtonGroup', [
                'label' => 'Content type', 'value' => ['path' => '/type'],
                'options' => ['Text', 'Text & Media', 'Image only', 'HTML'],
            ]),
            new Component('body', 'Textarea', [
                'label' => 'Body', 'rows' => 10, 'placeholder' => 'Enter your content...', 'value' => ['path' => '/body'],
            ]),
            new Component('save', 'Button', ['text' => 'Save', 'variant' => 'success'], [], [
                'event' => ['name' => 'saveContent', 'context' => [
                    'heading' => ['path' => '/heading'], 'type' => ['path' => '/type'], 'body' => ['path' => '/body'],
                ], 'wantResponse' => true],
            ]),
        ], ['heading' => '', 'type' => 'Text', 'body' => '']);
    }

    private function seoForm(): Surface
    {
        return $this->form('seo_metadata', 'SEO metadata', [
            new Component('metaTitle', 'TextField', [
                'label' => 'SEO title', 'placeholder' => 'Max. 60 characters', 'maxlength' => 60, 'value' => ['path' => '/metaTitle'],
            ]),
            new Component('metaDescription', 'Textarea', [
                'label' => 'Meta description', 'placeholder' => '150-160 characters recommended', 'rows' => 3, 'value' => ['path' => '/metaDescription'],
            ]),
            new Component('focusKeyword', 'TextField', [
                'label' => 'Focus keyword', 'placeholder' => 'Main keyword for this page', 'value' => ['path' => '/focusKeyword'],
            ]),
            new Component('noindex', 'CheckBox', ['label' => 'Exclude from search engines (noindex)', 'value' => ['path' => '/noindex']]),
            new Component('update', 'Button', ['text' => 'Update SEO data', 'variant' => 'primary'], [], [
                'event' => ['name' => 'updateSeo', 'context' => [
                    'title' => ['path' => '/metaTitle'], 'description' => ['path' => '/metaDescription'], 'keyword' => ['path' => '/focusKeyword'],
                ], 'wantResponse' => true],
            ]),
        ], ['metaTitle' => '', 'metaDescription' => '', 'focusKeyword' => '', 'noindex' => false]);
    }

    private function scheduleForm(): Surface
    {
        return $this->form('schedule_publication', 'Schedule publication', [
            new Component('startDate', 'DateTimeInput', ['label' => 'Publish at', 'mode' => 'datetime', 'value' => ['path' => '/startDate']]),
            new Component('endDate', 'DateTimeInput', ['label' => 'Expire at (optional)', 'mode' => 'datetime', 'value' => ['path' => '/endDate']]),
            new Component('schedule', 'Button', ['text' => 'Set schedule', 'variant' => 'primary'], [], [
                'event' => ['name' => 'saveSchedule', 'context' => [
                    'start' => ['path' => '/startDate'], 'end' => ['path' => '/endDate'],
                ], 'wantResponse' => true],
            ]),
        ], ['startDate' => '', 'endDate' => '']);
    }

    private function eventRegistrationForm(): Surface
    {
        return $this->form('event_registration', 'Event registration', [
            new Component('fullName', 'TextField', ['label' => 'Full name', 'required' => true, 'value' => ['path' => '/fullName']]),
            new Component('email', 'TextField', ['label' => 'Email', 'inputType' => 'email', 'required' => true, 'value' => ['path' => '/email']]),
            new Component('ticket', 'ChoicePicker', [
                'label' => 'Ticket', 'value' => ['path' => '/ticket'],
                'options' => [
                    ['value' => 'standard', 'label' => 'Standard'],
                    ['value' => 'vip', 'label' => 'VIP'],
                    ['value' => 'student', 'label' => 'Student'],
                ],
            ]),
            new Component('attendees', 'TextField', ['label' => 'Number of attendees', 'inputType' => 'number', 'value' => ['path' => '/attendees']]),
            new Component('newsletter', 'CheckBox', ['label' => 'Keep me posted about future events', 'value' => ['path' => '/newsletter']]),
            new Component('register', 'Button', ['text' => 'Register', 'variant' => 'primary'], [], [
                'event' => ['name' => 'registerForEvent', 'context' => [
                    'name' => ['path' => '/fullName'], 'email' => ['path' => '/email'], 'ticket' => ['path' => '/ticket'],
                ], 'wantResponse' => true],
            ]),
        ], ['fullName' => '', 'email' => '', 'ticket' => 'standard', 'attendees' => '1', 'newsletter' => true]);
    }

    private function newsletterForm(): Surface
    {
        return $this->form('newsletter_signup', 'Newsletter signup', [
            new Component('intro', 'Text', ['text' => 'Get product news once a month. No spam, unsubscribe any time.', 'variant' => 'muted']),
            new Component('email', 'TextField', ['label' => 'Email address', 'inputType' => 'email', 'required' => true, 'value' => ['path' => '/email']]),
            new Component('consent', 'CheckBox', ['label' => 'I agree to the privacy policy', 'value' => ['path' => '/consent']]),
            new Component('subscribe', 'Button', ['text' => 'Subscribe', 'variant' => 'primary'], [], [
                'event' => ['name' => 'subscribeNewsletter', 'context' => ['email' => ['path' => '/email']], 'wantResponse' => true],
            ]),
        ], ['email' => '', 'consent' => false]);
    }

    private function quoteForm(): Surface
    {
        return $this->form('request_quote', 'Request a quote', [
            new Component('name', 'TextField', ['label' => 'Your name', 'required' => true, 'value' => ['path' => '/name']]),
            new Component('email', 'TextField', ['label' => 'Email', 'inputType' => 'email', 'required' => true, 'value' => ['path' => '/email']]),
            new Component('projectType', 'ChoicePicker', [
                'label' => 'What do you need?', 'value' => ['path' => '/projectType'],
                'options' => [
                    ['value' => 'advisory', 'label' => 'Advisory'],
                    ['value' => 'implementation', 'label' => 'Implementation'],
                    ['value' => 'managed', 'label' => 'Managed service'],
                    ['value' => 'other', 'label' => 'Something else'],
                ],
            ]),
            new Component('budget', 'ChoicePicker', [
                'label' => 'Indicative budget', 'value' => ['path' => '/budget'],
                'options' => [
                    ['value' => 'under10k', 'label' => 'Under €10k'],
                    ['value' => '10to50k', 'label' => '€10k–€50k'],
                    ['value' => 'over50k', 'label' => 'Over €50k'],
                    ['value' => 'unknown', 'label' => 'Not sure yet'],
                ],
            ]),
            new Component('details', 'Textarea', ['label' => 'Project details', 'rows' => 4, 'value' => ['path' => '/details']]),
            new Component('submit', 'Button', ['text' => 'Request quote', 'variant' => 'primary'], [], [
                'event' => ['name' => 'requestQuote', 'context' => [
                    'name' => ['path' => '/name'], 'email' => ['path' => '/email'], 'projectType' => ['path' => '/projectType'],
                    'budget' => ['path' => '/budget'], 'details' => ['path' => '/details'],
                ], 'wantResponse' => true],
            ]),
        ], ['name' => '', 'email' => '', 'projectType' => 'advisory', 'budget' => 'unknown', 'details' => '']);
    }

    private function callbackForm(): Surface
    {
        return $this->form('request_callback', 'Request a callback', [
            new Component('name', 'TextField', ['label' => 'Your name', 'required' => true, 'value' => ['path' => '/name']]),
            new Component('phone', 'TextField', ['label' => 'Phone number', 'required' => true, 'value' => ['path' => '/phone']]),
            new Component('when', 'DateTimeInput', ['label' => 'Best time to call', 'mode' => 'datetime', 'value' => ['path' => '/when']]),
            new Component('topic', 'TextField', ['label' => 'What is it about?', 'value' => ['path' => '/topic']]),
            new Component('submit', 'Button', ['text' => 'Request callback', 'variant' => 'primary'], [], [
                'event' => ['name' => 'requestCallback', 'context' => [
                    'name' => ['path' => '/name'], 'phone' => ['path' => '/phone'], 'when' => ['path' => '/when'], 'topic' => ['path' => '/topic'],
                ], 'wantResponse' => true],
            ]),
        ], ['name' => '', 'phone' => '', 'when' => '', 'topic' => '']);
    }

    private function applicationForm(): Surface
    {
        return $this->form('apply', 'Apply for a position', [
            new Component('name', 'TextField', ['label' => 'Full name', 'required' => true, 'value' => ['path' => '/name']]),
            new Component('email', 'TextField', ['label' => 'Email', 'inputType' => 'email', 'required' => true, 'value' => ['path' => '/email']]),
            new Component('position', 'TextField', ['label' => 'Position you are applying for', 'value' => ['path' => '/position']]),
            new Component('motivation', 'Textarea', ['label' => 'Why you?', 'rows' => 4, 'value' => ['path' => '/motivation']]),
            new Component('submit', 'Button', ['text' => 'Send application', 'variant' => 'primary'], [], [
                'event' => ['name' => 'submitApplication', 'context' => [
                    'name' => ['path' => '/name'], 'email' => ['path' => '/email'], 'position' => ['path' => '/position'],
                ], 'wantResponse' => true],
            ]),
        ], ['name' => '', 'email' => '', 'position' => '', 'motivation' => '']);
    }

    private function contactForm(string $intent): Surface
    {
        return $this->form('contact', 'Get in touch', [
            new Component('name', 'TextField', ['label' => 'Your name', 'required' => true, 'value' => ['path' => '/name']]),
            new Component('email', 'TextField', ['label' => 'Email', 'inputType' => 'email', 'required' => true, 'value' => ['path' => '/email']]),
            new Component('subject', 'TextField', ['label' => 'Subject', 'value' => ['path' => '/subject']]),
            new Component('message', 'Textarea', ['label' => 'How can we help?', 'rows' => 5, 'required' => true, 'value' => ['path' => '/message']]),
            new Component('consent', 'CheckBox', ['label' => 'I agree to be contacted about my request', 'value' => ['path' => '/consent']]),
            new Component('submit', 'Button', ['text' => 'Send message', 'variant' => 'primary'], [], [
                'event' => ['name' => 'sendMessage', 'context' => [
                    'name' => ['path' => '/name'], 'email' => ['path' => '/email'], 'subject' => ['path' => '/subject'], 'message' => ['path' => '/message'],
                ], 'wantResponse' => true],
            ]),
        ], ['name' => '', 'email' => '', 'subject' => $intent, 'message' => '', 'consent' => false]);
    }
}
