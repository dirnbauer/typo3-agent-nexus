<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\A2a;

use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Package\MetaData;
use TYPO3\CMS\Core\Package\PackageInterface;
use TYPO3\CMS\Core\Package\PackageManager;
use Webconsulting\AgentNexus\A2a\Http\A2aRoutes;
use Webconsulting\AgentNexus\A2a\Protocol\SendMessageParams;
use Webconsulting\AgentNexus\A2a\Server\A2aServer;
use Webconsulting\AgentNexus\A2a\Server\CallContext;
use Webconsulting\AgentNexus\A2a\Service\AgentCard;
use Webconsulting\AgentNexus\A2a\Service\SkillCatalog;
use Webconsulting\AgentNexus\A2a\Service\TaskRunner;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Http\Api\RouteRegistry;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;
use Webconsulting\AgentNexus\Tests\Unit\A2a\Fixtures\InMemoryTaskStore;
use Webconsulting\AgentNexus\Tests\Unit\A2a\Fixtures\MemoryTaskLock;

/**
 * Real A2A payloads — produced by the same code that serves them — checked
 * against the official 1.0 JSON Schema, plus the proto's REQUIRED fields and
 * oneofs, which the generated schema does not enforce.
 */
abstract class A2aConformanceTestCase extends ConformanceTestCase
{
    protected const string ORIGIN = 'https://agent.example.org';

    /** REQUIRED fields per type, from specification/a2a.proto (v1.0.1). */
    private const array REQUIRED = [
        'AgentCard' => ['name', 'description', 'supportedInterfaces', 'version', 'capabilities', 'defaultInputModes', 'defaultOutputModes', 'skills'],
        'AgentInterface' => ['url', 'protocolBinding', 'protocolVersion'],
        'AgentProvider' => ['url', 'organization'],
        'AgentSkill' => ['id', 'name', 'description', 'tags'],
        'Task' => ['id', 'status'],
        'TaskStatus' => ['state'],
        'Message' => ['messageId', 'role', 'parts'],
        'Artifact' => ['artifactId', 'parts'],
        'TaskStatusUpdateEvent' => ['taskId', 'contextId', 'status'],
        'TaskArtifactUpdateEvent' => ['taskId', 'contextId', 'artifact'],
        'ListTasksResponse' => ['tasks', 'nextPageToken', 'pageSize', 'totalSize'],
    ];

    protected static function schema(string $type): string
    {
        return SchemaValidator::A2A_SCHEMA_ID . '#/$defs/' . $type;
    }

    /**
     * Validate against the schema definition and the proto's REQUIRED fields.
     *
     * @param array<array-key, mixed> $data
     */
    protected static function assertA2a(string $type, array $data): void
    {
        self::assertConformsTo(self::schema($type), $data);
        if (isset(self::REQUIRED[$type])) {
            self::assertHasFields($data, self::REQUIRED[$type], $type);
        }
    }

    /**
     * A Task with everything inside it: status, messages, parts, artifacts.
     *
     * @param array<array-key, mixed> $task
     */
    protected static function assertTask(array $task): void
    {
        self::assertA2a('Task', $task);
        self::assertStatus(self::map($task['status']));
        foreach (self::listOf($task['history'] ?? []) as $message) {
            self::assertMessage($message);
        }
        foreach (self::listOf($task['artifacts'] ?? []) as $artifact) {
            self::assertArtifact($artifact);
        }
    }

    /**
     * @param array<array-key, mixed> $status
     */
    protected static function assertStatus(array $status): void
    {
        self::assertA2a('TaskStatus', $status);
        self::assertNotSame('TASK_STATE_UNSPECIFIED', $status['state']);
        if (isset($status['message'])) {
            self::assertMessage(self::map($status['message']));
        }
        if (isset($status['timestamp'])) {
            self::assertIsString($status['timestamp']);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $status['timestamp'], 'ISO 8601, UTC, milliseconds.');
        }
    }

    /**
     * @param array<array-key, mixed> $message
     */
    protected static function assertMessage(array $message): void
    {
        self::assertA2a('Message', $message);
        self::assertContains($message['role'], ['ROLE_USER', 'ROLE_AGENT']);
        self::assertNotSame([], $message['parts'], 'A message has at least one part.');
        foreach (self::listOf($message['parts']) as $part) {
            self::assertPart($part);
        }
    }

    /**
     * @param array<array-key, mixed> $artifact
     */
    protected static function assertArtifact(array $artifact): void
    {
        self::assertA2a('Artifact', $artifact);
        self::assertNotSame([], $artifact['parts'], 'An artifact has at least one part.');
        foreach (self::listOf($artifact['parts']) as $part) {
            self::assertPart($part);
        }
    }

    /**
     * @param array<array-key, mixed> $part
     */
    protected static function assertPart(array $part): void
    {
        self::assertA2a('Part', $part);
        self::assertExactlyOneOf($part, ['text', 'raw', 'url', 'data'], 'Part');
    }

    /**
     * A StreamResponse and the event inside it.
     *
     * @param array<array-key, mixed> $frame
     */
    protected static function assertStreamResponse(array $frame): void
    {
        self::assertA2a('StreamResponse', $frame);
        self::assertExactlyOneOf($frame, ['task', 'message', 'statusUpdate', 'artifactUpdate'], 'StreamResponse');
        if (isset($frame['task'])) {
            self::assertTask(self::map($frame['task']));
        }
        if (isset($frame['message'])) {
            self::assertMessage(self::map($frame['message']));
        }
        if (isset($frame['statusUpdate'])) {
            $event = self::map($frame['statusUpdate']);
            self::assertA2a('TaskStatusUpdateEvent', $event);
            self::assertArrayNotHasKey('final', $event, 'A2A 1.0 removed "final".');
            self::assertStatus(self::map($event['status']));
        }
        if (isset($frame['artifactUpdate'])) {
            $event = self::map($frame['artifactUpdate']);
            self::assertA2a('TaskArtifactUpdateEvent', $event);
            self::assertArtifact(self::map($event['artifact']));
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $members
     */
    protected static function assertExactlyOneOf(array $data, array $members, string $type): void
    {
        $present = array_values(array_filter($members, static fn(string $member): bool => array_key_exists($member, $data)));
        self::assertCount(1, $present, sprintf('%s carries exactly one of %s; found %s.', $type, implode(', ', $members), implode(', ', $present) ?: 'none'));
    }

    protected static function server(): A2aServer
    {
        return new A2aServer(
            new InMemoryTaskStore(),
            new TaskRunner(new SkillCatalog(), self::createStub(LanguageModel::class), self::createStub(UsageLedger::class)),
            new MemoryTaskLock(),
            new NullLogger(),
            0,
        );
    }

    /**
     * @param array<string, mixed> $message
     * @return list<array<string, mixed>>
     */
    protected static function stream(A2aServer $server, string $text, array $message = []): array
    {
        return iterator_to_array($server->sendStreamingMessage(self::params($text, $message), new CallContext()), false);
    }

    /**
     * @param array<string, mixed> $message
     */
    protected static function params(string $text, array $message = []): SendMessageParams
    {
        return SendMessageParams::fromArray(['message' => [
            'messageId' => 'm-' . bin2hex(random_bytes(4)),
            'role' => 'ROLE_USER',
            'parts' => [['text' => $text]],
        ] + $message]);
    }

    /**
     * The Agent Card as the endpoint builds it.
     *
     * @return array<string, mixed>
     */
    protected static function card(): array
    {
        $metaData = new MetaData('agent_nexus');
        $metaData->setVersion('4.0.0');
        $package = self::createStub(PackageInterface::class);
        $package->method('getPackageMetaData')->willReturn($metaData);
        $packageManager = self::createStub(PackageManager::class);
        $packageManager->method('getPackage')->willReturn($package);

        $routes = new RouteRegistry([new A2aRoutes()], new ExtensionSettings(self::createStub(ExtensionConfiguration::class)));
        return (new AgentCard(new SkillCatalog(), $routes, $packageManager))->build(self::ORIGIN);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $map = [];
        foreach ($value as $key => $item) {
            $map[(string)$key] = $item;
        }
        return $map;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function listOf(mixed $value): array
    {
        self::assertIsArray($value);
        self::assertTrue(array_is_list($value), 'Expected a JSON array.');
        return array_map(self::map(...), $value);
    }
}
