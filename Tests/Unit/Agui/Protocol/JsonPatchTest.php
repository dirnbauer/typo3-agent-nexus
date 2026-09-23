<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Agui\Protocol;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Agui\Protocol\JsonPatch;

final class JsonPatchTest extends UnitTestCase
{
    #[Test]
    public function everyOperationOfRfc6902Applies(): void
    {
        $document = ['pages' => [['title' => 'Über uns'], ['title' => 'Kontakt']], 'lang' => 'de'];

        $patched = JsonPatch::apply($document, [
            ['op' => 'replace', 'path' => '/pages/0/title', 'value' => 'About us'],
            ['op' => 'add', 'path' => '/pages/-', 'value' => ['title' => 'Services']],
            ['op' => 'copy', 'from' => '/lang', 'path' => '/source'],
            ['op' => 'move', 'from' => '/pages/1', 'path' => '/pages/0'],
            ['op' => 'remove', 'path' => '/lang'],
            ['op' => 'test', 'path' => '/source', 'value' => 'de'],
        ]);

        self::assertSame(['pages' => [['title' => 'Kontakt'], ['title' => 'About us'], ['title' => 'Services']], 'source' => 'de'], $patched);
    }

    #[Test]
    public function aFailingOperationLeavesTheDocumentAsItWas(): void
    {
        $document = new \stdClass();
        $document->selection = null;

        try {
            JsonPatch::apply($document, [
                ['op' => 'replace', 'path' => '/selection', 'value' => 'Team'],
                ['op' => 'test', 'path' => '/selection', 'value' => 'Business'],
            ]);
            self::fail('The failing test operation was not reported.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Operation 1 (test)', $e->getMessage());
        }
        self::assertNull($document->selection, 'The first operation was not kept.');
    }

    #[Test]
    public function anEmptyObjectStaysAnObject(): void
    {
        self::assertSame('{"a":{}}', json_encode(JsonPatch::apply(new \stdClass(), [['op' => 'add', 'path' => '/a', 'value' => new \stdClass()]])));
    }
}
