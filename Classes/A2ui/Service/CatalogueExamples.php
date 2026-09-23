<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Webconsulting\AgentNexus\A2ui\Domain\Model\A2uiVersion;
use Webconsulting\AgentNexus\A2ui\Domain\Model\SurfaceBuilder;

/**
 * One small surface per catalogue component, for the catalogue screen's live
 * examples. Each is written in the v0.9.1 shape and goes through the
 * sanitiser for the version shown, so an example can never show more than
 * the catalogue allows.
 */
final readonly class CatalogueExamples
{
    public function __construct(
        private SurfaceSanitizer $sanitizer,
        private MessageBuilder $messages,
    ) {}

    /**
     * The messages that render an example of one component.
     *
     * @param string $imageUrl an image the Image example may show
     * @return list<array<string, mixed>>
     */
    public function messages(string $component, A2uiVersion $version, string $imageUrl = ''): array
    {
        $b = new SurfaceBuilder();
        $this->write($b, $component, $imageUrl);
        $surface = $b->build('example-' . strtolower($component), $component);
        $clean = $this->sanitizer->sanitize($surface->componentsToArray(), $surface->dataModel, $version);
        return $this->messages->surface($surface->withContent($clean->components, $clean->dataModel), $version);
    }

    private function write(SurfaceBuilder $b, string $component, string $imageUrl): void
    {
        $text = static fn(string $id, string $value, string $variant = 'body'): string => $b->text($id, $value, $variant);
        $button = static fn(string $id, string $label, string $variant): string => $b->button($id, $label, SurfaceBuilder::event($id), $variant);

        match ($component) {
            'Text' => $b->column('root', [
                $text('heading', 'Opening hours', 'h2'),
                $text('body', 'We are open **Monday to Friday**, 9:00 to 17:00.'),
                $text('caption', 'Closed on public holidays.', 'caption'),
            ]),
            'Image' => $b->component('root', 'Image', [
                'url' => $imageUrl,
                'description' => 'The A2UI sequence diagram',
                'fit' => 'contain',
                'variant' => 'mediumFeature',
            ]),
            'Icon' => $b->row('root', array_map(
                static fn(string $name, string $label): string => $b->icon('icon_' . $name, $name, $label),
                ['mail', 'phone', 'calendarToday', 'star', 'check', 'warning', 'info', 'settings'],
                ['Email', 'Phone', 'Calendar', 'Favourite', 'Done', 'Warning', 'Information', 'Settings'],
            ), align: 'center'),
            'Video' => $b->component('root', 'Video', ['url' => SurfaceBuilder::path('media')]),
            'AudioPlayer' => $b->component('root', 'AudioPlayer', ['url' => SurfaceBuilder::path('media'), 'description' => 'Episode 12']),
            'Row' => $b->row('root', [$text('one', 'One'), $text('two', 'Two'), $text('three', 'Three')], 'spaceBetween'),
            'Column' => $b->column('root', [$text('first', 'First'), $text('second', 'Second'), $text('third', 'Third')], align: 'center'),
            'List' => $b->list('root', '/drinks', $b->row('drink', [
                $b->text('drink_name', ['path' => 'name']),
                $b->text('drink_price', ['call' => 'formatCurrency', 'args' => ['value' => ['path' => 'price'], 'currency' => 'EUR']]),
            ], 'spaceBetween')),
            'Card' => $b->card('root', $b->column('card', [
                $text('card_title', 'A card', 'h3'),
                $text('card_text', 'It holds exactly one child, usually a Column.'),
            ])),
            'Tabs' => $b->tabs('root', [
                'Details' => $text('details', 'What is included.'),
                'Delivery' => $text('delivery', 'When it arrives.'),
            ]),
            'Modal' => $b->modal(
                'root',
                $button('open_dialog', 'Open the dialog', 'default'),
                $b->column('dialog', [
                    $text('dialog_title', 'A dialog', 'h3'),
                    $text('dialog_text', 'Press Escape or the close button to close it.'),
                ]),
            ),
            'Divider' => $b->column('root', [$text('above', 'Above the line'), $b->divider('line'), $text('below', 'Below the line')]),
            'Button' => $b->row('root', [
                $button('primary', 'Primary', 'primary'),
                $button('default', 'Default', 'default'),
                $button('borderless', 'Borderless', 'borderless'),
            ], align: 'center'),
            'TextField' => $b->column('root', [
                $b->textField('name', 'Name', checks: [SurfaceBuilder::check('required', 'name', 'Enter a name.')]),
                $b->textField('quantity', 'Quantity', 'number'),
                $b->textField('password', 'Password', 'obscured'),
                $b->textField('message', 'Message', 'longText'),
            ]),
            'CheckBox' => $b->checkBox('root', 'I agree to the terms'),
            'ChoicePicker' => $b->column('root', [
                $b->choice('size', 'Size', ['s' => 'Small', 'm' => 'Medium', 'l' => 'Large'], ['m']),
                $b->choice('toppings', 'Toppings', ['cheese' => 'Cheese', 'olives' => 'Olives', 'basil' => 'Basil', 'chilli' => 'Chilli'], ['cheese'], multiple: true, chips: true),
            ]),
            'Slider' => $b->slider('root', 'Budget in thousand euros', 1, 50, 10),
            'DateTimeInput' => $b->column('root', [
                $b->dateTime('day', 'Delivery date', true, false),
                $b->dateTime('time', 'Time', false, true),
                $b->dateTime('appointment', 'Appointment', true, true),
            ]),
            default => $text('root', 'No example for ' . $component . '.'),
        };

        if ($component === 'List') {
            $b->data('drinks', [
                ['name' => 'Espresso', 'price' => 2.4],
                ['name' => 'Cappuccino', 'price' => 3.6],
                ['name' => 'Tea', 'price' => 2.9],
            ]);
        }
        if ($component === 'Video' || $component === 'AudioPlayer') {
            $b->data('media', '');
        }
    }
}
