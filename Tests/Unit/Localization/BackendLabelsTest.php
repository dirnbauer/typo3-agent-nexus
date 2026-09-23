<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Localization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Agentstack\Service\ProtocolCatalog;

/**
 * A German backend must read German everywhere: every English label has a
 * current German translation, and the backend templates take their words from
 * the label files instead of spelling them out.
 */
final class BackendLabelsTest extends UnitTestCase
{
    private const string LANGUAGE_DIRECTORY = __DIR__ . '/../../../Resources/Private/Language';
    private const string PRIVATE_DIRECTORY = __DIR__ . '/../../../Resources/Private';

    /** Backend screens: module templates and the partials only they use. */
    private const array BACKEND_TEMPLATE_DIRECTORIES = [
        'Templates/A2a',
        'Templates/A2ui',
        'Templates/Agui',
        'Templates/Ap2',
        'Templates/Inspector',
        'Templates/Overview',
        'Templates/Traffic',
        'Templates/Ucp',
        'Partials/Backend',
        'Partials/Inspector',
        'Partials/Traffic',
    ];

    /** Words that are the same in every language: HTTP methods on endpoint badges. */
    private const array UNTRANSLATED_WORDS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @return array<string, array{0: string}>
     */
    public static function labelFileProvider(): array
    {
        $files = [];
        foreach (glob(self::LANGUAGE_DIRECTORY . '/locallang*.xlf') ?: [] as $file) {
            $files[basename($file)] = [$file];
        }
        return $files;
    }

    #[Test]
    #[DataProvider('labelFileProvider')]
    public function everyLabelHasACurrentGermanTranslation(string $englishFile): void
    {
        $german = dirname($englishFile) . '/de.' . basename($englishFile);
        self::assertFileExists($german);
        $english = self::units($englishFile);
        $translated = self::units($german);

        self::assertSame([], array_values(array_diff(array_keys($english), array_keys($translated))), basename($german) . ' lacks these units.');
        self::assertSame([], array_values(array_diff(array_keys($translated), array_keys($english))), basename($german) . ' has units the English file no longer has.');
        foreach ($english as $id => $unit) {
            self::assertNotSame('', trim($translated[$id]['target']), sprintf('%s: "%s" has no German text.', basename($german), $id));
            self::assertSame(
                $unit['source'],
                $translated[$id]['source'],
                sprintf('%s: the English text of "%s" changed; translate it again.', basename($german), $id),
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function backendTemplateProvider(): array
    {
        $templates = [];
        foreach (self::BACKEND_TEMPLATE_DIRECTORIES as $directory) {
            foreach (glob(self::PRIVATE_DIRECTORY . '/' . $directory . '/*.html') ?: [] as $file) {
                $templates[$directory . '/' . basename($file)] = [$file];
            }
        }
        return $templates;
    }

    #[Test]
    #[DataProvider('backendTemplateProvider')]
    public function backendTemplatesSpellOutNoText(string $template): void
    {
        $html = (string)file_get_contents($template);
        // Comments, scripts, styles and code samples are not interface text.
        $html = (string)preg_replace(['#<f:comment>.*?</f:comment>#s', '#<!--.*?-->#s', '#<(script|style|svg|code|pre|kbd|samp)\b.*?</\1>#s'], '', $html);

        preg_match_all('#\s(title|aria-label|aria-description|placeholder|alt)="([^"{]*\p{L}[^"]*)"#u', $html, $attributes, PREG_SET_ORDER);
        self::assertSame([], array_map(static fn(array $match): string => $match[0], $attributes), 'Attributes with literal text in ' . basename($template));

        // Fluid inline syntax first, innermost first: `{x -> f:format.json()}` holds a ">".
        $text = $html;
        do {
            $text = (string)preg_replace('#\{[^{}]*\}#', ' ', $text, -1, $count);
        } while ($count > 0);
        $text = (string)preg_replace('#<[^>]*>#s', ' ', $text);
        preg_match_all('/\p{L}{2,}[\p{L}\'’-]*/u', html_entity_decode($text), $words);
        $spelled = array_values(array_diff($words[0], self::UNTRANSLATED_WORDS));

        self::assertSame([], $spelled, 'Text spelled out in ' . basename($template) . ' instead of taken from a label file.');
    }

    #[Test]
    public function theOverviewTaglinesAreTheProtocolCatalogues(): void
    {
        $meta = (new \ReflectionClassConstant(ProtocolCatalog::class, 'META'))->getValue();
        self::assertIsArray($meta);
        $labels = self::units(self::LANGUAGE_DIRECTORY . '/locallang_overview.xlf');

        foreach (ProtocolCatalog::PROTOCOLS as $protocol) {
            $entry = $meta[$protocol] ?? null;
            self::assertIsArray($entry);
            self::assertSame($entry['tagline'] ?? null, $labels['card.tagline.' . $protocol]['source'] ?? null, 'The overview card of ' . $protocol . ' says something else than the protocol elements.');
        }
    }

    /**
     * @return array<string, array{source: string, target: string}>
     */
    private static function units(string $file): array
    {
        $document = new \DOMDocument();
        self::assertTrue($document->load($file), 'Cannot read ' . $file);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', 'urn:oasis:names:tc:xliff:document:2.0');

        $units = [];
        foreach ($xpath->query('//x:unit') ?: [] as $unit) {
            if (!$unit instanceof \DOMElement) {
                continue;
            }
            $units[$unit->getAttribute('id')] = [
                'source' => (string)$xpath->evaluate('string(x:segment/x:source)', $unit),
                'target' => (string)$xpath->evaluate('string(x:segment/x:target)', $unit),
            ];
        }
        return $units;
    }
}
