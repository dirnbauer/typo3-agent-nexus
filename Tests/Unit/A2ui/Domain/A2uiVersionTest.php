<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\A2ui\Domain;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;

final class A2uiVersionTest extends UnitTestCase
{
    #[Test]
    public function theStableReleaseIsTheDefault(): void
    {
        self::assertSame(A2uiVersion::DEFAULT, A2uiVersion::fromWire('v0.9.1'));
        self::assertTrue(A2uiVersion::V0_9_1->isStable());
        self::assertFalse(A2uiVersion::V1_0->isStable());
    }

    #[Test]
    public function v09AndV091AreOneVersionOnTheWire(): void
    {
        self::assertSame(A2uiVersion::V0_9_1, A2uiVersion::fromWire('v0.9'));
        self::assertSame(A2uiVersion::V0_9_1, A2uiVersion::fromWire('v0.9.1'));
        self::assertSame(A2uiVersion::V1_0, A2uiVersion::fromWire('v1.0'));
        self::assertNull(A2uiVersion::fromWire('v0.8'));
        self::assertNull(A2uiVersion::fromWire('1.0'));
        self::assertNull(A2uiVersion::fromWire(null));
    }

    #[Test]
    public function eachVersionNamesItsOwnCatalogueAndMetadataMembers(): void
    {
        self::assertSame('https://a2ui.org/specification/v0_9/catalogs/basic/catalog.json', A2uiVersion::V0_9_1->catalogId());
        self::assertSame('https://a2ui.org/specification/v1_0/catalogs/basic/catalog.json', A2uiVersion::V1_0->catalogId());
        self::assertSame('v0.9', A2uiVersion::V0_9_1->capabilitiesKey());
        self::assertSame('a2uiClientDataModel', A2uiVersion::V0_9_1->dataModelMember());
        self::assertSame('a2uiRendererDataModel', A2uiVersion::V1_0->dataModelMember());
        self::assertSame('a2uiRendererCapabilities', A2uiVersion::V1_0->capabilitiesMember());
        self::assertSame('https://a2ui.org/a2a-extension/a2ui/v0.9.1', A2uiVersion::V0_9_1->extensionUri());
    }

    #[Test]
    public function theV091ProseSpellingOfTheCatalogueIdIsAccepted(): void
    {
        self::assertContains('https://a2ui.org/specification/v0_9_1/catalogs/basic/catalog.json', A2uiVersion::V0_9_1->catalogIds());
        self::assertSame([A2uiVersion::V1_0->catalogId()], A2uiVersion::V1_0->catalogIds());
    }
}
