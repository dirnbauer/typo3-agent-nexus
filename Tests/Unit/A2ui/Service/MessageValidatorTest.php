<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\A2ui\Service\MessageBuilder;
use Webconsulting\AgentNexus\A2ui\Service\MessageValidator;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceGenerator;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceSanitizer;

final class MessageValidatorTest extends UnitTestCase
{
    private MessageValidator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new MessageValidator(new SurfaceSanitizer(new ComponentRegistry()));
    }

    #[Test]
    public function theMessagesOfABuiltInSurfaceAreValidInBothVersions(): void
    {
        foreach (A2uiVersion::cases() as $version) {
            $surface = (new SurfaceGenerator())->example('event');
            $clean = (new SurfaceSanitizer(new ComponentRegistry()))->sanitize($surface->componentsToArray(), $surface->dataModel, $version);
            $messages = (new MessageBuilder())->surface($surface->withContent($clean->components, $clean->dataModel), $version);

            self::assertSame([], $this->subject->validate($messages, $version), $version->value);
        }
    }

    #[Test]
    public function aStreamMustStartWithCreateSurface(): void
    {
        $errors = $this->subject->validate([
            ['version' => 'v0.9.1', 'updateComponents' => ['surfaceId' => 's', 'components' => [['id' => 'root', 'component' => 'Text', 'text' => 'x']]]],
        ], A2uiVersion::V0_9_1);

        self::assertContains('Message 1 (updateComponents) comes before createSurface.', $errors);
        self::assertContains('There is no createSurface message.', $errors);
    }

    #[Test]
    public function anEnvelopeCarriesExactlyOneMessage(): void
    {
        $errors = $this->subject->validate([
            ['version' => 'v0.9.1', 'createSurface' => ['surfaceId' => 's', 'catalogId' => A2uiVersion::V0_9_1->catalogId()], 'deleteSurface' => ['surfaceId' => 's']],
        ], A2uiVersion::V0_9_1);

        self::assertStringStartsWith('Message 1 must carry exactly one of', $errors[0]);
    }

    #[Test]
    public function aComponentOutsideTheCatalogueIsReported(): void
    {
        $errors = $this->subject->validate([
            ['version' => 'v0.9.1', 'createSurface' => ['surfaceId' => 's', 'catalogId' => A2uiVersion::V0_9_1->catalogId()]],
            ['version' => 'v0.9.1', 'updateComponents' => ['surfaceId' => 's', 'components' => [
                ['id' => 'root', 'component' => 'Column', 'children' => ['t']],
                ['id' => 't', 'component' => 'Textarea', 'label' => 'x'],
            ]]],
        ], A2uiVersion::V0_9_1);

        self::assertContains('Component "t" differs from what the v0.9.1 catalogue allows.', $errors);
    }

    #[Test]
    public function v10NeedsAValueInEveryDataModelUpdate(): void
    {
        $errors = $this->subject->validate([
            ['version' => 'v1.0', 'createSurface' => ['surfaceId' => 's', 'components' => [['id' => 'root', 'component' => 'Text', 'text' => 'x']]]],
            ['version' => 'v1.0', 'updateDataModel' => ['surfaceId' => 's', 'path' => '/x']],
        ], A2uiVersion::V1_0);

        self::assertSame(['Message 2: updateDataModel needs a "value" in v1.0 (null deletes).'], $errors);
    }

    #[Test]
    public function v091NeedsTheBasicCatalogueId(): void
    {
        $errors = $this->subject->validate([
            ['version' => 'v0.9.1', 'createSurface' => ['surfaceId' => 's']],
            ['version' => 'v0.9.1', 'updateComponents' => ['surfaceId' => 's', 'components' => [['id' => 'root', 'component' => 'Text', 'text' => 'x']]]],
        ], A2uiVersion::V0_9_1);

        self::assertSame(['Message 1 does not name the basic catalogue ' . A2uiVersion::V0_9_1->catalogId() . '.'], $errors);
    }
}
