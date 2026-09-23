<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\GenerationRequest;
use Webconsulting\AgentNexus\A2ui\Http\A2uiProblem;
use Webconsulting\AgentNexus\Shared\Http\PluginSettings;
use Webconsulting\AgentNexus\Shared\Http\WidgetContext;
use Webconsulting\AgentNexus\Shared\Store\ObjectKind;
use Webconsulting\AgentNexus\Shared\Store\ObjectStore;
use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;

/**
 * The A2UI binding behind the public endpoints and the backend playground:
 * creates surfaces and answers what renderers report about them.
 *
 * Every surface is a protocol object (kind Surface, object id = surface id)
 * whose payload is `{version, messages, dataModel, actions}`: every message
 * the agent sent, the data model as the renderer last reported it, and every
 * message the renderer sent. A form a visitor submits is exactly that data
 * model — there is no inquiry table any more. States: created → submitted, or
 * created → deleted.
 */
final readonly class SurfaceService
{
    public const string STATE_CREATED = 'created';
    public const string STATE_SUBMITTED = 'submitted';
    public const string STATE_DELETED = 'deleted';

    /** The CType of the widget whose FlexForm shapes prompts and confirmations. */
    public const string WIDGET_CTYPE = 'agentnexus_inquiry';

    public const int MAX_INTENT = 600;

    public function __construct(
        private AgentService $agent,
        private MessageBuilder $messages,
        private VersionNegotiator $negotiator,
        private RendererMessageParser $parser,
        private ActionHandler $handler,
        private ObjectStore $store,
        private WidgetContext $widgetContext,
        private PluginSettings $pluginSettings,
    ) {}

    /**
     * Generate a surface for `{"intent", "version"?, capabilities?, "locale"?, "agentNexus"?}`.
     *
     * @param array<string, mixed> $body
     * @return array{messages: list<array<string, mixed>>, surfaceId: string, version: string, provenance: array{mode: string, label: string, reason?: string}, notes: list<string>}
     * @throws A2uiProblem
     */
    public function create(array $body, bool $public, bool $modelAllowed = true, int $beUser = 0): array
    {
        $intent = $body['intent'] ?? null;
        if (!is_string($intent) || trim($intent) === '') {
            throw A2uiProblem::invalid('/intent', 'Describe the interface you need in "intent", e.g. "a contact form".');
        }
        $intent = mb_substr(trim($intent), 0, self::MAX_INTENT);
        $version = $this->negotiator->negotiate($body);

        $widget = $this->widgetContext->from($body);
        $settings = $widget !== null ? $this->pluginSettings->forContentElement($widget['ce'], self::WIDGET_CTYPE) : [];
        $businessContext = is_string($settings['business_context'] ?? null) ? mb_substr(trim($settings['business_context']), 0, 2000) : '';

        $result = $this->agent->generate(new GenerationRequest(
            $intent,
            $version,
            $public,
            $this->language($body['locale'] ?? null),
            $businessContext,
            $modelAllowed,
            $beUser,
        ));
        $surface = $result->surface;
        $messages = $this->messages->surface($surface, $version);

        $object = new ProtocolObject(
            ObjectKind::Surface,
            $surface->surfaceId,
            '',
            self::STATE_CREATED,
            $this->channel($public, $widget)->value,
            $intent,
            [
                'version' => $version->value,
                'messages' => $messages,
                'dataModel' => MessageBuilder::object($surface->dataModel),
                'actions' => [],
            ],
            [],
            $widget !== null ? $this->widgetContext->storagePid($widget['page']) : 0,
            $beUser,
        );
        $this->store->save($object->withState(self::STATE_CREATED, $result->label()));

        return [
            'messages' => $messages,
            'surfaceId' => $surface->surfaceId,
            'version' => $version->value,
            'provenance' => $result->provenance(),
            'notes' => $result->notes,
        ];
    }

    /**
     * Answer a renderer-to-agent message about one of this agent's surfaces.
     *
     * @param array<string, mixed> $body
     * @return array{messages: list<array<string, mixed>>, surfaceId: string}
     * @throws A2uiProblem
     */
    public function receive(array $body): array
    {
        $message = $this->parser->parse($body);
        $surfaceId = $message->surfaceId();

        $object = $this->store->find(ObjectKind::Surface, $surfaceId);
        if ($object === null) {
            throw new A2uiProblem(404, 'SURFACE_NOT_FOUND', sprintf('This agent has no surface "%s".', $surfaceId), $surfaceId, version: $message->version);
        }
        $version = A2uiVersion::fromWire($object->payload['version'] ?? null) ?? A2uiVersion::DEFAULT;
        if ($version !== $message->version) {
            throw A2uiProblem::invalid('/version', sprintf('The surface was created with A2UI %s; answer in that version.', $version->value), $surfaceId, $version);
        }
        if ($object->state === self::STATE_DELETED) {
            throw new A2uiProblem(410, 'SURFACE_DELETED', sprintf('The surface "%s" was deleted.', $surfaceId), $surfaceId, version: $version);
        }
        if ($object->state === self::STATE_SUBMITTED && $message->isAction()) {
            throw new A2uiProblem(409, 'SURFACE_ALREADY_SUBMITTED', sprintf('The surface "%s" was already submitted.', $surfaceId), $surfaceId, version: $version);
        }

        $widget = $this->widgetContext->from($body);
        $settings = $widget !== null ? $this->pluginSettings->forContentElement($widget['ce'], self::WIDGET_CTYPE) : [];
        $confirmation = is_string($settings['success_message'] ?? null) ? $settings['success_message'] : '';

        $sent = $this->listOf($object->payload['messages'] ?? null);
        $outcome = $this->handler->answer($message, $sent, $confirmation);

        $payload = $object->payload;
        $payload['messages'] = [...$sent, ...$outcome->messages];
        $payload['actions'] = [...$this->listOf($payload['actions'] ?? null), $message->toRecord()];
        if ($message->dataModel !== null) {
            $payload['dataModel'] = MessageBuilder::object($message->dataModel);
        } elseif ($message->isAction() && $message->context() !== [] && !$this->sendsDataModel($sent)) {
            $payload['dataModel'] = $message->context();
        }

        $updated = $object->withPayload($payload);
        $updated = $outcome->state !== null
            ? $updated->withState($outcome->state, $outcome->note)
            : $updated->withState($object->state, $outcome->note);
        $this->store->save($updated);

        return ['messages' => $outcome->messages, 'surfaceId' => $surfaceId];
    }

    /**
     * @param array{ce: int, page: int, url: string}|null $widget
     */
    private function channel(bool $public, ?array $widget): Channel
    {
        if (!$public) {
            return Channel::Backend;
        }
        return $widget !== null ? Channel::Widget : Channel::Api;
    }

    private function language(mixed $locale): string
    {
        if (is_string($locale) && preg_match('/^([a-z]{2,3})(?:[-_][A-Za-z0-9]+)*$/i', trim($locale), $match) === 1) {
            return strtolower($match[1]);
        }
        return 'en';
    }

    /**
     * @param list<array<string, mixed>> $sent
     */
    private function sendsDataModel(array $sent): bool
    {
        $create = $sent[0]['createSurface'] ?? null;
        return is_array($create) && ($create['sendDataModel'] ?? false) === true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        $list = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_array($item)) {
                $entry = [];
                foreach ($item as $key => $field) {
                    $entry[(string)$key] = $field;
                }
                $list[] = $entry;
            }
        }
        return $list;
    }
}
