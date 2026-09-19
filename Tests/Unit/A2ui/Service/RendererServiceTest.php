<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Component;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Surface;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\A2ui\Service\RendererService;

/**
 * The renderer is the trust boundary: an agent hands over a description of an
 * interface, and only components the registry knows may ever reach the page.
 * These tests replace Fluid with a recording stand-in, so what is asserted is
 * the renderer's decisions — which nodes it renders, in which order, and what
 * it resolves their bindings to — rather than any template's markup.
 */
final class RendererServiceTest extends UnitTestCase
{
    #[Test]
    public function theTreeIsRebuiltFromTheFlatListStartingAtRoot(): void
    {
        $surface = new Surface('demo', [
            new Component('root', 'Column', [], ['title', 'submit']),
            new Component('title', 'TextField', ['label' => 'Title']),
            new Component('submit', 'Button', ['label' => 'Send']),
        ]);

        $html = $this->render($surface);

        self::assertStringContainsString('data-surface-id="demo"', $html);
        self::assertSame(
            ['Column', 'TextField', 'Button'],
            $this->renderedTypes($html),
            'Children render in the order the root lists them, nested inside their container.',
        );
    }

    #[Test]
    public function aComponentThatIsNotInTheCatalogIsDroppedRatherThanRendered(): void
    {
        $surface = new Surface('demo', [
            new Component('root', 'Column', [], ['ok', 'evil']),
            new Component('ok', 'Text', ['text' => 'fine']),
            new Component('evil', 'ScriptInjection', ['src' => 'https://example.invalid/x.js']),
        ]);

        $html = $this->render($surface);

        self::assertContains('Text', $this->renderedTypes($html));
        self::assertNotContains('ScriptInjection', $this->renderedTypes($html));
    }

    #[Test]
    public function childrenOfANonContainerAreIgnored(): void
    {
        $surface = new Surface('demo', [
            new Component('root', 'Text', ['text' => 'leaf'], ['ignored']),
            new Component('ignored', 'Text', ['text' => 'never']),
        ]);

        self::assertSame(['Text'], $this->renderedTypes($this->render($surface)));
    }

    #[Test]
    public function aJsonPointerBindingIsResolvedAgainstTheDataModel(): void
    {
        $surface = new Surface(
            'demo',
            [new Component('root', 'TextField', ['value' => ['path' => '/contact/name']])],
            ['contact' => ['name' => 'Ada']],
        );

        self::assertStringContainsString('value=Ada', $this->render($surface));
        self::assertStringContainsString('path=/contact/name', $this->render($surface));
    }

    #[Test]
    public function aBindingThatPointsNowhereResolvesToNullInsteadOfFailing(): void
    {
        $surface = new Surface(
            'demo',
            [new Component('root', 'TextField', ['value' => ['path' => '/nope/missing']])],
            ['contact' => ['name' => 'Ada']],
        );

        self::assertStringContainsString('value=NULL', $this->render($surface));
    }

    #[Test]
    public function aLiteralValueIsPassedThroughWithoutABindingPath(): void
    {
        $surface = new Surface('demo', [new Component('root', 'TextField', ['value' => 'typed in'])]);

        $html = $this->render($surface);

        self::assertStringContainsString('value=typed in', $html);
        self::assertStringContainsString('path=NULL', $html);
    }

    #[Test]
    public function aSurfaceWithoutARootSaysSoInsteadOfRenderingNothing(): void
    {
        $surface = new Surface('demo', [new Component('orphan', 'Text', ['text' => 'hi'])]);

        self::assertStringContainsString('surface has no', $this->render($surface));
    }

    private function render(Surface $surface): string
    {
        $subject = new RendererService(new ComponentRegistry(), $this->recordingViewFactory());

        return $subject->renderStatic($surface, new ServerRequest('https://example.org/'));
    }

    /**
     * @return list<string>
     */
    private function renderedTypes(string $html): array
    {
        preg_match_all('/<n type=([A-Za-z]+)/', $html, $matches);
        return $matches[1];
    }

    /**
     * A view that records what the renderer asked it to draw instead of running
     * Fluid — this is a unit test of the renderer, not of the templates.
     */
    private function recordingViewFactory(): ViewFactoryInterface
    {
        $view = new class () implements ViewInterface {
            /** @var array<string, mixed> */
            private array $variables = [];

            public function assign(string $key, mixed $value): self
            {
                $this->variables[$key] = $value;
                return $this;
            }

            public function assignMultiple(array $values): self
            {
                foreach ($values as $key => $value) {
                    $this->variables[$key] = $value;
                }
                return $this;
            }

            public function render(string $templateFileName = ''): string
            {
                return sprintf(
                    '<n type=%s id=%s value=%s path=%s>%s</n>',
                    $this->variables['component'] ?? '?',
                    $this->variables['id'] ?? '?',
                    $this->scalar($this->variables['value'] ?? null),
                    $this->scalar($this->variables['path'] ?? null),
                    $this->variables['children'] ?? '',
                );
            }

            private function scalar(mixed $value): string
            {
                return match (true) {
                    $value === null => 'NULL',
                    is_scalar($value) => (string)$value,
                    default => gettype($value),
                };
            }
        };

        $factory = self::createStub(ViewFactoryInterface::class);
        $factory->method('create')->willReturnCallback(
            static fn(ViewFactoryData $data): ViewInterface => clone $view,
        );

        return $factory;
    }
}
