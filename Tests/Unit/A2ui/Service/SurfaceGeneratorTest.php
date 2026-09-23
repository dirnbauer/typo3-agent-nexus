<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Component;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceGenerator;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceSanitizer;

final class SurfaceGeneratorTest extends UnitTestCase
{
    private SurfaceGenerator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SurfaceGenerator();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function requests(): array
    {
        return [
            'the widget placeholder is a quote, not a page' => ['I need a quote for a 10-page website with a blog', 'quote'],
            'a callback' => ['Book an introductory call', 'callback'],
            'a job' => ['Apply for an open role', 'application'],
            'German: a callback' => ['Ich hätte gerne einen Rückruf', 'callback'],
            'German: an offer' => ['Ein Angebot für eine Website', 'quote'],
            'an event' => ['Collect event registration details', 'event'],
            'a newsletter' => ['A newsletter sign-up form', 'newsletter'],
            'SEO before page' => ['Edit the SEO metadata of this page', 'seo'],
            'a schedule' => ['Schedule the publication of a page', 'schedule'],
            'a page' => ['Create a new landing page', 'page'],
            'anything else' => ['Ask about a service', 'contact'],
            'no keyword inside a word' => ['Recall our last conversation', 'contact'],
        ];
    }

    #[Test]
    #[DataProvider('requests')]
    public function keywordsPickTheExample(string $intent, string $expected): void
    {
        self::assertSame($expected, $this->subject->match($intent));
    }

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
    public function everyExampleUsesOnlyTheCatalogue(string $example, A2uiVersion $version): void
    {
        $surface = $this->subject->example($example, 'A request');
        $clean = (new SurfaceSanitizer(new ComponentRegistry()))->sanitize($surface->componentsToArray(), $surface->dataModel, $version);

        self::assertSame([], $clean->notes, 'The built-in surfaces need no repair.');
        self::assertTrue($clean->isRenderable());
        self::assertCount(count($surface->components), $clean->components, 'Every component is reachable from the root.');
    }

    /**
     * @return \Generator<string, array{string}>
     */
    public static function exampleNames(): \Generator
    {
        foreach (array_keys(SurfaceGenerator::EXAMPLES) as $example) {
            yield $example => [$example];
        }
    }

    #[Test]
    #[DataProvider('exampleNames')]
    public function everyExampleSendsItsFieldsAndCanBeDiscarded(string $example): void
    {
        $surface = $this->subject->example($example);

        self::assertSame('Card', $surface->root()?->type);
        $submit = $surface->component('submit');
        self::assertNotNull($submit);
        self::assertSame('primary', $submit->property('variant'));
        $context = $submit->property('action')['event']['context'] ?? [];
        foreach (array_keys($context) as $key) {
            self::assertArrayHasKey($key, $surface->dataModel, 'Every value the action sends has a starting value.');
        }
        self::assertSame(
            ['event' => ['name' => SurfaceGenerator::DISCARD_EVENT]],
            $surface->component('discard')?->property('action'),
        );
        self::assertTrue($surface->sendDataModel);
    }

    #[Test]
    public function theContactFormStartsWithTheRequestAsItsSubject(): void
    {
        $surface = $this->subject->generate('Question about hosting');

        self::assertSame('contact', $surface->surfaceId);
        self::assertSame('Question about hosting', $surface->dataModel['subject']);
    }

    #[Test]
    public function theNewsletterButtonIsDisabledUntilConsentIsGiven(): void
    {
        $submit = $this->subject->example('newsletter')->component('submit');

        self::assertSame(
            [['condition' => ['path' => '/consent'], 'message' => 'Agree to the privacy policy to subscribe.']],
            $submit?->property('checks'),
        );
    }

    #[Test]
    public function theSeoExampleUsesTabs(): void
    {
        $surface = $this->subject->example('seo');
        $tabs = array_values(array_filter($surface->components, static fn(Component $component): bool => $component->type === 'Tabs'));

        self::assertCount(1, $tabs);
        self::assertSame(['General', 'Search engines'], array_column($tabs[0]->property('tabs'), 'title'));
    }
}
