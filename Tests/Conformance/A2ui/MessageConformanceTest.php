<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\A2ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Surface;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\A2ui\Service\ActionHandler;
use Webconsulting\AgentNexus\A2ui\Service\MessageBuilder;
use Webconsulting\AgentNexus\A2ui\Service\RendererMessageParser;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceGenerator;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceSanitizer;

/**
 * Every message shape Agent Nexus sends or accepts, against the official
 * schemas: the surfaces of every built-in example in both versions (each
 * message, and the list), the answers to actions (confirmation and
 * deleteSurface, in the list wrapper the endpoint returns), the error bodies,
 * the capabilities, and the renderer-to-agent messages the widget sends.
 * Plus the traps the specifications changed on the way.
 */
final class MessageConformanceTest extends A2uiConformanceTestCase
{
    /**
     * @return \Generator<string, array{string, A2uiVersion}>
     */
    public static function examples(): \Generator
    {
        foreach (array_keys(SurfaceGenerator::EXAMPLES) as $example) {
            foreach (A2uiVersion::cases() as $version) {
                yield $example . ' in ' . $version->value => [$example, $version];
            }
        }
    }

    #[Test]
    #[DataProvider('examples')]
    public function everyMessageOfEverySurfaceConforms(string $example, A2uiVersion $version): void
    {
        $messages = self::messages(self::surface($example, $version), $version);

        foreach ($messages as $message) {
            self::assertConformsTo(self::envelope($version), $message);
        }
        self::assertConformsTo(self::list($version), $messages);
    }

    #[Test]
    public function v091BuildsASurfaceInThreeMessages(): void
    {
        $messages = self::messages(self::surface('contact', A2uiVersion::V0_9_1), A2uiVersion::V0_9_1);

        self::assertSame(['createSurface', 'updateComponents', 'updateDataModel'], array_map(
            static fn(array $message): string => (string)array_key_last($message),
            $messages,
        ));
        self::assertSame('v0.9.1', $messages[0]['version']);
        self::assertSame(A2uiVersion::V0_9_1->catalogId(), $messages[0]['createSurface']['catalogId']);
        self::assertTrue($messages[0]['createSurface']['sendDataModel']);
        self::assertSame('/', $messages[2]['updateDataModel']['path']);
    }

    #[Test]
    public function v10CreatesASurfaceInOneMessage(): void
    {
        $messages = self::messages(self::surface('contact', A2uiVersion::V1_0), A2uiVersion::V1_0);

        self::assertCount(1, $messages);
        self::assertArrayHasKey('components', $messages[0]['createSurface']);
        self::assertArrayHasKey('dataModel', $messages[0]['createSurface']);
        self::assertArrayNotHasKey('theme', $messages[0]['createSurface']);
    }

    #[Test]
    #[DataProvider('versions')]
    public function anEmptyDataModelTravelsAsAnObject(A2uiVersion $version): void
    {
        $surface = self::surface('contact', $version);
        $messages = self::messages($surface->withContent($surface->components, []), $version);

        foreach ($messages as $message) {
            self::assertConformsTo(self::envelope($version), $message);
        }
        self::assertStringContainsString($version === A2uiVersion::V1_0 ? '"dataModel":{}' : '"value":{}', (string)json_encode($messages));
    }

    #[Test]
    #[DataProvider('versions')]
    public function theAnswersToActionsConform(A2uiVersion $version): void
    {
        $sent = self::messages(self::surface('quote', $version)->withSurfaceId('quote-1'), $version);
        $handler = new ActionHandler(new MessageBuilder(), new SurfaceSanitizer(new ComponentRegistry()));

        $confirmation = $handler->answer(self::action($version, 'requestQuote', 'submit', ['name' => 'Ada', 'projectType' => ['managed']]), $sent);
        $discard = $handler->answer(self::action($version, 'discard', 'discard', []), $sent);

        foreach ([...$confirmation->messages, ...$discard->messages] as $message) {
            self::assertConformsTo(self::envelope($version), $message);
        }
        self::assertConformsTo(self::wrapper($version), ['messages' => $confirmation->messages]);
        self::assertConformsTo(self::wrapper($version), ['messages' => $discard->messages]);
        self::assertConformsTo(self::wrapper($version), ['messages' => []]);
    }

    #[Test]
    #[DataProvider('versions')]
    public function theActionsTheRendererSendsConform(A2uiVersion $version): void
    {
        $action = [
            'version' => $version->value,
            'action' => [
                'name' => 'requestQuote',
                'surfaceId' => 'quote-1',
                'sourceComponentId' => 'submit',
                'timestamp' => '2026-09-23T10:15:00.123Z',
                'context' => ['name' => 'Ada', 'projectType' => ['managed'], 'consent' => true],
            ],
        ];
        $dataModel = ['version' => $version->value, 'surfaces' => ['quote-1' => ['name' => 'Ada']]];
        $capabilities = [$version->capabilitiesKey() => ['supportedCatalogIds' => [$version->catalogId()]]];

        if ($version === A2uiVersion::V1_0) {
            self::assertConformsTo(A2uiSchemas::V1_0_RENDERER, $action);
            self::assertConformsTo(A2uiSchemas::V1_0_RENDERER_LIST, [$action]);
            self::assertConformsTo(A2uiSchemas::V1_0_DATA_MODEL, $dataModel);
            self::assertConformsTo(A2uiSchemas::V1_0_RENDERER_CAPABILITIES, $capabilities);
        } else {
            self::assertConformsTo(A2uiSchemas::V0_9_CLIENT, $action);
            self::assertConformsTo(A2uiSchemas::V0_9_CLIENT_LIST, [$action]);
            self::assertConformsTo(A2uiSchemas::V0_9_DATA_MODEL, $dataModel);
            self::assertConformsTo(A2uiSchemas::V0_9_CLIENT_CAPABILITIES, $capabilities);
        }
        self::assertTrue((new RendererMessageParser())->parse($action + ['metadata' => [$version->dataModelMember() => $dataModel]])->isAction());
    }

    #[Test]
    #[DataProvider('versions')]
    public function theErrorBodiesConform(A2uiVersion $version): void
    {
        $builder = new MessageBuilder();
        $schema = $version === A2uiVersion::V1_0 ? A2uiSchemas::V1_0_RENDERER : A2uiSchemas::V0_9_CLIENT;

        self::assertConformsTo($schema, $builder->error($version, 'VALIDATION_FAILED', 'quote-1', 'No such component.', '/action/sourceComponentId'));
        self::assertConformsTo($schema, $builder->error($version, 'SURFACE_NOT_FOUND', 'quote-1', 'No such surface.'));
        self::assertConformsTo($schema, $builder->error($version, 'RATE_LIMITED', '', 'Too many requests.'));
    }

    #[Test]
    public function theCapabilitiesConformToBothSchemas(): void
    {
        $capabilities = (new ComponentRegistry())->capabilities();

        self::assertConformsTo(A2uiSchemas::V0_9_SERVER_CAPABILITIES, $capabilities);
        self::assertConformsTo(A2uiSchemas::V1_0_AGENT_CAPABILITIES, $capabilities);
    }

    #[Test]
    #[DataProvider('versions')]
    public function whateverAModelWritesTheSanitisedSurfaceConforms(A2uiVersion $version): void
    {
        $clean = (new SurfaceSanitizer(new ComponentRegistry()))->sanitize([
            ['id' => 'form', 'component' => 'Card', 'title' => 'Contact', 'children' => ['name', 'message', 'kind', 'send', 'modal']],
            ['id' => 'name', 'component' => 'TextField', 'label' => 'Name', 'required' => true, 'placeholder' => 'Ada', 'value' => ['path' => '/name']],
            ['id' => 'message', 'component' => 'Textarea', 'label' => 'Message', 'rows' => 4, 'value' => ['path' => '/message']],
            ['id' => 'kind', 'component' => 'ButtonGroup', 'label' => 'Kind', 'options' => ['A', 'B'], 'value' => 'A'],
            ['id' => 'send', 'component' => 'Button', 'text' => 'Send', 'action' => ['event' => ['name' => 'send', 'wantResponse' => true, 'context' => ['name' => ['path' => '/name']]]]],
            ['id' => 'modal', 'component' => 'Modal', 'title' => 'Help', 'children' => ['help']],
            ['id' => 'help', 'component' => 'Text', 'text' => 'Help text', 'variant' => 'h4'],
            ['id' => 'icon', 'component' => 'Icon', 'name' => 'user', 'size' => 'large'],
            ['id' => 'video', 'component' => 'Video', 'src' => 'https://example.org/a.mp4', 'autoplay' => true],
        ], ['name' => '', 'message' => ''], $version);
        $surface = new Surface('model-1', $clean->components, $clean->dataModel);

        self::assertTrue($clean->isRenderable());
        foreach (self::messages($surface, $version) as $message) {
            self::assertConformsTo(self::envelope($version), $message);
        }
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function v091Traps(): array
    {
        $create = ['surfaceId' => 's', 'catalogId' => A2uiVersion::V0_9_1->catalogId()];
        $components = static fn(array $component): array => ['version' => 'v0.9.1', 'updateComponents' => ['surfaceId' => 's', 'components' => [$component]]];
        return [
            'surfaceProperties (removed)' => ['createSurface', ['version' => 'v0.9.1', 'createSurface' => $create + ['surfaceProperties' => ['brand' => 'x']]]],
            'components inside createSurface' => ['createSurface', ['version' => 'v0.9.1', 'createSurface' => $create + ['components' => []]]],
            'no catalogId' => ['createSurface', ['version' => 'v0.9.1', 'createSurface' => ['surfaceId' => 's']]],
            'the v1.0 version' => ['createSurface', ['version' => 'v1.0', 'createSurface' => $create]],
            'two messages in one envelope' => ['envelope', ['version' => 'v0.9.1', 'createSurface' => $create, 'deleteSurface' => ['surfaceId' => 's']]],
            'a Textarea' => ['component', $components(['id' => 'root', 'component' => 'Textarea', 'label' => 'x'])],
            'a ButtonGroup' => ['component', $components(['id' => 'root', 'component' => 'ButtonGroup', 'options' => []])],
            'a Button with a text' => ['component', $components(['id' => 'root', 'component' => 'Button', 'child' => 'l', 'text' => 'Send', 'action' => ['event' => ['name' => 'x']]])],
            'wantResponse on an event' => ['component', $components(['id' => 'root', 'component' => 'Button', 'child' => 'l', 'action' => ['event' => ['name' => 'x', 'wantResponse' => true]]])],
            'a Card with a title' => ['component', $components(['id' => 'root', 'component' => 'Card', 'child' => 'c', 'title' => 'x'])],
            'a check without a message' => ['component', $components(['id' => 'root', 'component' => 'CheckBox', 'label' => 'x', 'value' => true, 'checks' => [['condition' => ['path' => '/x']]]])],
            'a 3.1 check' => ['component', $components(['id' => 'root', 'component' => 'CheckBox', 'label' => 'x', 'value' => true, 'checks' => [['type' => 'required', 'error' => 'x']]])],
            'a ChoicePicker value that is not a list' => ['component', $components(['id' => 'root', 'component' => 'ChoicePicker', 'options' => [['label' => 'A', 'value' => 'a']], 'value' => 'a'])],
            'an empty component list' => ['component', ['version' => 'v0.9.1', 'updateComponents' => ['surfaceId' => 's', 'components' => []]]],
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[Test]
    #[DataProvider('v091Traps')]
    public function v091RefusesWhatItNeverOrNoLongerHad(string $kind, array $message): void
    {
        self::assertViolates(A2uiSchemas::V0_9_ENVELOPE, $message, $kind);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function v10Traps(): array
    {
        return [
            'a theme (removed)' => [['version' => 'v1.0', 'createSurface' => ['surfaceId' => 's', 'theme' => ['primaryColor' => '#000000']]]],
            'surfaceProperties (removed)' => [['version' => 'v1.0', 'createSurface' => ['surfaceId' => 's', 'surfaceProperties' => []]]],
            'a data model list where an object is required' => [['version' => 'v1.0', 'createSurface' => ['surfaceId' => 's', 'dataModel' => []]]],
            'an update without a value' => [['version' => 'v1.0', 'updateDataModel' => ['surfaceId' => 's', 'path' => '/x']]],
            'a function call with a returnType' => [['version' => 'v1.0', 'createSurface' => ['surfaceId' => 's', 'components' => [
                ['id' => 'root', 'component' => 'Text', 'text' => ['call' => 'formatString', 'args' => ['value' => 'x'], 'returnType' => 'string']],
            ]]]],
            'a heading variant' => [['version' => 'v1.0', 'createSurface' => ['surfaceId' => 's', 'components' => [
                ['id' => 'root', 'component' => 'Text', 'text' => 'x', 'variant' => 'h2'],
            ]]]],
            'the reserved Surface component' => [['version' => 'v1.0', 'updateComponents' => ['surfaceId' => 's', 'components' => [
                ['id' => 'root', 'component' => 'Surface', 'child' => 'root'],
            ]]]],
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[Test]
    #[DataProvider('v10Traps')]
    public function v10RefusesWhatTheCandidateRemoved(array $message): void
    {
        self::assertViolates(A2uiSchemas::V1_0_ENVELOPE, $message);
    }

    #[Test]
    public function whatDiffersBetweenTheVersionsIsValidWhereItBelongs(): void
    {
        self::assertConformsTo(A2uiSchemas::V0_9_ENVELOPE, ['version' => 'v0.9.1', 'updateDataModel' => ['surfaceId' => 's', 'path' => '/x']], 'v0.9.1 deletes by leaving the value out.');
        self::assertConformsTo(A2uiSchemas::V1_0_ENVELOPE, ['version' => 'v1.0', 'updateDataModel' => ['surfaceId' => 's', 'path' => '/x', 'value' => null]], 'v1.0 deletes with null.');
        self::assertConformsTo(A2uiSchemas::V0_9_ENVELOPE, ['version' => 'v0.9', 'deleteSurface' => ['surfaceId' => 's']], 'v0.9 is still a valid v0.9.1 version.');
        self::assertConformsTo(A2uiSchemas::V1_0_ENVELOPE, ['version' => 'v1.0', 'createSurface' => ['surfaceId' => 's', 'components' => [
            ['id' => 'root', 'component' => 'CheckBox', 'label' => 'x', 'value' => true, 'checks' => [['condition' => ['path' => '/x']]]],
        ]]], 'A v1.0 check may leave the message out.');
    }

    #[Test]
    public function anActionIsTheMessageAndNothingElse(): void
    {
        $action = ['name' => 'a', 'surfaceId' => 's', 'sourceComponentId' => 'b', 'timestamp' => '2026-09-23T10:15:00Z', 'context' => []];

        self::assertViolates(A2uiSchemas::V0_9_CLIENT, ['version' => 'v0.9.1', 'action' => $action, 'metadata' => []], 'Metadata travels next to the message, not in it.');
        self::assertViolates(A2uiSchemas::V0_9_CLIENT, ['version' => 'v0.9.1', 'action' => ['context' => ['x']] + $action], 'The context is an object.');
        self::assertViolates(A2uiSchemas::V0_9_CLIENT, ['version' => 'v0.9.1', 'action' => array_diff_key($action, ['timestamp' => true])]);
        self::assertViolates(A2uiSchemas::V1_0_RENDERER, ['version' => 'v1.0', 'action' => ['wantResponse' => true] + $action] + ['actionResponse' => []]);
    }

    /**
     * @return \Generator<string, array{A2uiVersion}>
     */
    public static function versions(): \Generator
    {
        foreach (A2uiVersion::cases() as $version) {
            yield $version->value => [$version];
        }
    }

    private static function surface(string $example, A2uiVersion $version): Surface
    {
        $surface = (new SurfaceGenerator())->example($example, 'A request');
        $clean = (new SurfaceSanitizer(new ComponentRegistry()))->sanitize($surface->componentsToArray(), $surface->dataModel, $version);
        return $surface->withSurfaceId($example . '-1')->withContent($clean->components, $clean->dataModel);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function messages(Surface $surface, A2uiVersion $version): array
    {
        return (new MessageBuilder())->surface($surface, $version);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function action(A2uiVersion $version, string $name, string $source, array $context): \Webconsulting\AgentNexus\A2ui\Domain\Model\RendererMessage
    {
        return (new RendererMessageParser())->parse(['version' => $version->value, 'action' => [
            'name' => $name,
            'surfaceId' => 'quote-1',
            'sourceComponentId' => $source,
            'timestamp' => '2026-09-23T10:15:00Z',
            'context' => $context,
        ]]);
    }

    private static function envelope(A2uiVersion $version): string
    {
        return $version === A2uiVersion::V1_0 ? A2uiSchemas::V1_0_ENVELOPE : A2uiSchemas::V0_9_ENVELOPE;
    }

    private static function list(A2uiVersion $version): string
    {
        return $version === A2uiVersion::V1_0 ? A2uiSchemas::V1_0_LIST : A2uiSchemas::V0_9_LIST;
    }

    private static function wrapper(A2uiVersion $version): string
    {
        return $version === A2uiVersion::V1_0 ? A2uiSchemas::V1_0_LIST_WRAPPER : A2uiSchemas::V0_9_LIST_WRAPPER;
    }
}
