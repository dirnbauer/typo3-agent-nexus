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
            self::assertIsInt($product['price'], $product['id'] . ' must be priced in cents as an integer');
            self::assertGreaterThan(0, $product['price']);
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
        $catalog = $this->subject->catalog();
        $ids = array_column($catalog, 'id');

        self::assertSame($ids, array_unique($ids), 'Duplicate product ids would make a cart ambiguous.');

        foreach ($ids as $id) {
            self::assertSame($id, $this->subject->product((string)$id)['id']);
        }
    }

    #[Test]
    public function anUnknownProductResolvesToNothingRatherThanAGuess(): void
    {
        self::assertSame([], $this->subject->product('no-such-product'));
    }

    #[Test]
    public function everyProductCarriesWhatACartRowNeeds(): void
    {
        foreach ($this->subject->catalog() as $product) {
            foreach (['id', 'name', 'price', 'unit', 'tags', 'description'] as $field) {
                self::assertArrayHasKey($field, $product);
                self::assertNotEmpty($product[$field], $product['id'] . '.' . $field);
            }
        }
    }
}
