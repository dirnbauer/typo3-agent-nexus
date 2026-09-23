<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Http\A2uiProblem;
use Webconsulting\AgentNexus\A2ui\Service\RendererMessageParser;

final class RendererMessageParserTest extends UnitTestCase
{
    private RendererMessageParser $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new RendererMessageParser();
    }

    #[Test]
    public function anActionWithItsDataModelIsRead(): void
    {
        $message = $this->subject->parse([
            'version' => 'v0.9.1',
            'action' => self::action(),
            'metadata' => ['a2uiClientDataModel' => ['version' => 'v0.9.1', 'surfaces' => [
                'contact-1' => ['name' => 'Ada'],
                'someone-else' => ['secret' => true],
            ]]],
            'agentNexus' => ['ce' => 1, 'page' => 2],
        ]);

        self::assertTrue($message->isAction());
        self::assertSame(A2uiVersion::V0_9_1, $message->version);
        self::assertSame('contact-1', $message->surfaceId());
        self::assertSame('sendMessage', $message->name());
        self::assertSame('submit', $message->sourceComponentId());
        self::assertSame(['name' => 'Ada'], $message->context());
        self::assertSame(['name' => 'Ada'], $message->dataModel, 'Only the data model of this surface is taken.');
        self::assertSame(['version' => 'v0.9.1', 'action' => self::action()], $message->message);
        self::assertArrayNotHasKey('agentNexus', $message->toRecord());
        self::assertArrayHasKey('metadata', $message->toRecord());
    }

    #[Test]
    public function v10ReadsTheRendererDataModel(): void
    {
        $message = $this->subject->parse([
            'version' => 'v1.0',
            'action' => self::action(),
            'metadata' => ['a2uiRendererDataModel' => ['version' => 'v1.0', 'surfaces' => ['contact-1' => ['x' => 1]]]],
        ]);

        self::assertSame(A2uiVersion::V1_0, $message->version);
        self::assertSame(['x' => 1], $message->dataModel);
    }

    #[Test]
    public function anErrorReportIsRead(): void
    {
        $message = $this->subject->parse([
            'version' => 'v0.9',
            'error' => ['code' => 'VALIDATION_FAILED', 'surfaceId' => 'contact-1', 'path' => '/components/0', 'message' => 'Unknown'],
        ]);

        self::assertFalse($message->isAction());
        self::assertSame('contact-1', $message->surfaceId());
    }

    /**
     * @return array<string, array{array<string, mixed>, int, string, ?string}>
     */
    public static function invalidMessages(): array
    {
        $action = self::action();
        return [
            'no version' => [['action' => $action], 422, 'UNSUPPORTED_VERSION', '/version'],
            'v0.8' => [['version' => 'v0.8', 'action' => $action], 422, 'UNSUPPORTED_VERSION', '/version'],
            'an unknown member' => [['version' => 'v0.9.1', 'action' => $action, 'wantResponse' => true], 400, 'BAD_REQUEST', null],
            'two messages' => [['version' => 'v0.9.1', 'action' => $action, 'error' => []], 400, 'BAD_REQUEST', null],
            'no message' => [['version' => 'v0.9.1'], 400, 'BAD_REQUEST', null],
            'no name' => [['version' => 'v0.9.1', 'action' => ['name' => ''] + $action], 422, 'VALIDATION_FAILED', '/action/name'],
            'no source' => [['version' => 'v0.9.1', 'action' => array_diff_key($action, ['sourceComponentId' => 1])], 422, 'VALIDATION_FAILED', '/action/sourceComponentId'],
            'a timestamp without a zone' => [['version' => 'v0.9.1', 'action' => ['timestamp' => '2026-09-23T10:00:00'] + $action], 422, 'VALIDATION_FAILED', '/action/timestamp'],
            'a context list' => [['version' => 'v0.9.1', 'action' => ['context' => ['a', 'b']] + $action], 422, 'VALIDATION_FAILED', '/action/context'],
            'no context' => [['version' => 'v0.9.1', 'action' => array_diff_key($action, ['context' => 1])], 422, 'VALIDATION_FAILED', '/action/context'],
            'a data model list' => [['version' => 'v0.9.1', 'action' => $action, 'metadata' => ['a2uiClientDataModel' => ['surfaces' => ['contact-1' => [1, 2]]]]], 422, 'VALIDATION_FAILED', '/metadata/a2uiClientDataModel/surfaces'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[Test]
    #[DataProvider('invalidMessages')]
    public function invalidMessagesAreRefusedWithThePathOfTheProblem(array $body, int $status, string $code, ?string $path): void
    {
        try {
            $this->subject->parse($body);
            self::fail('Expected a refusal.');
        } catch (A2uiProblem $problem) {
            self::assertSame($status, $problem->status);
            self::assertSame($code, $problem->errorCode);
            self::assertSame($path, $problem->path);
        }
    }

    #[Test]
    public function anEmptyContextIsAnEmptyObject(): void
    {
        $message = $this->subject->parse(['version' => 'v0.9.1', 'action' => ['context' => []] + self::action()]);

        self::assertSame([], $message->context());
    }

    /**
     * @return array{name: string, surfaceId: string, sourceComponentId: string, timestamp: string, context: array<string, mixed>}
     */
    private static function action(): array
    {
        return [
            'name' => 'sendMessage',
            'surfaceId' => 'contact-1',
            'sourceComponentId' => 'submit',
            'timestamp' => '2026-09-23T10:15:00.123Z',
            'context' => ['name' => 'Ada'],
        ];
    }
}
