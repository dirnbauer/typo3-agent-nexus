<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * What to generate and for whom.
 *
 * `public` is true for anything that reached the public API (a widget, another
 * agent): those calls pass the shared frontend LLM guard and its token
 * ceiling. The backend playground and the CLI are editors, not visitors.
 */
final readonly class GenerationRequest
{
    /**
     * @param string $language        the language code the labels should use, e.g. "en" or "de"
     * @param string $businessContext what the site is about, from the widget's FlexForm (never from the wire)
     * @param bool $modelAllowed      false when this client's model budget is used up
     */
    public function __construct(
        public string $intent,
        public A2uiVersion $version = A2uiVersion::DEFAULT,
        public bool $public = false,
        public string $language = 'en',
        public string $businessContext = '',
        public bool $modelAllowed = true,
        public int $beUser = 0,
        public bool $offline = false,
    ) {}
}
