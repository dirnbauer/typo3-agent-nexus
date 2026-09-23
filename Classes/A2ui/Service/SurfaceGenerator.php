<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Service;

use Webconsulting\AgentNexus\A2ui\Domain\Model\Surface;
use Webconsulting\AgentNexus\A2ui\Domain\Model\SurfaceBuilder;

/**
 * The built-in generator: a handful of surfaces written out by hand, picked by
 * keywords in the request.
 *
 * It is what runs when no model is configured, when the model budget is used
 * up or when a model answer cannot be repaired — so every demo works without
 * an API key — and it supplies the example the model prompt shows. The
 * surfaces use the official basic catalogue in its v0.9.1 shape; the
 * sanitiser converts them for v1.0.
 *
 * Every form has the same frame: a Card around a Column with a heading, the
 * fields and a row of two buttons — the primary one sends the form, the
 * borderless "Start over" asks the agent to discard the surface.
 */
final class SurfaceGenerator
{
    public const string DISCARD_EVENT = 'discard';

    /**
     * The examples, keyed by name: the request each one answers.
     */
    public const array EXAMPLES = [
        'contact' => 'A contact form with name, email and a message',
        'quote' => 'Request a quote for a project',
        'callback' => 'Book an introductory call',
        'application' => 'Apply for an open role',
        'event' => 'Collect event registration details',
        'newsletter' => 'A newsletter sign-up form',
        'page' => 'Create a new landing page',
        'content' => 'Edit a content element',
        'seo' => 'Edit the SEO metadata of this page',
        'schedule' => 'Schedule the publication of a page',
    ];

    /**
     * Keywords per example, English and German, checked in this order.
     */
    private const array KEYWORDS = [
        'quote' => ['quote', 'angebot', 'offer', 'estimate', 'price', 'pricing', 'budget', 'cost', 'kosten', 'preis'],
        'callback' => ['callback', 'call', 'phone', 'rückruf', 'ruckruf', 'anruf', 'telefon', 'meeting', 'appointment', 'termin'],
        'application' => ['job', 'apply', 'application', 'career', 'role', 'vacancy', 'bewerb', 'stelle'],
        'event' => ['event', 'registration', 'register', 'ticket', 'anmeld', 'veranstaltung', 'workshop', 'webinar'],
        'newsletter' => ['newsletter', 'signup', 'sign-up', 'sign up', 'subscribe', 'abonn'],
        'seo' => ['seo', 'metadata', 'meta description', 'search engine', 'suchmaschine'],
        'schedule' => ['schedule', 'publish', 'publication', 'veröffentlich', 'zeitplan'],
        'content' => ['content element', 'content', 'inhalt', 'text element'],
        'page' => ['page', 'seite', 'landing'],
    ];

    /** Which example answers a request; the contact form when nothing matches. */
    public function match(string $intent): string
    {
        $needle = mb_strtolower($intent);
        foreach (self::KEYWORDS as $example => $keywords) {
            foreach ($keywords as $keyword) {
                if (preg_match('/(?<![\p{L}])' . preg_quote($keyword, '/') . '/u', $needle) === 1) {
                    return $example;
                }
            }
        }
        return 'contact';
    }

    /**
     * The surface for a request. The surface id is the example name; the
     * caller makes it unique.
     */
    public function generate(string $intent): Surface
    {
        return $this->example($this->match($intent), $intent);
    }

    public function example(string $name, string $intent = ''): Surface
    {
        return match ($name) {
            'quote' => $this->quote(),
            'callback' => $this->callback(),
            'application' => $this->application(),
            'event' => $this->event(),
            'newsletter' => $this->newsletter(),
            'page' => $this->page(),
            'content' => $this->content(),
            'seo' => $this->seo(),
            'schedule' => $this->schedule(),
            default => $this->contact($intent),
        };
    }

    private function contact(string $intent): Surface
    {
        return $this->form('contact', 'Get in touch', 'Tell us what you need and we will get back to you.', static function (SurfaceBuilder $b) use ($intent): array {
            return [
                $b->textField('name', 'Your name', checks: [SurfaceBuilder::check('required', 'name', 'Enter your name.')]),
                $b->textField('email', 'Email', checks: self::emailChecks()),
                $b->textField('subject', 'Subject', initial: mb_substr(trim($intent), 0, 120)),
                $b->textField('message', 'How can we help?', 'longText', [SurfaceBuilder::check('required', 'message', 'Tell us how we can help.')]),
                $b->checkBox('consent', 'You may contact me about this request'),
            ];
        }, 'Send message', 'sendMessage');
    }

    private function quote(): Surface
    {
        return $this->form('quote', 'Request a quote', 'A few details help us prepare an accurate quote.', static function (SurfaceBuilder $b): array {
            return [
                $b->textField('name', 'Your name', checks: [SurfaceBuilder::check('required', 'name', 'Enter your name.')]),
                $b->textField('email', 'Email', checks: self::emailChecks()),
                $b->choice('projectType', 'What do you need?', [
                    'advisory' => 'Advisory',
                    'implementation' => 'Implementation',
                    'managed' => 'Managed service',
                    'other' => 'Something else',
                ], ['advisory'], chips: true),
                $b->choice('budget', 'Indicative budget', [
                    'under10k' => 'Under €10k',
                    '10to50k' => '€10k to €50k',
                    'over50k' => 'Over €50k',
                    'unknown' => 'Not sure yet',
                ], ['unknown']),
                $b->textField('details', 'Project details', 'longText'),
            ];
        }, 'Request quote', 'requestQuote');
    }

    private function callback(): Surface
    {
        return $this->form('callback', 'Request a callback', 'Leave your number and pick a time that suits you.', static function (SurfaceBuilder $b): array {
            return [
                $b->textField('name', 'Your name', checks: [SurfaceBuilder::check('required', 'name', 'Enter your name.')]),
                $b->textField('phone', 'Phone number', checks: [
                    SurfaceBuilder::check('required', 'phone', 'Enter your phone number.'),
                    SurfaceBuilder::check('regex', 'phone', 'Enter a phone number, for example +43 1 234 5678.', ['pattern' => '^[+0-9 ()/-]{6,20}$']),
                ]),
                $b->dateTime('when', 'Best time to call', true, true),
                $b->textField('topic', 'What is it about?'),
            ];
        }, 'Request callback', 'requestCallback');
    }

    private function application(): Surface
    {
        return $this->form('application', 'Apply for a position', 'Tell us about yourself. We reply to every application.', static function (SurfaceBuilder $b): array {
            return [
                $b->textField('name', 'Full name', checks: [SurfaceBuilder::check('required', 'name', 'Enter your full name.')]),
                $b->textField('email', 'Email', checks: self::emailChecks()),
                $b->textField('position', 'Position you are applying for'),
                $b->textField('motivation', 'Why you?', 'longText', [SurfaceBuilder::check('required', 'motivation', 'Tell us a little about yourself.')]),
            ];
        }, 'Send application', 'submitApplication');
    }

    private function event(): Surface
    {
        return $this->form('event', 'Event registration', null, static function (SurfaceBuilder $b): array {
            return [
                $b->textField('fullName', 'Full name', checks: [SurfaceBuilder::check('required', 'fullName', 'Enter your full name.')]),
                $b->textField('email', 'Email', checks: self::emailChecks()),
                $b->choice('ticket', 'Ticket', ['standard' => 'Standard', 'vip' => 'VIP', 'student' => 'Student'], ['standard']),
                $b->slider('attendees', 'Number of attendees', 1, 10, 1),
                $b->checkBox('updates', 'Keep me posted about future events', true),
            ];
        }, 'Register', 'registerForEvent');
    }

    private function newsletter(): Surface
    {
        return $this->form('newsletter', 'Newsletter sign-up', 'Product news once a month. Unsubscribe at any time.', static function (SurfaceBuilder $b): array {
            $trigger = $b->button('privacy_open', 'Read the privacy note', SurfaceBuilder::event('openPrivacyNote'), 'borderless');
            $note = $b->column('privacy_note', [
                $b->text('privacy_title', 'Privacy note', 'h3'),
                $b->text('privacy_text', 'We use your email address only to send the newsletter. Every issue has a link to unsubscribe.'),
            ]);
            return [
                $b->textField('email', 'Email', checks: self::emailChecks()),
                $b->choice('topics', 'Topics', [
                    'product' => 'Product news',
                    'events' => 'Events',
                    'stories' => 'Customer stories',
                ], ['product'], multiple: true, chips: true),
                $b->checkBox('consent', 'I agree to the privacy policy'),
                $b->modal('privacy', $trigger, $note),
            ];
        }, 'Subscribe', 'subscribeNewsletter', [SurfaceBuilder::isTrue('consent', 'Agree to the privacy policy to subscribe.')]);
    }

    private function page(): Surface
    {
        return $this->form('page', 'Create a new page', null, static function (SurfaceBuilder $b): array {
            return [
                $b->textField('pageTitle', 'Page title', checks: [SurfaceBuilder::check('required', 'pageTitle', 'Give the page a title.')]),
                $b->choice('pageType', 'Page type', [
                    'standard' => 'Standard',
                    'link' => 'Link to an external URL',
                    'shortcut' => 'Shortcut',
                    'folder' => 'Folder',
                ], ['standard']),
                $b->textField('slug', 'URL segment'),
                $b->checkBox('hideInMenu', 'Hide in menu'),
                $b->dateTime('publishDate', 'Publish on', true, false),
            ];
        }, 'Create page', 'createPage');
    }

    private function content(): Surface
    {
        return $this->form('content', 'Edit a content element', null, static function (SurfaceBuilder $b): array {
            return [
                $b->textField('heading', 'Heading', checks: [SurfaceBuilder::check('required', 'heading', 'Enter a heading.')]),
                $b->choice('type', 'Content type', [
                    'text' => 'Text',
                    'textmedia' => 'Text and media',
                    'image' => 'Image only',
                    'html' => 'HTML',
                ], ['text'], chips: true),
                $b->textField('body', 'Body text', 'longText'),
            ];
        }, 'Save', 'saveContent');
    }

    private function seo(): Surface
    {
        return $this->form('seo', 'SEO metadata', null, static function (SurfaceBuilder $b): array {
            $general = $b->column('seo_general', [
                $b->textField('metaTitle', 'SEO title', checks: [
                    SurfaceBuilder::check('length', 'metaTitle', 'Keep the title under 60 characters.', ['max' => 60]),
                ]),
                $b->textField('metaDescription', 'Meta description', 'longText', [
                    SurfaceBuilder::check('length', 'metaDescription', 'Keep the description under 160 characters.', ['max' => 160]),
                ]),
            ]);
            $search = $b->column('seo_search', [
                $b->textField('focusKeyword', 'Focus keyword'),
                $b->checkBox('noindex', 'Hide this page from search engines (noindex)'),
            ]);
            return [$b->tabs('seo_tabs', ['General' => $general, 'Search engines' => $search])];
        }, 'Update SEO data', 'updateSeo');
    }

    private function schedule(): Surface
    {
        return $this->form('schedule', 'Schedule publication', 'Pick when the page goes live and, if you like, when it comes down again.', static function (SurfaceBuilder $b): array {
            return [
                $b->dateTime('startDate', 'Publish at', true, true, [SurfaceBuilder::check('required', 'startDate', 'Choose when the page goes live.')]),
                $b->dateTime('endDate', 'Unpublish at (optional)', true, true),
            ];
        }, 'Set schedule', 'saveSchedule');
    }

    /**
     * The frame every example shares.
     *
     * @param \Closure(SurfaceBuilder): list<string> $fields adds the fields, returns their ids in order
     * @param list<array<string, mixed>> $submitChecks
     */
    private function form(string $name, string $title, ?string $intro, \Closure $fields, string $submit, string $event, array $submitChecks = []): Surface
    {
        $b = new SurfaceBuilder();
        $children = [$b->text('title', $title, 'h2')];
        if ($intro !== null) {
            $children[] = $b->text('intro', $intro);
        }
        $children = [...$children, ...$fields($b)];
        $send = $b->button('submit', $submit, SurfaceBuilder::event($event, $b->fields()), 'primary', $submitChecks);
        $discard = $b->button('discard', 'Start over', SurfaceBuilder::event(self::DISCARD_EVENT), 'borderless');
        $children[] = $b->row('actions', [$send, $discard], align: 'center');
        $b->card('root', $b->column('form', $children));
        return $b->build($name, $title);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function emailChecks(): array
    {
        return [
            SurfaceBuilder::check('required', 'email', 'Enter your email address.'),
            SurfaceBuilder::check('email', 'email', 'Enter an email address like name@example.com.'),
        ];
    }
}
