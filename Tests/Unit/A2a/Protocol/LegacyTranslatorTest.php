<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2a\Protocol;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2a\Protocol\LegacyTranslator;
use Webconsulting\AgentNexus\A2a\Protocol\Message;
use Webconsulting\AgentNexus\A2a\Protocol\Operation;
use Webconsulting\AgentNexus\A2a\Protocol\SendMessageParams;

/**
 * The 0.3 dialect: 0.3 parameters become 1.0 parameters, 1.0 results and
 * frames become 0.3 ones. The task logic never sees the difference.
 */
final class LegacyTranslatorTest extends UnitTestCase
{
    #[Test]
    public function aZeroPointThreeMessageSendBecomesA10Request(): void
    {
        $core = LegacyTranslator::paramsToCore(Operation::SendMessage, [
            'message' => [
                'kind' => 'message',
                'messageId' => 'm-1',
                'role' => 'user',
                'parts' => [
                    ['kind' => 'text', 'text' => 'Summarise the pricing page', 'metadata' => ['lang' => 'en']],
                    ['kind' => 'file', 'file' => ['uri' => 'https://example.org/a.pdf', 'mimeType' => 'application/pdf', 'name' => 'a.pdf']],
                    ['kind' => 'file', 'file' => ['bytes' => 'aGVsbG8=']],
                    ['kind' => 'data', 'data' => ['plan' => 'pro']],
                ],
                'taskId' => 't-1',
            ],
            'configuration' => ['blocking' => false, 'historyLength' => 2, 'acceptedOutputModes' => ['text/plain']],
        ]);

        self::assertSame([
            'messageId' => 'm-1',
            'role' => 'ROLE_USER',
            'parts' => [
                ['text' => 'Summarise the pricing page', 'metadata' => ['lang' => 'en']],
                ['url' => 'https://example.org/a.pdf', 'mediaType' => 'application/pdf', 'filename' => 'a.pdf'],
                ['raw' => 'aGVsbG8='],
                ['data' => ['plan' => 'pro']],
            ],
            'taskId' => 't-1',
        ], $core['message']);
        self::assertSame(['acceptedOutputModes' => ['text/plain'], 'historyLength' => 2, 'returnImmediately' => true], $core['configuration']);

        $params = SendMessageParams::fromArray($core);
        self::assertTrue($params->returnImmediately, 'blocking: false is returnImmediately: true.');
        self::assertSame('t-1', $params->message->taskId);
    }

    #[Test]
    public function aZeroPointThreePushConfigReachesThe10Check(): void
    {
        $core = LegacyTranslator::paramsToCore(Operation::SendStreamingMessage, [
            'message' => ['messageId' => 'm', 'role' => 'user', 'parts' => [['kind' => 'text', 'text' => 'x']]],
            'configuration' => ['pushNotificationConfig' => ['url' => 'https://example.org/hook']],
        ]);

        $this->expectExceptionCode(-32003);
        SendMessageParams::fromArray($core);
    }

    #[Test]
    public function aTaskGetsKindsAndLowerCaseStates(): void
    {
        $legacy = LegacyTranslator::task([
            'id' => 't-1',
            'contextId' => 'c-1',
            'status' => [
                'state' => 'TASK_STATE_INPUT_REQUIRED',
                'message' => ['messageId' => 'm-2', 'role' => 'ROLE_AGENT', 'parts' => [['text' => 'Who is the audience?']], 'contextId' => 'c-1', 'taskId' => 't-1'],
                'timestamp' => '2026-09-23T10:00:02.000Z',
            ],
            'artifacts' => [['artifactId' => 'a-1', 'name' => 'x.md', 'parts' => [['text' => '# X', 'mediaType' => 'text/markdown']]]],
            'history' => [['messageId' => 'm-1', 'role' => 'ROLE_USER', 'parts' => [['text' => 'Draft an email']]]],
            'metadata' => ['skillId' => 'draft_outreach'],
        ]);

        self::assertSame('task', $legacy['kind']);
        self::assertSame('input-required', $legacy['status']['state']);
        self::assertSame('2026-09-23T10:00:02.000Z', $legacy['status']['timestamp']);
        self::assertSame(['kind' => 'message', 'messageId' => 'm-2', 'role' => 'agent', 'parts' => [['kind' => 'text', 'text' => 'Who is the audience?']], 'contextId' => 'c-1', 'taskId' => 't-1'], $legacy['status']['message']);
        self::assertSame([['artifactId' => 'a-1', 'parts' => [['kind' => 'text', 'text' => '# X']], 'name' => 'x.md']], $legacy['artifacts']);
        self::assertSame('user', $legacy['history'][0]['role']);
        self::assertSame(['skillId' => 'draft_outreach'], $legacy['metadata']);
    }

    #[Test]
    public function messageSendAnswersWithTheBareTask(): void
    {
        $legacy = LegacyTranslator::sendMessageResponse(['task' => ['id' => 't-1', 'contextId' => 'c-1', 'status' => ['state' => 'TASK_STATE_COMPLETED']]]);

        self::assertSame(['kind' => 'task', 'id' => 't-1', 'contextId' => 'c-1', 'status' => ['state' => 'completed']], $legacy);
    }

    #[Test]
    public function aStatusUpdateIsFinalWhereTheStreamCloses(): void
    {
        foreach (['TASK_STATE_WORKING' => false, 'TASK_STATE_INPUT_REQUIRED' => true, 'TASK_STATE_COMPLETED' => true, 'TASK_STATE_CANCELED' => true] as $state => $final) {
            $legacy = LegacyTranslator::streamResponse(['statusUpdate' => ['taskId' => 't', 'contextId' => 'c', 'status' => ['state' => $state]]]);
            self::assertSame('status-update', $legacy['kind']);
            self::assertSame($final, $legacy['final'], $state);
        }
    }

    #[Test]
    public function anArtifactUpdateKeepsAppendAndLastChunk(): void
    {
        $legacy = LegacyTranslator::streamResponse(['artifactUpdate' => [
            'taskId' => 't',
            'contextId' => 'c',
            'artifact' => ['artifactId' => 'a', 'parts' => [['text' => 'chunk ']]],
            'append' => true,
            'lastChunk' => false,
        ]]);

        self::assertSame([
            'kind' => 'artifact-update',
            'taskId' => 't',
            'contextId' => 'c',
            'artifact' => ['artifactId' => 'a', 'parts' => [['kind' => 'text', 'text' => 'chunk ']]],
            'append' => true,
            'lastChunk' => false,
        ], $legacy);
    }

    #[Test]
    public function filesAndDataTranslateToTheirZeroPointThreeParts(): void
    {
        self::assertSame(['kind' => 'file', 'file' => ['uri' => 'https://a.b/c.png', 'mimeType' => 'image/png', 'name' => 'c.png']], LegacyTranslator::part(['url' => 'https://a.b/c.png', 'mediaType' => 'image/png', 'filename' => 'c.png']));
        self::assertSame(['kind' => 'file', 'file' => ['bytes' => 'aGk=']], LegacyTranslator::part(['raw' => 'aGk=']));
        self::assertSame(['kind' => 'data', 'data' => ['a' => 1]], LegacyTranslator::part(['data' => ['a' => 1]]));
        self::assertSame(['kind' => 'data', 'data' => ['value' => [1, 2]]], LegacyTranslator::part(['data' => [1, 2]]), 'A 0.3 DataPart holds an object.');
    }

    #[Test]
    public function aTranslatedMessageReadsAsA10Message(): void
    {
        $message = Message::fromArray(LegacyTranslator::messageToCore([
            'kind' => 'message', 'messageId' => 'm', 'role' => 'agent', 'parts' => [['kind' => 'text', 'text' => 'Hi']],
        ]));

        self::assertSame('ROLE_AGENT', $message->role->value);
        self::assertSame('Hi', $message->text());
    }
}
