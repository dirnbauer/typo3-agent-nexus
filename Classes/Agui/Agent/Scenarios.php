<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Agent;

/**
 * The tasks the two demo agents know.
 *
 * The site assistant answers visitors (the public endpoint and the assistant
 * widget): `plan` recommends a plan, `support` books a consultation. The
 * editor agent works for backend users in the run console: `seo`, `translate`
 * and `news`. Every task ends by proposing one change and waiting for a
 * person to approve it; nothing is written before that.
 */
final class Scenarios
{
    /** @var array<string, Scenario>|null */
    private ?array $scenarios = null;

    public function get(string $id): ?Scenario
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * @return list<Scenario>
     */
    public function forAudience(Audience $audience): array
    {
        return array_values(array_filter($this->all(), static fn(Scenario $scenario): bool => $scenario->audience === $audience));
    }

    /**
     * The task a run asks for: the requested one when it belongs to the
     * audience, otherwise one guessed from the question, otherwise the
     * audience's first.
     */
    public function resolve(string $requested, Audience $audience, string $question = ''): Scenario
    {
        $scenario = $this->get($requested);
        if ($scenario !== null && $scenario->audience === $audience) {
            return $scenario;
        }
        if ($audience === Audience::Site && preg_match('/\b(call|consult\w*|book\w*|meeting|appointment|talk)\b/i', $question) === 1) {
            return $this->all()['support'];
        }
        return $this->forAudience($audience)[0];
    }

    /**
     * @return array<string, Scenario>
     */
    private function all(): array
    {
        if ($this->scenarios !== null) {
            return $this->scenarios;
        }
        $scenarios = [];
        foreach ([...$this->site(), ...$this->editor()] as $scenario) {
            $scenarios[$scenario->id] = $scenario;
        }
        return $this->scenarios = $scenarios;
    }

    /**
     * @return list<Scenario>
     */
    private function site(): array
    {
        return [
            new Scenario(
                id: 'plan',
                audience: Audience::Site,
                reasoning: 'They need a plan for five people under €50 a month. The Team plan at €39 fits. I will show a comparison and ask before I send anything.',
                answer: 'For a team of five under €50, the Team plan fits best at €39 a month. Here is a comparison.',
                tool: 'confirm_booking',
                toolArgs: ['plan' => 'Team', 'price' => 39, 'currency' => 'EUR', 'seats' => 5],
                approvalPrompt: 'Confirm and send your request for the Team plan to our team.',
                doneText: 'Thanks. Your request for the Team plan was sent to our team.',
                simulatedNote: 'This is a demo, so nothing left this site.',
                declinedText: 'No problem. I sent nothing.',
                state: ['selection' => null],
                stateDelta: [['op' => 'replace', 'path' => '/selection', 'value' => 'Team']],
                activity: [
                    'recommended' => 'Team',
                    'currency' => 'EUR',
                    'period' => 'month',
                    'plans' => [
                        ['name' => 'Starter', 'price' => 0, 'seats' => 2],
                        ['name' => 'Team', 'price' => 39, 'seats' => 5],
                        ['name' => 'Business', 'price' => 79, 'seats' => 15],
                    ],
                ],
                contactFields: ['name', 'email'],
                defaultMessage: 'I need a plan for a team of five, under €50 a month.',
            ),
            new Scenario(
                id: 'support',
                audience: Audience::Site,
                reasoning: 'They want to book a consultation. I will collect the essentials and ask before I send the request.',
                answer: 'I can set up a consultation. Tell me a good time, and I will confirm the details before I send anything.',
                tool: 'confirm_booking',
                toolArgs: ['action' => 'book_consultation', 'length' => '30 minutes'],
                approvalPrompt: 'Confirm and send your consultation request to our team.',
                doneText: 'Done. Your consultation request was sent. We will contact you soon.',
                simulatedNote: 'This is a demo, so nothing left this site.',
                declinedText: 'No problem. I sent nothing.',
                contactFields: ['name', 'email', 'preferredTime'],
                defaultMessage: 'Book an introductory call.',
            ),
        ];
    }

    /**
     * @return list<Scenario>
     */
    private function editor(): array
    {
        $description = 'Compare flexible team plans with clear pricing. Start free, grow at your own pace and cancel at any time.';
        $lead = 'Today we launched our redesigned team workspace. It brings real-time collaboration and clear pricing to growing companies.';

        return [
            new Scenario(
                id: 'seo',
                audience: Audience::Editor,
                reasoning: 'The page is about flexible team plans and clear pricing. The description should lead with that, end with a call to action and stay under 160 characters.',
                answer: $description,
                tool: 'confirm_apply',
                toolArgs: ['table' => 'pages', 'uid' => 42, 'page' => 'Pricing', 'field' => 'description', 'value' => $description],
                approvalPrompt: 'Approve writing this meta description to page 42 (Pricing).',
                doneText: 'Done. The meta description was written to the page.',
                simulatedNote: 'This is a demo, so the change was only simulated.',
                declinedText: 'No problem. I discarded the change and wrote nothing.',
                editableArguments: true,
                defaultMessage: 'Write a meta description for the Pricing page.',
            ),
            new Scenario(
                id: 'translate',
                audience: Audience::Editor,
                reasoning: 'Three pages need English titles. I will propose translations and keep them editable, because brand terms sometimes stay in German.',
                answer: 'I translated the three page titles. Check the shared state, and edit any title before you approve.',
                tool: 'confirm_apply',
                toolArgs: [
                    'action' => 'translate_titles',
                    'targetLanguage' => 'en',
                    'titles' => [
                        ['uid' => 7, 'title' => 'About us'],
                        ['uid' => 8, 'title' => 'Services'],
                        ['uid' => 9, 'title' => 'Contact'],
                    ],
                ],
                approvalPrompt: 'Approve saving three English page titles.',
                doneText: 'Done. Three page titles were saved in English.',
                simulatedNote: 'This is a demo, so the change was only simulated.',
                declinedText: 'No problem. I discarded the translations and wrote nothing.',
                state: [
                    'targetLanguage' => 'en',
                    'pages' => [
                        ['uid' => 7, 'title' => 'Über uns'],
                        ['uid' => 8, 'title' => 'Leistungen'],
                        ['uid' => 9, 'title' => 'Kontakt'],
                    ],
                ],
                stateDelta: [
                    ['op' => 'replace', 'path' => '/pages/0/title', 'value' => 'About us'],
                    ['op' => 'replace', 'path' => '/pages/1/title', 'value' => 'Services'],
                    ['op' => 'replace', 'path' => '/pages/2/title', 'value' => 'Contact'],
                ],
                editableArguments: true,
                defaultMessage: 'Translate the titles of the About, Services and Contact pages into English.',
            ),
            new Scenario(
                id: 'news',
                audience: Audience::Editor,
                reasoning: 'The brief is about a product launch. I will draft a short news lead with the date and a place for a quote, in the house style.',
                answer: $lead,
                tool: 'confirm_apply',
                toolArgs: ['action' => 'create_news', 'table' => 'tx_news_domain_model_news', 'title' => 'New team workspace launches', 'teaser' => $lead],
                approvalPrompt: 'Approve creating this news draft.',
                doneText: 'Done. A new news draft is ready for review.',
                simulatedNote: 'This is a demo, so the change was only simulated.',
                declinedText: 'No problem. I discarded the draft and wrote nothing.',
                editableArguments: true,
                defaultMessage: 'Draft a short news item about the launch of our new team workspace.',
            ),
        ];
    }
}
