<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Tests\Unit\Ap2\Mandate;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\AgentNexus\Ap2\Mandate\LineItemMatcher;

/**
 * `checkout.line_items` as the AP2 checkout mandate page defines it — the
 * shoe-and-socks example is the one from the specification.
 */
final class LineItemMatcherTest extends UnitTestCase
{
    /** @var list<array{id: string, acceptable: list<string>, quantity: int}> */
    private const array SHOES_AND_SOCKS = [
        ['id' => 'id-shoe-choices', 'acceptable' => ['BAB1234', 'FAF1234'], 'quantity' => 1],
        ['id' => 'id-sock-choices', 'acceptable' => ['QRT1234'], 'quantity' => 1],
    ];

    /**
     * @return array<string, array{0: array<string, int>}>
     */
    public static function fulfilling(): array
    {
        return [
            'red shoes and socks' => [['BAB1234' => 1, 'QRT1234' => 1]],
            'blue shoes and socks' => [['FAF1234' => 1, 'QRT1234' => 1]],
        ];
    }

    /**
     * @param array<string, int> $checkout
     */
    #[Test]
    #[DataProvider('fulfilling')]
    public function theSpecificationsValidCombinationsPass(array $checkout): void
    {
        self::assertSame([], LineItemMatcher::violations($checkout, self::SHOES_AND_SOCKS));
    }

    /**
     * @return array<string, array{0: array<string, int>}>
     */
    public static function violating(): array
    {
        return [
            'both shoes, no socks' => [['BAB1234' => 1, 'FAF1234' => 1]],
            'red shoes only' => [['BAB1234' => 1]],
            'blue shoes only' => [['FAF1234' => 1]],
            'socks only' => [['QRT1234' => 1]],
            'two pairs of socks' => [['BAB1234' => 1, 'QRT1234' => 2]],
            'an item nobody approved' => [['BAB1234' => 1, 'QRT1234' => 1, 'HAT999' => 1]],
            'nothing at all' => [[]],
        ];
    }

    /**
     * @param array<string, int> $checkout
     */
    #[Test]
    #[DataProvider('violating')]
    public function theSpecificationsInvalidCombinationsFail(array $checkout): void
    {
        self::assertNotSame([], LineItemMatcher::violations($checkout, self::SHOES_AND_SOCKS));
    }

    #[Test]
    public function oneItemCannotFillTwoRequirements(): void
    {
        $requirements = [
            ['id' => 'a', 'acceptable' => ['X', 'Y'], 'quantity' => 1],
            ['id' => 'b', 'acceptable' => ['X'], 'quantity' => 1],
        ];

        // Greedy matching would put X on "a" and then fail; the flow finds X→b, Y→a.
        self::assertSame([], LineItemMatcher::violations(['X' => 1, 'Y' => 1], $requirements));
        self::assertNotSame([], LineItemMatcher::violations(['X' => 1], $requirements));
    }

    #[Test]
    public function quantitiesMustAddUpExactly(): void
    {
        $requirements = [['id' => 'seats', 'acceptable' => ['licence'], 'quantity' => 3]];

        self::assertSame([], LineItemMatcher::violations(['licence' => 3], $requirements));
        self::assertSame(['The approved list asks for 3 items; the checkout has 2.'], LineItemMatcher::violations(['licence' => 2], $requirements));
        self::assertNotSame([], LineItemMatcher::violations(['licence' => 4], $requirements));
    }

    #[Test]
    public function aRequirementWithNothingDisclosedMatchesNothing(): void
    {
        // The SDK would treat this as a wildcard; the specification does not.
        self::assertSame(['"anything" is not on the approved list.'], LineItemMatcher::violations(
            ['anything' => 1],
            [['id' => 'hidden', 'acceptable' => [], 'quantity' => 1]],
        ));
    }
}
