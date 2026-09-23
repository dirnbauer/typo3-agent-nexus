<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Http\A2uiProblem;
use Webconsulting\AgentNexus\A2ui\Service\VersionNegotiator;

final class VersionNegotiatorTest extends UnitTestCase
{
    private const string V09 = 'https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json';
    private const string V10 = 'https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json';

    private VersionNegotiator $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new VersionNegotiator();
    }

    #[Test]
    public function withoutAPreferenceTheStableVersionIsUsed(): void
    {
        self::assertSame(A2uiVersion::V0_9_1, $this->subject->negotiate(['intent' => 'x']));
    }

    #[Test]
    public function theRequestedVersionWins(): void
    {
        self::assertSame(A2uiVersion::V1_0, $this->subject->negotiate(['version' => 'v1.0']));
        self::assertSame(A2uiVersion::V0_9_1, $this->subject->negotiate(['version' => 'v0.9']));
    }

    #[Test]
    public function anUnknownVersionIsRefused(): void
    {
        try {
            $this->subject->negotiate(['version' => 'v0.8']);
            self::fail('Expected a refusal.');
        } catch (A2uiProblem $problem) {
            self::assertSame('UNSUPPORTED_VERSION', $problem->errorCode);
            self::assertSame(422, $problem->status);
            self::assertSame('/version', $problem->path);
        }
    }

    #[Test]
    public function capabilitiesChooseTheVersionAndPreferTheStableOne(): void
    {
        self::assertSame(A2uiVersion::V1_0, $this->subject->negotiate([
            'a2uiRendererCapabilities' => ['v1.0' => ['supportedCatalogIds' => [self::V10]]],
        ]));
        self::assertSame(A2uiVersion::V0_9_1, $this->subject->negotiate([
            'metadata' => [
                'a2uiClientCapabilities' => ['v0.9' => ['supportedCatalogIds' => [self::V09]]],
                'a2uiRendererCapabilities' => ['v1.0' => ['supportedCatalogIds' => [self::V10]]],
            ],
        ]));
    }

    #[Test]
    public function theV091SpellingOfTheCatalogueIdCounts(): void
    {
        self::assertSame(A2uiVersion::V0_9_1, $this->subject->negotiate([
            'a2uiClientCapabilities' => ['v0.9' => ['supportedCatalogIds' => ['https://a2ui.org/specification/v0_9_1/catalogs/basic/catalog.json']]],
        ]));
    }

    #[Test]
    public function aRendererWithoutTheBasicCatalogueIsRefused(): void
    {
        try {
            $this->subject->negotiate(['a2uiClientCapabilities' => ['v0.9' => ['supportedCatalogIds' => ['https://example.org/my-catalog.json']]]]);
            self::fail('Expected a refusal.');
        } catch (A2uiProblem $problem) {
            self::assertSame('UNSUPPORTED_CATALOG', $problem->errorCode);
        }
    }

    #[Test]
    public function aRequestedVersionTheCapabilitiesDoNotCoverIsRefused(): void
    {
        try {
            $this->subject->negotiate([
                'version' => 'v1.0',
                'a2uiClientCapabilities' => ['v0.9' => ['supportedCatalogIds' => [self::V09]]],
            ]);
            self::fail('Expected a refusal.');
        } catch (A2uiProblem $problem) {
            self::assertSame('UNSUPPORTED_CATALOG', $problem->errorCode);
            self::assertSame(A2uiVersion::V1_0, $problem->version);
        }
    }

    #[Test]
    public function capabilitiesMustBeAnObject(): void
    {
        try {
            $this->subject->negotiate(['a2uiClientCapabilities' => [self::V09]]);
            self::fail('Expected a refusal.');
        } catch (A2uiProblem $problem) {
            self::assertSame(A2uiProblem::VALIDATION_FAILED, $problem->errorCode);
            self::assertSame('/a2uiClientCapabilities', $problem->path);
        }
    }
}
