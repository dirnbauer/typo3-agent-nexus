<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Agui\Event;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Agui\Event\EventFactory;
use Webconsulting\AgentNexus\Agui\Protocol\EventType;
use Webconsulting\AgentNexus\Agui\Service\EventCatalog;

/**
 * The factory keeps the rules that are easy to break from PHP. Its schema
 * conformance is proven in Tests/Conformance/Agui; this checks the PHP side.
 */
final class EventFactoryTest extends UnitTestCase
{
    #[Test]
    public function aProducerDeclaresItsOwnVersionOnRunStarted(): void
    {
        self::assertSame('1.0', EventFactory::runStarted('t', 'r')['protocolVersion']);
    }

    #[Test]
    public function optionalFieldsWithoutAValueAreLeftOut(): void
    {
        $event = EventFactory::runFinished('t', 'r');

        self::assertSame(['type', 'threadId', 'runId', 'timestamp'], array_keys($event));
        self::assertArrayNotHasKey('code', EventFactory::runError('failed'));
        self::assertArrayNotHasKey('parentMessageId', EventFactory::toolCallStart('c', 'x'));
    }

    #[Test]
    public function aRequiredPayloadMayBeNull(): void
    {
        self::assertArrayHasKey('value', EventFactory::custom('at.webconsulting.test', null));
    }

    #[Test]
    public function timestampsAreIntegerMilliseconds(): void
    {
        $timestamp = EventFactory::textMessageEnd('m')['timestamp'];

        self::assertIsInt($timestamp);
        self::assertEqualsWithDelta(microtime(true) * 1000, $timestamp, 5000);
    }

    #[Test]
    public function objectFieldsStayObjectsWhenEmpty(): void
    {
        self::assertSame('{}', json_encode(EventFactory::activitySnapshot('a', 'x', [])['content']));
        self::assertSame('{}', json_encode(EventFactory::stateSnapshot([])['snapshot']));
        self::assertSame('{}', json_encode(EventFactory::tokenUsage()));
        self::assertSame('{}', json_encode(EventFactory::withMetadata(EventFactory::stepStarted('s'), [])['metadata']));
    }

    #[Test]
    public function theSuccessOutcomeNamesPendingCallsOnlyWhenThereAreSome(): void
    {
        self::assertSame(['type' => 'success'], EventFactory::success());
        self::assertSame(['type' => 'success', 'pendingToolCallIds' => ['c']], EventFactory::success(['c']));
    }

    #[Test]
    public function runScopedEventsCannotBeAttributedToASubagent(): void
    {
        $this->expectException(\LogicException::class);
        EventFactory::attributedTo(EventFactory::runStarted('t', 'r'), 's');
    }

    #[Test]
    public function mintedIdsAreUnique(): void
    {
        self::assertNotSame(EventFactory::mintId('msg'), EventFactory::mintId('msg'));
        self::assertStringStartsWith('msg_', EventFactory::mintId('msg'));
    }

    #[Test]
    public function theCatalogueHasAnExampleForEveryEventType(): void
    {
        foreach (EventType::cases() as $type) {
            self::assertSame($type->value, EventCatalog::example($type)['type']);
        }
    }
}
