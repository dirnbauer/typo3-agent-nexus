<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ucp\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ucp\Service\Merchant;

/**
 * A shopping agent may write the rationale; it must never write a price. These
 * tests pin the catalogue down as the only source of money in the UCP demo.
 */
final class MerchantTest extends UnitTestCase
{
    private Merchant $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new Merchant();
    }

    #[Test]
    public function pricesAreWholeMinorUnitsSoNoFloatMathCanCreep(): void
    {
        foreach ($this->subject->catalog() as $product) {
            self::assertGreaterThan(0, $product['price'], $product['id'] . ' must be priced in cents');
        }
    }

    #[Test]
    public function theCatalogueIsDeterministicAcrossCalls(): void
    {
        self::assertSame($this->subject->catalog(), $this->subject->catalog());
    }

    #[Test]
    public function everyProductIdIsUniqueAndResolvable(): void
    {
        $ids = array_column($this->subject->catalog(), 'id');

        self::assertSame($ids, array_unique($ids), 'Duplicate product ids would make a cart ambiguous.');
        foreach ($ids as $id) {
            $product = $this->subject->product($id);
            self::assertNotSame([], $product);
            self::assertSame($id, $product['id']);
            self::assertTrue($this->subject->sells($id));
        }
    }

    #[Test]
    public function anUnknownProductResolvesToNothingRatherThanAGuess(): void
    {
        self::assertSame([], $this->subject->product('no-such-product'));
        self::assertFalse($this->subject->sells('no-such-product'));
        self::assertFalse($this->subject->isMonthly('no-such-product'));
    }

    #[Test]
    public function everyProductCarriesWhatACartLineNeeds(): void
    {
        foreach ($this->subject->catalog() as $product) {
            self::assertNotSame('', $product['name']);
            self::assertNotSame('', $product['description']);
            self::assertContains($product['unit'], ['/mo', 'one-time']);
        }
    }

    #[Test]
    public function subscriptionsAreBilledMonthly(): void
    {
        self::assertTrue($this->subject->isMonthly('pro-license'));
        self::assertFalse($this->subject->isMonthly('onboarding-addon'));
    }
}
