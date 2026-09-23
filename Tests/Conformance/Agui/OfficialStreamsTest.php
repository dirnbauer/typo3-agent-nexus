<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Agui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Agui\Protocol\EventVerifier;
use Webconsulting\AgentNexus\Agui\Protocol\OrderingRule;
use Webconsulting\AgentNexus\Agui\Protocol\ProtocolViolation;
use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;
use Webconsulting\AgentNexus\Tests\Conformance\SchemaValidator;

/**
 * The specification's own conformance streams (Streams/, see SOURCE.txt),
 * replayed through the stream verifier.
 *
 * They were written for clients. A stream a client must reject, the verifier
 * rejects too. A stream a client consumes as it arrives, the verifier
 * accepts, and each of its events validates against the schema. The streams
 * a client only survives by stripping or skipping something are the
 * asymmetry the processing model draws: a producer must never send that
 * material, so the verifier rejects them and names the rule.
 */
final class OfficialStreamsTest extends ConformanceTestCase
{
    /** Consumable for a client, forbidden for a producer. */
    private const array PRODUCER_MUST_NOT = [
        'activity-delta-missing-target-tolerated' => OrderingRule::ActivityBaseline,
        'era-0-0-45-thinking-translated' => OrderingRule::ClosedObjects,
        'state-delta-unknown-op-dropped' => OrderingRule::ClosedObjects,
        'unknown-event-dropped' => OrderingRule::ClosedObjects,
        'unknown-outcome-stripped' => OrderingRule::ClosedObjects,
        'unknown-part-in-message-list' => OrderingRule::ClosedObjects,
        'unknown-property-stripped' => OrderingRule::ClosedObjects,
    ];

    /**
     * @return iterable<string, array{string, \stdClass}>
     */
    public static function fixtures(): iterable
    {
        $files = glob(__DIR__ . '/Streams/*.json');
        foreach ($files === false ? [] : $files as $file) {
            $fixture = json_decode((string)file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);
            if ($fixture instanceof \stdClass) {
                yield basename($file, '.json') => [basename($file, '.json'), $fixture];
            }
        }
    }

    #[Test]
    public function theWholeCorpusIsHere(): void
    {
        $manifest = array_values(array_filter(
            array_map(trim(...), file(__DIR__ . '/Streams/MANIFEST.txt') ?: []),
            static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#'),
        ));
        $files = array_map(basename(...), glob(__DIR__ . '/Streams/*.json') ?: []);

        self::assertSame($manifest, $files);
    }

    #[Test]
    #[DataProvider('fixtures')]
    public function theVerifierReachesTheSpecificationsVerdict(string $name, \stdClass $fixture): void
    {
        $stream = is_array($fixture->stream ?? null) ? $fixture->stream : [];
        $clientRejects = ($fixture->expect->outcome ?? null) === 'failed';
        $rule = self::PRODUCER_MUST_NOT[$name] ?? null;

        try {
            EventVerifier::verify($this->objects($stream), requireProtocolVersion: false);
        } catch (ProtocolViolation $violation) {
            self::assertTrue($clientRejects || $rule !== null, $name . ' is a stream a client consumes, yet: ' . $violation->getMessage());
            if ($rule !== null) {
                self::assertSame($rule, $violation->rule, $violation->getMessage());
            }
            return;
        }
        self::assertFalse($clientRejects, $name . ' must be rejected: ' . (string)($fixture->description ?? ''));
        self::assertNull($rule, $name . ' carries material a producer must not send.');
        foreach ($stream as $index => $event) {
            self::assertConformsTo(SchemaValidator::AGUI_SCHEMA_ID . '#Event', $event, $name . ' event #' . $index);
        }
    }

    /**
     * @param array<mixed> $stream
     * @return list<\stdClass>
     */
    private function objects(array $stream): array
    {
        return array_values(array_filter($stream, static fn(mixed $event): bool => $event instanceof \stdClass));
    }
}
