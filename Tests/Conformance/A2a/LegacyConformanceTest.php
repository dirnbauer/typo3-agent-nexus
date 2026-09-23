<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\A2a;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\A2a\Protocol\A2aException;
use Webconsulting\AgentNexus\A2a\Protocol\LegacyTranslator;
use Webconsulting\AgentNexus\A2a\Protocol\Operation;
use Webconsulting\AgentNexus\A2a\Protocol\TaskIdParams;
use Webconsulting\AgentNexus\A2a\Server\CallContext;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;

/**
 * The 0.3 dialect against the official A2A 0.3.0 schema, which — unlike the
 * 1.0 bundle — carries `required` lists: a 0.3 client must be able to parse
 * every translated result and frame.
 */
final class LegacyConformanceTest extends A2aConformanceTestCase
{
    private const string SCHEMA_ID = SchemaValidator::A2A_03_SCHEMA_ID;

    #[Test]
    public function everyTranslatedFrameIsAValid03StreamResponse(): void
    {
        $server = self::server();
        $frames = [...self::stream($server, 'Summarise the pricing page'), ...self::stream($server, 'Draft an outreach email')];

        $finals = 0;
        foreach ($frames as $frame) {
            $event = LegacyTranslator::streamResponse($frame);
            self::assertConformsTo03('SendStreamingMessageSuccessResponse', ['jsonrpc' => '2.0', 'id' => 1, 'result' => $event]);
            if (($event['kind'] ?? '') === 'status-update' && $event['final'] === true) {
                $finals++;
            }
        }
        self::assertSame(2, $finals, 'Each stream ends on one final status update: completed, and input-required.');
    }

    #[Test]
    public function messageSendReturnsAValid03Task(): void
    {
        $response = self::server()->sendMessage(self::params('Plan the onboarding'), new CallContext());

        self::assertConformsTo03('SendMessageSuccessResponse', ['jsonrpc' => '2.0', 'id' => 'r-1', 'result' => LegacyTranslator::sendMessageResponse($response)]);
    }

    #[Test]
    public function tasksGetAndTasksCancelReturnValid03Tasks(): void
    {
        $server = self::server();
        $id = self::stream($server, 'Draft an outreach email')[0]['task']['id'];
        self::assertIsString($id);

        self::assertConformsTo03('GetTaskSuccessResponse', ['jsonrpc' => '2.0', 'id' => 2, 'result' => LegacyTranslator::task($server->getTask(new TaskIdParams($id), new CallContext()))]);
        self::assertConformsTo03('CancelTaskSuccessResponse', ['jsonrpc' => '2.0', 'id' => 3, 'result' => LegacyTranslator::task($server->cancelTask(new TaskIdParams($id), new CallContext()))]);
    }

    #[Test]
    public function aTaskNotFoundErrorIsValidIn03(): void
    {
        self::assertConformsTo03('JSONRPCErrorResponse', ['jsonrpc' => '2.0', 'id' => 4, 'error' => A2aException::taskNotFound('t-1')->toJsonRpcError()]);
        self::assertConformsTo03('TaskNotFoundError', A2aException::taskNotFound('t-1')->toJsonRpcError());
    }

    #[Test]
    public function an03RequestTranslatesToAValid10Request(): void
    {
        $legacy = [
            'message' => [
                'kind' => 'message',
                'messageId' => 'm-1',
                'role' => 'user',
                'parts' => [['kind' => 'text', 'text' => 'Summarise the pricing page']],
            ],
            'configuration' => ['blocking' => true, 'acceptedOutputModes' => ['text/plain']],
        ];
        self::assertConformsTo03('MessageSendParams', $legacy);

        self::assertA2a('SendMessageRequest', LegacyTranslator::paramsToCore(Operation::SendMessage, $legacy));
    }

    #[Test]
    public function anUntranslated10FrameIsNotA03Event(): void
    {
        $frame = self::stream(self::server(), 'Summarise the pricing page')[0];

        self::assertNotSame(
            [],
            self::errors03('SendStreamingMessageSuccessResponse', ['jsonrpc' => '2.0', 'id' => 1, 'result' => $frame]),
            'Without the translation a 0.3 client could not read the stream — which is why it exists.',
        );
    }

    private static function assertConformsTo03(string $definition, mixed $data): void
    {
        self::assertSame(
            [],
            self::errors03($definition, $data),
            'Does not conform to A2A 0.3 ' . $definition . ":\n" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }

    /**
     * @return list<string>
     */
    private static function errors03(string $definition, mixed $data): array
    {
        return SchemaValidator::errors($data, self::SCHEMA_ID . '#/definitions/' . $definition);
    }
}
