<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Service;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\Component;
use Webconsulting\AgentNexus\A2ui\Domain\Model\GenerationRequest;
use Webconsulting\AgentNexus\A2ui\Domain\Model\GenerationResult;
use Webconsulting\AgentNexus\A2ui\Domain\Repository\ComponentRegistry;
use Webconsulting\AgentNexus\A2ui\Service\AgentService;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceGenerator;
use Webconsulting\AgentNexus\A2ui\Service\SurfaceSanitizer;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;
use Webconsulting\AgentNexus\Shared\Llm\LlmGuard;
use Webconsulting\AgentNexus\Shared\Llm\UsageLedger;
use Webconsulting\AgentNexus\Tests\Unit\A2ui\Fixtures\RecordingUsageLedger;
use Webconsulting\AgentNexus\Tests\Unit\A2ui\Fixtures\ScriptedLanguageModel;

/**
 * The agent may use a model, but every road leads to a renderable surface:
 * the model's answer is sanitised, and anything that goes wrong on the way
 * hands over to the built-in generator with a note that says why.
 */
final class AgentServiceTest extends UnitTestCase
{
    private RecordingUsageLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledger = new RecordingUsageLedger();
    }

    #[Test]
    public function withoutAModelTheBuiltInGeneratorAnswersAndSaysSo(): void
    {
        $result = $this->agent(model: null)->generate(new GenerationRequest('I need a quote'));

        self::assertSame(GenerationResult::MODE_BUILTIN, $result->mode);
        self::assertSame('Scripted demo', $result->label());
        self::assertMatchesRegularExpression('/^quote-[0-9a-f]{8}$/', $result->surface->surfaceId);
        self::assertContains('No model is configured (netresearch/nr-llm); the built-in generator answered.', $result->notes);
    }

    #[Test]
    public function surfaceIdsAreNeverReused(): void
    {
        $agent = $this->agent(model: null);

        self::assertNotSame(
            $agent->generate(new GenerationRequest('a contact form'))->surface->surfaceId,
            $agent->generate(new GenerationRequest('a contact form'))->surface->surfaceId,
        );
    }

    #[Test]
    public function aModelAnswerIsSanitisedAndItsUseRecorded(): void
    {
        $result = $this->agent(model: [
            'title' => 'Book a table',
            'components' => [
                ['id' => 'root', 'component' => 'Card', 'child' => 'form'],
                ['id' => 'form', 'component' => 'Column', 'children' => ['guests', 'send']],
                ['id' => 'guests', 'component' => 'Slider', 'label' => 'Guests', 'min' => 1, 'max' => 8, 'value' => ['path' => '/guests']],
                ['id' => 'send', 'component' => 'Button', 'text' => 'Book', 'action' => ['event' => ['name' => 'book', 'wantResponse' => true]]],
                ['id' => 'x', 'component' => 'Iframe', 'src' => 'https://example.invalid'],
            ],
            'dataModel' => ['guests' => 2],
        ])->generate(new GenerationRequest('book a table'));

        self::assertSame(GenerationResult::MODE_LLM, $result->mode);
        self::assertSame('Live model · test-model', $result->label());
        self::assertSame('Book a table', $result->surface->title);
        self::assertStringStartsWith('book-a-table-', $result->surface->surfaceId);
        $types = array_map(static fn(Component $component): string => $component->type, $result->surface->components);
        self::assertNotContains('Iframe', $types);
        self::assertSame(['event' => ['name' => 'book']], $result->surface->component('send')?->property('action'));
        self::assertNotSame([], array_filter($result->notes, static fn(string $note): bool => str_starts_with($note, 'Repaired: ')));
        self::assertSame([['protocol' => 'a2ui', 'source' => UsageLedger::SOURCE_BACKEND, 'model' => 'test-model']], $this->ledger->records);
    }

    #[Test]
    public function aModelAnswerInMessageFormIsUnderstood(): void
    {
        $result = $this->agent(model: [[
            'version' => 'v1.0',
            'createSurface' => [
                'surfaceId' => 'main',
                'components' => [['id' => 'root', 'component' => 'Text', 'text' => 'Hello']],
                'dataModel' => ['a' => 1],
            ],
        ]])->generate(new GenerationRequest('say hello', A2uiVersion::V1_0));

        self::assertSame(GenerationResult::MODE_LLM, $result->mode);
        self::assertSame(['a' => 1], $result->surface->dataModel);
    }

    #[Test]
    public function aModelAnswerWithoutARootHandsOverToTheGenerator(): void
    {
        $result = $this->agent(model: ['components' => [['id' => 'root', 'component' => 'Evil']]])
            ->generate(new GenerationRequest('a contact form'));

        self::assertSame(GenerationResult::MODE_BUILTIN, $result->mode);
        self::assertContains('The model answer had no usable root component; the built-in generator answered.', $result->notes);
    }

    #[Test]
    public function aFailingModelHandsOverToTheGeneratorWithoutLeakingDetailsInPublic(): void
    {
        $failing = new \RuntimeException('API key sk-secret rejected');

        $public = $this->agent(model: $failing, config: ['llmFrontendEnabled' => true, 'a2uiLlmEnabled' => true])
            ->generate(new GenerationRequest('a contact form', public: true));
        self::assertSame(GenerationResult::MODE_BUILTIN, $public->mode);
        self::assertContains('The model call failed; the built-in generator answered.', $public->notes);

        $backend = $this->agent(model: $failing)->generate(new GenerationRequest('a contact form'));
        self::assertStringContainsString('sk-secret', implode(' ', $backend->notes), 'Editors see the reason.');
    }

    #[Test]
    public function aCutOffAnswerIsReportedAsSuch(): void
    {
        $result = $this->agent(model: new \JsonException('Syntax error'))->generate(new GenerationRequest('a contact form'));

        self::assertContains('The model answer was not valid JSON (it may have been cut off); the built-in generator answered.', $result->notes);
    }

    #[Test]
    public function publicRequestsPassTheFrontendGuard(): void
    {
        $result = $this->agent(model: ['components' => []], config: ['llmFrontendEnabled' => false, 'a2uiLlmEnabled' => true])
            ->generate(new GenerationRequest('a contact form', public: true));

        self::assertSame(GenerationResult::MODE_BUILTIN, $result->mode);
        self::assertContains('No model call (frontend LLM disabled); the built-in generator answered.', $result->notes);
        self::assertSame([], $this->ledger->records);
    }

    #[Test]
    public function aClientOverItsModelBudgetGetsTheGenerator(): void
    {
        $result = $this->agent(model: ['components' => []])->generate(new GenerationRequest('a contact form', modelAllowed: false));

        self::assertContains('The model budget for this client is used up for now; the built-in generator answered.', $result->notes);
    }

    #[Test]
    public function theSystemPromptTeachesTheCatalogueOfTheRequestedVersion(): void
    {
        $agent = $this->agent(model: null);

        $v091 = $agent->systemPrompt(A2uiVersion::V0_9_1, 'We sell bicycles.');
        self::assertStringContainsString('version v0.9.1', $v091);
        self::assertStringContainsString('- ChoicePicker: label (text or {"path"}), variant (multipleSelection|mutuallyExclusive)', $v091);
        self::assertStringContainsString('We sell bicycles.', $v091);
        self::assertStringNotContainsString('Textarea', $v091);
        self::assertStringNotContainsString('wantResponse', $v091);

        $v10 = $agent->systemPrompt(A2uiVersion::V1_0);
        self::assertStringContainsString('placeholder (text or {"path"})', $v10);
        self::assertStringContainsString('"## ', $v10);
    }

    /**
     * @param array<array-key, mixed>|\Throwable|null $model the model's answer, an error it throws, or null for no model
     * @param array<string, mixed> $config
     */
    private function agent(array|\Throwable|null $model, array $config = ['a2uiLlmEnabled' => true]): AgentService
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($config);
        $languageModel = new ScriptedLanguageModel($model);
        $registry = new ComponentRegistry();

        return new AgentService(
            new SurfaceGenerator(),
            new SurfaceSanitizer($registry),
            $registry,
            $languageModel,
            $this->ledger,
            new LlmGuard($extensionConfiguration, $languageModel, $this->ledger),
            new ExtensionSettings($extensionConfiguration),
            new NullLogger(),
        );
    }
}
