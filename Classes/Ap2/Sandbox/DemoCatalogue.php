<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Webconsulting\AgentNexus\Ucp\Service\Merchant as UcpMerchant;

/**
 * The products and prices the AP2 demo shops for, read from the UCP demo
 * store so both protocols sell the same catalogue.
 *
 * The shopping list is what the agent asks the person to approve: a licence
 * (either of two), onboarding and support, one of each. With the default cap
 * of €500 the cheaper licence fits and the dearer one does not, which is what
 * the widget's two buttons show.
 */
final readonly class DemoCatalogue
{
    /** @var list<array{id: string, title: string, options: list<string>}> */
    private const array SHOPPING_LIST = [
        ['id' => 'licence', 'title' => 'Licence', 'options' => ['pro-license', 'agency-bundle']],
        ['id' => 'onboarding', 'title' => 'Onboarding', 'options' => ['onboarding-addon']],
        ['id' => 'support', 'title' => 'Support', 'options' => ['support-pack']],
    ];

    public function __construct(
        private UcpMerchant $store,
    ) {}

    /**
     * Every product with an id, a title and an integer price in cents.
     *
     * @return list<array{id: string, title: string, price: int}>
     */
    public function items(): array
    {
        $items = [];
        foreach ($this->store->catalog() as $product) {
            $id = $product['id'] ?? null;
            $title = $product['title'] ?? ($product['name'] ?? null);
            $price = $product['price'] ?? null;
            if (is_string($id) && $id !== '' && is_string($title) && is_int($price) && $price >= 0) {
                $items[] = ['id' => $id, 'title' => $title, 'price' => $price];
            }
        }
        return $items;
    }

    /**
     * @return array{id: string, title: string, price: int}|null
     */
    public function item(string $id): ?array
    {
        return array_find($this->items(), static fn(array $item): bool => $item['id'] === $id);
    }

    /**
     * The lines the agent asks approval for, each with the products that may
     * fill it. Should the store's catalogue change, the list is rebuilt from
     * whatever it offers, so the demo keeps working.
     *
     * @return list<array{id: string, title: string, quantity: int, options: list<array{id: string, title: string, price: int}>}>
     */
    public function shoppingList(): array
    {
        $lines = [];
        foreach (self::SHOPPING_LIST as $line) {
            $options = array_values(array_filter(array_map($this->item(...), $line['options'])));
            if ($options === []) {
                return $this->fallbackList();
            }
            $lines[] = ['id' => $line['id'], 'title' => $line['title'], 'quantity' => 1, 'options' => $options];
        }
        return $lines;
    }

    /**
     * @return list<array{id: string, title: string, quantity: int, options: list<array{id: string, title: string, price: int}>}>
     */
    private function fallbackList(): array
    {
        $items = $this->items();
        if ($items === []) {
            return [];
        }
        $lines = [['id' => 'line-1', 'title' => $items[0]['title'], 'quantity' => 1, 'options' => array_slice($items, 0, 2)]];
        foreach (array_slice($items, 2, 2) as $index => $item) {
            $lines[] = ['id' => 'line-' . ($index + 2), 'title' => $item['title'], 'quantity' => 1, 'options' => [$item]];
        }
        return $lines;
    }
}
