<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Shared\Llm;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Shared\Llm\LanguageModel;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Shared\Protocol;

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
    public function everyProtocolHasItsOwnOutputBudgetByDefault(): void
    {
        $guard = $this->guard([]);

        self::assertSame(1600, $guard->maxOutputTokens(Protocol::A2ui), 'A generated form needs 500 to 900 tokens.');
        self::assertSame(700, $guard->maxOutputTokens(Protocol::Agui));
        self::assertSame(400, $guard->maxOutputTokens(Protocol::A2a));
        self::assertSame(160, $guard->maxOutputTokens(Protocol::Ucp));
        self::assertSame(160, $guard->maxOutputTokens(Protocol::Ap2));
    }

    #[Test]
    public function theStoredGlobalValueNoLongerCutsA2uiFormsShort(): void
    {
        // What every installation that ran extension:setup on 4.0.0 has stored.
        $guard = $this->guard(['llmMaxOutputTokens' => '700']);

        self::assertSame(1600, $guard->maxOutputTokens(Protocol::A2ui));
    }

    #[Test]
    public function aConfiguredProtocolBudgetWins(): void
    {
        $guard = $this->guard(['a2uiLlmMaxOutputTokens' => '2400', 'ucpLlmMaxOutputTokens' => 90, 'llmMaxOutputTokens' => '700']);

        self::assertSame(2400, $guard->maxOutputTokens(Protocol::A2ui));
        self::assertSame(90, $guard->maxOutputTokens(Protocol::Ucp));
        self::assertSame(400, $guard->maxOutputTokens(Protocol::A2a), 'An unset protocol keeps its default.');
    }

    #[Test]
    public function aProtocolBudgetOfZeroFallsBackToTheGlobalSetting(): void
    {
        self::assertSame(900, $this->guard(['a2uiLlmMaxOutputTokens' => '0', 'llmMaxOutputTokens' => '900'])->maxOutputTokens(Protocol::A2ui));
        self::assertSame(700, $this->guard(['a2uiLlmMaxOutputTokens' => '0'])->maxOutputTokens(Protocol::A2ui), 'Without a global value, its default.');
        self::assertSame(700, $this->guard(['a2uiLlmMaxOutputTokens' => '0', 'llmMaxOutputTokens' => '0'])->maxOutputTokens(Protocol::A2ui), 'Never an unlimited call.');
    }

    #[Test]
    public function aPluginMayAskForLessThanTheBudgetButNeverForMore(): void
    {
        $guard = $this->guard(['aguiLlmMaxOutputTokens' => 500]);

        self::assertSame(500, $guard->maxOutputTokens(Protocol::Agui, null), 'No request means the budget.');
        self::assertSame(300, $guard->maxOutputTokens(Protocol::Agui, 300), 'A plugin may ask for less.');
        self::assertSame(500, $guard->maxOutputTokens(Protocol::Agui, 5000), 'A plugin may never ask for more.');
        self::assertSame(500, $guard->maxOutputTokens(Protocol::Agui, 0), 'A nonsense request means the budget.');
    }

    #[Test]
    public function theDefaultsMatchTheExtensionConfigurationTemplate(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 4) . '/ext_conf_template.txt');

        foreach (Protocol::cases() as $protocol) {
            self::assertMatchesRegularExpression(
                sprintf('/^%sLlmMaxOutputTokens = %d$/m', $protocol->value, LlmGuard::DEFAULT_OUTPUT_BUDGETS[$protocol->value]),
                $template,
                sprintf('ext_conf_template.txt and LlmGuard disagree about the %s budget.', $protocol->label()),
            );
        }
        self::assertMatchesRegularExpression(sprintf('/^llmMaxOutputTokens = %d$/m', LlmGuard::DEFAULT_FALLBACK_BUDGET), $template);
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
