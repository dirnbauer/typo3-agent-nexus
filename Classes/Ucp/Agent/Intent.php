<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

/**
 * What a visitor can ask the shopping agent to buy, and what it puts into the
 * checkout for each wish. The products are catalogue ids; their prices come
 * from the store, never from here.
 */
enum Intent: string
{
    case Pro = 'pro';
    case Agency = 'agency';
    case Support = 'support';

    /** The widget's button label for this wish. */
    public function label(): string
    {
        return match ($this) {
            self::Pro => 'Pro licence',
            self::Agency => 'Agency and onboarding',
            self::Support => 'Priority support',
        };
    }

    /**
     * @return list<string> catalogue ids, one line item each
     */
    public function productIds(): array
    {
        return match ($this) {
            self::Pro => ['pro-license'],
            self::Agency => ['agency-bundle', 'onboarding-addon'],
            self::Support => ['support-pack'],
        };
    }

    /**
     * The scripted explanation, used whenever no model writes one. It restates
     * the catalogue and names no price.
     */
    public function rationale(): string
    {
        return match ($this) {
            self::Pro => 'One team and one site: the Pro licence covers email support, LTS compatibility updates and early access to new elements. I am adding the Pro licence.',
            self::Agency => 'You run many projects, so the Agency Bundle fits: unlimited projects and an answer within 4 business hours. A guided onboarding gets your team started. I am adding both.',
            self::Support => 'You want faster answers without changing your licence. The Priority Support Pack gives you direct contact with the maintainers. I am adding it.',
        };
    }

    /**
     * A wish in free text, as a generic AG-UI client would send it.
     */
    public static function fromText(string $text): self
    {
        $text = mb_strtolower($text);
        return match (true) {
            str_contains($text, 'agency') || str_contains($text, 'onboarding') => self::Agency,
            str_contains($text, 'support') => self::Support,
            default => self::Pro,
        };
    }
}
