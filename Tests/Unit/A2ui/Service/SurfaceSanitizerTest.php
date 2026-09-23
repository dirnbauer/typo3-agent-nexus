<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Component;
use Webconsulting\AgentNexus\A2ui\Domain\Model\SanitizedSurface;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceSanitizer;

/**
 * The sanitiser is the trust boundary: whatever a model writes, only the
 * official basic catalogue may leave the server.
 */
final class SurfaceSanitizerTest extends UnitTestCase
{
    private SurfaceSanitizer $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SurfaceSanitizer(new ComponentRegistry());
    }

    #[Test]
    public function aCleanSurfacePassesUnchangedAndWithoutNotes(): void
    {
        $components = [
            ['id' => 'root', 'component' => 'Card', 'child' => 'form'],
            ['id' => 'form', 'component' => 'Column', 'children' => ['title', 'send']],
            ['id' => 'title', 'component' => 'Text', 'text' => 'Hello', 'variant' => 'h2'],
            ['id' => 'send', 'component' => 'Button', 'child' => 'send_label', 'action' => ['event' => ['name' => 'send']]],
            ['id' => 'send_label', 'component' => 'Text', 'text' => 'Send'],
        ];

        $result = $this->sanitize($components, ['a' => 1]);

        self::assertSame([], $result->notes);
        self::assertSame($components, $this->arrays($result));
        self::assertSame(['a' => 1], $result->dataModel);
    }

    #[Test]
    public function aComponentOutsideTheCatalogueIsDroppedAndItsReferenceCut(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Column', 'children' => ['ok', 'evil']],
            ['id' => 'ok', 'component' => 'Text', 'text' => 'fine'],
            ['id' => 'evil', 'component' => 'ScriptInjection', 'src' => 'https://example.invalid/x.js'],
        ]);

        self::assertSame(['root', 'ok'], $this->ids($result));
        self::assertSame(['ok'], $this->component($result, 'root')->property('children'));
        self::assertNotSame([], $result->notes);
    }

    #[Test]
    public function propertiesTheCatalogueDoesNotDefineAreRemoved(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Text', 'text' => 'Hi', 'onClick' => 'alert(1)', 'html' => '<b>x</b>', 'style' => 'color:red'],
        ]);

        self::assertSame(['text' => 'Hi'], $this->component($result, 'root')->properties);
        self::assertStringContainsString('onClick, html, style', implode("\n", $result->notes));
    }

    #[Test]
    public function aTextareaBecomesALongTextField(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Textarea', 'label' => 'Message', 'rows' => 5, 'value' => ['path' => '/message']],
        ]);

        $field = $this->component($result, 'root');
        self::assertSame('TextField', $field->type);
        self::assertSame('longText', $field->property('variant'));
        self::assertFalse($field->has('rows'));
    }

    #[Test]
    public function aButtonGroupBecomesAChoicePickerShownAsChips(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'ButtonGroup', 'label' => 'Type', 'options' => ['Text', 'Image'], 'value' => 'Text'],
        ]);

        $picker = $this->component($result, 'root');
        self::assertSame('ChoicePicker', $picker->type);
        self::assertSame('chips', $picker->property('displayStyle'));
        self::assertSame([['label' => 'Text', 'value' => 'Text'], ['label' => 'Image', 'value' => 'Image']], $picker->property('options'));
        self::assertSame(['Text'], $picker->property('value'), 'The value of a ChoicePicker is a list of strings.');
    }

    #[Test]
    public function aButtonWithATextGetsATextChild(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Button', 'text' => 'Send', 'variant' => 'success', 'action' => ['event' => ['name' => 'send']]],
        ]);

        $button = $this->component($result, 'root');
        self::assertSame('root_label', $button->property('child'));
        self::assertSame('primary', $button->property('variant'));
        self::assertSame('Send', $this->component($result, 'root_label')->property('text'));
    }

    #[Test]
    public function aCardWithATitleGetsAColumnWithAHeading(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Card', 'title' => 'New page', 'children' => ['field']],
            ['id' => 'field', 'component' => 'TextField', 'label' => 'Title'],
        ]);

        self::assertSame('root_content', $this->component($result, 'root')->property('child'));
        self::assertSame(['root_title', 'field'], $this->component($result, 'root_content')->property('children'));
        self::assertSame(['text' => 'New page', 'variant' => 'h3'], $this->component($result, 'root_title')->properties);
    }

    #[Test]
    public function requiredTrueBecomesACheckWithTheRequiredFunction(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'TextField', 'label' => 'Email', 'required' => true, 'inputType' => 'email', 'value' => ['path' => '/email']],
        ]);

        $checks = $this->component($result, 'root')->property('checks');
        self::assertSame([
            ['condition' => ['call' => 'required', 'args' => ['value' => ['path' => '/email']]], 'message' => 'This field is required.'],
            ['condition' => ['call' => 'email', 'args' => ['value' => ['path' => '/email']]], 'message' => 'Enter a valid email address.'],
        ], $checks);
    }

    #[Test]
    public function theCheckShapeOfTheProseExamplesIsConverted(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'TextField', 'label' => 'Zip', 'value' => ['path' => '/zip'], 'checks' => [
                ['call' => 'regex', 'args' => ['value' => ['path' => '/zip'], 'pattern' => '^[0-9]{5}$'], 'message' => 'Five digits.'],
            ]],
        ]);

        self::assertSame(
            [['condition' => ['call' => 'regex', 'args' => ['value' => ['path' => '/zip'], 'pattern' => '^[0-9]{5}$']], 'message' => 'Five digits.']],
            $this->component($result, 'root')->property('checks'),
        );
    }

    #[Test]
    public function wantResponseAndActionIdDoNotSurvive(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Button', 'child' => 'label', 'action' => ['event' => [
                'name' => 'send',
                'context' => ['email' => ['path' => '/email'], 'bad' => null],
                'wantResponse' => true,
                'actionId' => 'a-1',
            ]]],
            ['id' => 'label', 'component' => 'Text', 'text' => 'Send'],
        ]);

        self::assertSame(
            ['event' => ['name' => 'send', 'context' => ['email' => ['path' => '/email']]]],
            $this->component($result, 'root')->property('action'),
        );
    }

    #[Test]
    public function aFunctionOutsideTheCatalogueIsNotCalled(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Column', 'children' => ['a', 'b']],
            ['id' => 'a', 'component' => 'Text', 'text' => ['call' => 'eval', 'args' => ['code' => 'x']]],
            ['id' => 'b', 'component' => 'Text', 'text' => ['call' => 'formatString', 'args' => ['value' => 'Hi ${/name}', 'extra' => 1]]],
        ]);

        self::assertSame(['root', 'b'], $this->ids($result), 'A Text whose text cannot be used has no text left.');
        self::assertSame(['call' => 'formatString', 'args' => ['value' => 'Hi ${/name}']], $this->component($result, 'b')->property('text'));
    }

    #[Test]
    public function openUrlOnlyTakesAbsoluteWebUrls(): void
    {
        $button = static fn(string $url): array => [
            ['id' => 'root', 'component' => 'Button', 'child' => 'label', 'action' => ['functionCall' => ['call' => 'openUrl', 'args' => ['url' => $url]]]],
            ['id' => 'label', 'component' => 'Text', 'text' => 'Open'],
        ];

        self::assertTrue($this->sanitize($button('https://example.org/'))->isRenderable());
        self::assertSame([], $this->sanitize($button('javascript:alert(1)'))->components, 'A button without a usable action is dropped.');
    }

    #[Test]
    public function anSvgPathMayOnlyContainPathData(): void
    {
        $icon = fn(string $path): SanitizedSurface => $this->sanitize([['id' => 'root', 'component' => 'Icon', 'name' => ['svgPath' => $path]]]);

        self::assertTrue($icon('M3 8.5l3 3 7-7Z')->isRenderable());
        self::assertFalse($icon('M3 8"/><script>alert(1)</script>')->isRenderable());
    }

    #[Test]
    public function missingChildrenAreCutAndAComponentThatNeedsOneIsDropped(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Column', 'children' => ['card', 'gone']],
            ['id' => 'card', 'component' => 'Card', 'child' => 'nowhere'],
        ]);

        self::assertSame(['root'], $this->ids($result));
        self::assertSame([], $this->component($result, 'root')->property('children'));
    }

    #[Test]
    public function aCycleIsBroken(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Column', 'children' => ['inner']],
            ['id' => 'inner', 'component' => 'Column', 'children' => ['root', 'leaf']],
            ['id' => 'leaf', 'component' => 'Text', 'text' => 'x'],
        ]);

        self::assertSame(['leaf'], $this->component($result, 'inner')->property('children'));
        self::assertStringContainsString('loop', implode("\n", $result->notes));
    }

    #[Test]
    public function aSurfaceWithoutARootGetsItsOnlyTopLevelComponentAsRoot(): void
    {
        $result = $this->sanitize([
            ['id' => 'form', 'component' => 'Column', 'children' => ['a']],
            ['id' => 'a', 'component' => 'Text', 'text' => 'x'],
        ]);

        self::assertSame(['root', 'a'], $this->ids($result));
    }

    #[Test]
    public function unreachableComponentsArePruned(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Text', 'text' => 'x'],
            ['id' => 'orphan', 'component' => 'Text', 'text' => 'y'],
        ]);

        self::assertSame(['root'], $this->ids($result));
    }

    #[Test]
    public function weightStaysOnlyOnChildrenOfARowOrColumn(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Card', 'child' => 'row', 'weight' => 2],
            ['id' => 'row', 'component' => 'Row', 'children' => ['a']],
            ['id' => 'a', 'component' => 'Text', 'text' => 'x', 'weight' => 1],
        ]);

        self::assertFalse($this->component($result, 'root')->has('weight'));
        self::assertSame(1, $this->component($result, 'a')->property('weight'));
    }

    #[Test]
    public function aListTemplateKeepsItsPathAndTemplateComponent(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'List', 'children' => ['componentId' => 'item', 'path' => '/items']],
            ['id' => 'item', 'component' => 'Text', 'text' => ['path' => 'name']],
        ], ['items' => [['name' => 'A']]]);

        self::assertSame(['componentId' => 'item', 'path' => '/items'], $this->component($result, 'root')->property('children'));
        self::assertSame(['path' => 'name'], $this->component($result, 'item')->property('text'));
    }

    #[Test]
    public function headingsBecomeMarkdownInV10WhichHasNoHeadingVariants(): void
    {
        $result = $this->sanitize([['id' => 'root', 'component' => 'Text', 'text' => 'Title', 'variant' => 'h2']], [], A2uiVersion::V1_0);

        self::assertSame(['text' => '## Title'], $this->component($result, 'root')->properties);
    }

    #[Test]
    public function theVersionDecidesWhichPropertiesExist(): void
    {
        $field = [['id' => 'root', 'component' => 'TextField', 'label' => 'Code', 'placeholder' => 'ABC', 'validationRegexp' => '^[A-Z]+$', 'value' => ['path' => '/code']]];

        $v091 = $this->component($this->sanitize($field), 'root');
        self::assertFalse($v091->has('placeholder'));
        self::assertSame('^[A-Z]+$', $v091->property('validationRegexp'));

        $v10 = $this->component($this->sanitize($field, [], A2uiVersion::V1_0), 'root');
        self::assertSame('ABC', $v10->property('placeholder'));
        self::assertFalse($v10->has('validationRegexp'));
        self::assertSame('regex', $v10->property('checks')[0]['condition']['call'] ?? null, 'v1.0 turns the pattern into a check.');
    }

    #[Test]
    public function aLiteralConditionIsNoCheckInV10(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'CheckBox', 'label' => 'OK', 'value' => false, 'checks' => [['condition' => true, 'message' => 'x']]],
        ], [], A2uiVersion::V1_0);

        self::assertFalse($this->component($result, 'root')->has('checks'));
    }

    #[Test]
    public function missingRequiredValuesAreFilledWithEmptyOnes(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Column', 'children' => ['box', 'pick']],
            ['id' => 'box', 'component' => 'CheckBox', 'label' => 'Agree'],
            ['id' => 'pick', 'component' => 'ChoicePicker', 'options' => [['label' => 'A', 'value' => 'a']]],
        ]);

        self::assertFalse($this->component($result, 'box')->property('value'));
        self::assertSame([], $this->component($result, 'pick')->property('value'));
    }

    #[Test]
    public function theDataModelMustBeAnObject(): void
    {
        $components = [['id' => 'root', 'component' => 'Text', 'text' => 'x']];

        self::assertSame([], $this->sanitize($components, ['a', 'b'])->dataModel);
        self::assertSame([], $this->sanitize($components, 'text')->dataModel);
        self::assertSame(['a' => ['b' => 1]], $this->sanitize($components, ['a' => ['b' => 1]])->dataModel);
    }

    #[Test]
    public function invalidIdsAreRejected(): void
    {
        $result = $this->sanitize([
            ['id' => 'root', 'component' => 'Column', 'children' => ['<script>']],
            ['id' => '<script>', 'component' => 'Text', 'text' => 'x'],
        ]);

        self::assertSame(['root'], $this->ids($result));
    }

    #[Test]
    public function withoutAnyUsableComponentThereIsNothingToRender(): void
    {
        self::assertFalse($this->sanitize('not a list')->isRenderable());
        self::assertFalse($this->sanitize([['component' => 'Text']])->isRenderable());
    }

    /**
     * @param array<array-key, mixed>|string $components
     */
    private function sanitize(array|string $components, mixed $dataModel = [], A2uiVersion $version = A2uiVersion::V0_9_1): SanitizedSurface
    {
        return $this->subject->sanitize($components, $dataModel, $version);
    }

    private function component(SanitizedSurface $surface, string $id): Component
    {
        foreach ($surface->components as $component) {
            if ($component->id === $id) {
                return $component;
            }
        }
        self::fail('No component "' . $id . '" in ' . implode(', ', $this->ids($surface)));
    }

    /**
     * @return list<string>
     */
    private function ids(SanitizedSurface $surface): array
    {
        return array_map(static fn(Component $component): string => $component->id, $surface->components);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function arrays(SanitizedSurface $surface): array
    {
        return array_map(static fn(Component $component): array => $component->toArray(), $surface->components);
    }
}
