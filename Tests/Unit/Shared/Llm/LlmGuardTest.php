<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Shared\Llm;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;

/**
 * The guard is the only cost brake on streamed calls, because nr-llm's own
 * budget middleware does not run on that path. Every "no" it can give is worth
 * a test, and so is the reason it reports — the plugins show that reason to the
 * visitor as provenance.
 */
final class LlmGuardTest extends UnitTestCase
{
    #[Test]
    public function withoutNrLlmNothingIsAllowed(): void
    {
        $result = $this->guard(['llmFrontendEnabled' => true, 'a2uiLlmEnabled' => true], available: false)->allows('a2ui');

        self::assertFalse($result['allowed']);
        self::assertSame('nr-llm not installed', $result['reason']);
    }

    #[Test]
    public function theGlobalFrontendSwitchOverridesThePerProtocolToggle(): void
    {
        $result = $this->guard(['llmFrontendEnabled' => false, 'a2uiLlmEnabled' => true])->allows('a2ui');

        self::assertFalse($result['allowed']);
        self::assertSame('frontend LLM disabled', $result['reason']);
    }

    #[Test]
    public function aProtocolWithItsToggleOffIsRefusedEvenWhenEverythingElseIsOn(): void
    {
        $result = $this->guard(['llmFrontendEnabled' => true, 'ap2LlmEnabled' => false])->allows('ap2');

        self::assertFalse($result['allowed']);
        self::assertSame('ap2 LLM disabled', $result['reason']);
    }

    #[Test]
    public function aProtocolWithNoToggleAtAllDefaultsToOff(): void
    {
        self::assertFalse($this->guard(['llmFrontendEnabled' => true])->allows('a2ui')['allowed']);
    }

    #[Test]
    public function spendingTheDailyBudgetStopsFurtherCalls(): void
    {
        $result = $this->guard(
            ['llmFrontendEnabled' => true, 'a2uiLlmEnabled' => true, 'llmDailyBudget' => 2.0],
            costToday: 2.0,
        )->allows('a2ui');

        self::assertFalse($result['allowed']);
        self::assertSame('daily LLM budget reached', $result['reason']);
    }

    #[Test]
    public function stayingUnderTheBudgetIsAllowed(): void
    {
        $result = $this->guard(
            ['llmFrontendEnabled' => true, 'a2uiLlmEnabled' => true, 'llmDailyBudget' => 2.0],
            costToday: 1.99,
        )->allows('a2ui');

        self::assertTrue($result['allowed']);
        self::assertSame('', $result['reason']);
    }

    #[Test]
    public function aBudgetOfZeroMeansNoCapRatherThanNoCalls(): void
    {
        $result = $this->guard(
            ['llmFrontendEnabled' => true, 'a2uiLlmEnabled' => true, 'llmDailyBudget' => 0],
            costToday: 999.0,
        )->allows('a2ui');

        self::assertTrue($result['allowed']);
    }

    #[Test]
    public function unreadableExtensionConfigurationDoesNotCrashTheGuard(): void
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new \RuntimeException('not installed', 1751400200));

        $guard = new LlmGuard($extensionConfiguration, $this->client(true), $this->tracker(0.0));

        self::assertFalse($guard->allows('a2ui')['allowed']);
    }

    #[Test]
    public function theTokenCeilingCapsWhatAPluginMayAskFor(): void
    {
        $guard = $this->guard(['llmMaxOutputTokens' => 500]);

        self::assertSame(500, $guard->maxOutputTokens(null), 'No request means the ceiling.');
        self::assertSame(300, $guard->maxOutputTokens(300), 'A plugin may ask for less.');
        self::assertSame(500, $guard->maxOutputTokens(5000), 'A plugin may never ask for more.');
        self::assertSame(500, $guard->maxOutputTokens(0), 'A nonsense request means the ceiling.');
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function guard(array $configuration, bool $available = true, float $costToday = 0.0): LlmGuard
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($configuration);

        return new LlmGuard($extensionConfiguration, $this->client($available), $this->tracker($costToday));
    }

    private function client(bool $available): LanguageModel
    {
        $client = self::createStub(LanguageModel::class);
        $client->method('isAvailable')->willReturn($available);
        return $client;
    }

    private function tracker(float $costToday): UsageLedger
    {
        $tracker = self::createStub(UsageLedger::class);
        $tracker->method('getCostToday')->willReturn($costToday);
        return $tracker;
    }
}
