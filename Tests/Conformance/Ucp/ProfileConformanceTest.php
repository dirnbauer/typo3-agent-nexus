<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Conformance\Ucp;

use PHPUnit\Framework\Attributes\Test;
use Webconsulting\AgentNexus\Tests\Conformance\ConformanceTestCase;
use Webconsulting\AgentNexus\Ucp\Payment\SandboxPaymentHandler;
use Webconsulting\AgentNexus\Ucp\Profile\ProfileBuilder;
use Webconsulting\AgentNexus\Ucp\Spec;

/**
 * The two published profiles against UCP 2026-08-25's profile schema.
 */
final class ProfileConformanceTest extends ConformanceTestCase
{
    private const string BUSINESS = 'https://ucp.dev/schemas/profile.json#/$defs/business_schema';
    private const string PLATFORM = 'https://ucp.dev/schemas/profile.json#/$defs/platform_schema';

    #[Test]
    public function theBusinessProfileIsAValidBusinessProfile(): void
    {
        self::assertConformsTo(self::BUSINESS, $this->business());
    }

    #[Test]
    public function thePlatformProfileIsAValidPlatformProfile(): void
    {
        self::assertConformsTo(self::PLATFORM, (new ProfileBuilder(new SandboxPaymentHandler()))->platform());
    }

    #[Test]
    public function everyUcpEntryCarriesTheProfileVersion(): void
    {
        $ucp = $this->business()['ucp'];
        self::assertSame(Spec::VERSION, $ucp['version']);
        foreach (['services', 'capabilities'] as $registry) {
            self::assertIsArray($ucp[$registry]);
            foreach ($ucp[$registry] as $name => $entries) {
                self::assertStringStartsWith('dev.ucp.', (string)$name);
                self::assertIsArray($entries);
                foreach ($entries as $entry) {
                    self::assertIsArray($entry);
                    self::assertSame(Spec::VERSION, $entry['version'], $name . ' must carry the profile version');
                }
            }
        }
    }

    #[Test]
    public function theProfileChecksTheBackendShowsAllPass(): void
    {
        $builder = new ProfileBuilder(new SandboxPaymentHandler());

        foreach ($builder->checks($this->business()) as $check) {
            self::assertTrue($check['pass'], $check['key'] . ': ' . $check['detail']);
        }
    }

    #[Test]
    public function theChecksCatchAnEndpointWithoutHttps(): void
    {
        $builder = new ProfileBuilder(new SandboxPaymentHandler());
        $failed = array_column(array_filter($builder->checks($builder->business('http://shop.example/api')), static fn(array $c): bool => !$c['pass']), 'key');

        self::assertSame(['https'], $failed);
    }

    #[Test]
    public function anEmptyHandlerRegistryWrittenAsAJsonArrayIsRejected(): void
    {
        $profile = $this->business();
        $profile['ucp']['payment_handlers'] = [];

        self::assertViolates(self::BUSINESS, $profile, 'An empty registry is {}, never [].');
    }

    #[Test]
    public function aCapabilityWithoutItsSchemaIsRejected(): void
    {
        $profile = $this->business();
        unset($profile['ucp']['capabilities'][Spec::CAPABILITY_CHECKOUT][0]['schema']);

        self::assertViolates(self::BUSINESS, $profile);
    }

    #[Test]
    public function aRestServiceWithoutItsEndpointIsRejected(): void
    {
        $profile = $this->business();
        unset($profile['ucp']['services'][Spec::SERVICE_SHOPPING][0]['endpoint']);

        self::assertViolates(self::BUSINESS, $profile);
    }

    #[Test]
    public function aVersionThatIsNotADateIsRejected(): void
    {
        $profile = $this->business();
        $profile['ucp']['version'] = '0.1';

        self::assertViolates(self::BUSINESS, $profile, '3.1 published "0.1".');
    }

    /**
     * @return array{ucp: array<string, mixed>}
     */
    private function business(): array
    {
        return (new ProfileBuilder(new SandboxPaymentHandler()))->business('https://shop.example/api/agent-nexus');
    }
}
