<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Domain;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;

/**
 * The registry's own behaviour. That its definitions match the published
 * catalogues is proven by Tests/Conformance/A2ui/CatalogueConformanceTest.
 */
final class ComponentRegistryTest extends UnitTestCase
{
    private ComponentRegistry $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ComponentRegistry();
    }

    #[Test]
    public function theBasicCatalogueHasEighteenComponentsInBothVersions(): void
    {
        self::assertCount(18, $this->subject->components(A2uiVersion::V0_9_1));
        self::assertCount(18, $this->subject->components(A2uiVersion::V1_0));
        self::assertSame(
            array_keys($this->subject->components(A2uiVersion::V0_9_1)),
            array_keys($this->subject->components(A2uiVersion::V1_0)),
        );
    }

    #[Test]
    public function componentsOfOlderDraftsAreNotInTheCatalogue(): void
    {
        self::assertFalse($this->subject->isRegistered('Textarea'));
        self::assertFalse($this->subject->isRegistered('ButtonGroup'));
        self::assertFalse($this->subject->isRegistered('Surface'));
        self::assertTrue($this->subject->isRegistered('ChoicePicker'));
    }

    #[Test]
    public function aButtonTakesAChildAndAnActionAndNothingLikeAText(): void
    {
        $button = $this->subject->component('Button');

        self::assertNotNull($button);
        self::assertSame(['child', 'action'], $button->requiredProperties());
        self::assertNull($button->property('text'));
        self::assertNull($button->property('label'));
        self::assertTrue($button->isCheckable());
    }

    #[Test]
    public function theVersionsDifferWhereThePublishedCataloguesDiffer(): void
    {
        $text091 = $this->subject->component('Text', A2uiVersion::V0_9_1);
        $text10 = $this->subject->component('Text', A2uiVersion::V1_0);
        self::assertContains('h2', $text091?->property('variant')->values ?? []);
        self::assertSame(['caption', 'body'], $text10?->property('variant')?->values);

        self::assertNotNull($this->subject->component('TextField', A2uiVersion::V0_9_1)?->property('validationRegexp'));
        self::assertNull($this->subject->component('TextField', A2uiVersion::V1_0)?->property('validationRegexp'));
        self::assertNotNull($this->subject->component('TextField', A2uiVersion::V1_0)?->property('placeholder'));
        self::assertNotNull($this->subject->component('Slider', A2uiVersion::V1_0)?->property('steps'));
        self::assertNotNull($this->subject->component('Video', A2uiVersion::V1_0)?->property('posterUrl'));
        self::assertSame([], $this->subject->themeProperties(A2uiVersion::V1_0), 'v1.0 removed the theme.');
    }

    #[Test]
    public function checksReturnABooleanInV091AndAValidationResultInV10(): void
    {
        self::assertSame('boolean', $this->subject->function('required', A2uiVersion::V0_9_1)?->returnType);
        self::assertSame('validationResult', $this->subject->function('required', A2uiVersion::V1_0)?->returnType);
        self::assertNull($this->subject->function('@index', A2uiVersion::V0_9_1));
        self::assertSame('number', $this->subject->function('@index', A2uiVersion::V1_0)?->returnType);
        self::assertNull($this->subject->function('eval'));
    }

    #[Test]
    public function theCapabilitiesAdvertiseBothBasicCatalogues(): void
    {
        self::assertSame([
            'v0.9' => ['supportedCatalogIds' => [A2uiVersion::V0_9_1->catalogId()], 'acceptsInlineCatalogs' => false],
            'v1.0' => ['supportedCatalogIds' => [A2uiVersion::V1_0->catalogId()], 'acceptsInlineCatalogs' => false],
        ], $this->subject->capabilities());
    }

    #[Test]
    public function theManifestKeepsTheShapeTheProtocolOverviewReads(): void
    {
        $manifest = $this->subject->getCatalogManifest();

        self::assertCount(18, $manifest);
        self::assertSame(['category' => 'layout', 'container' => true, 'allowedProps' => ['child']], $manifest['Card']);
        self::assertFalse($manifest['Divider']['container']);
        self::assertSame('input', $manifest['TextField']['category']);
    }
}
