<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * The skills the site's A2A agent advertises — the single source of truth behind
 * the Agent Card, the backend presets, the Skill Inspector and the deterministic
 * agent's scripts. Each skill optionally has an `inputPrompt`: when present the
 * task pauses in the A2A `input-required` state and asks the calling agent (or a
 * human) for more detail before it finishes — the cooperative equivalent of a
 * human-in-the-loop gate.
 */
final class SkillCatalog implements SingletonInterface
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            'summarize_page' => [
                'id' => 'summarize_page',
                'name' => 'Summarise a page',
                'description' => 'Condenses a page into a short, faithful summary another agent can act on.',
                'tags' => ['content', 'summarization'],
                'examples' => ['Summarise our pricing page', 'Give me the gist of the onboarding guide'],
                'workingText' => 'Reading the page and extracting the key points…',
                'inputPrompt' => null,
                'artifactName' => 'summary.md',
                'artifactText' => "## Plans & Pricing — summary\n\nThree tiers: Community (free, all elements), Pro (€49/mo, priority support + early drops) and Agency (€149/mo, unlimited projects + a quarterly review). Yearly billing gives two months free. Everything ships under GPL-2.0; the differentiator is support depth and creator access, not feature gating.",
                'completedText' => 'Summary ready — returned as an artifact.',
            ],
            'draft_outreach' => [
                'id' => 'draft_outreach',
                'name' => 'Draft an outreach email',
                'description' => 'Writes a short, on-brand outreach email. Asks who the audience is before drafting.',
                'tags' => ['content', 'email', 'marketing'],
                'examples' => ['Draft an outreach email about our new plans', 'Write a re-engagement email'],
                'workingText' => 'Planning the angle and tone for the email…',
                'inputPrompt' => 'Who is the audience? (e.g. existing free users, agencies, lapsed customers)',
                'resumeText' => 'Thanks — writing for that audience now…',
                'artifactName' => 'outreach-email.md',
                'artifactText' => "Subject: Two months free when you grow with us\n\nHi there,\n\nYou already build with our free tier — here's a nudge worth opening: on Pro and Agency, paying yearly gives you two months free, priority support and early access to every new element drop.\n\nNothing changes about the open-source core. You just get more shipping speed and a direct line to the people who build it.\n\nWant the rundown? Reply and we'll tailor a plan to your team.\n\n— The team",
                'completedText' => 'Draft ready — returned as an artifact for your review.',
            ],
            'plan_onboarding' => [
                'id' => 'plan_onboarding',
                'name' => 'Plan an onboarding',
                'description' => 'Produces a concise, sequenced onboarding plan another agent or a human can execute.',
                'tags' => ['planning', 'operations'],
                'examples' => ['Plan a 3-step onboarding for a new agency customer', 'Outline first-week onboarding'],
                'workingText' => 'Sequencing the steps and owners…',
                'inputPrompt' => null,
                'artifactName' => 'onboarding-plan.md',
                'artifactText' => "## 3-step onboarding\n\n1. **Day 0 — Kickoff.** Provision the workspace, confirm goals, share the quick-start. Owner: success.\n2. **Day 3 — First win.** Pair on shipping one real page with the element library. Owner: solutions.\n3. **Day 7 — Review & scale.** Recap usage, set the rollout plan, schedule the quarterly check-in. Owner: success.",
                'completedText' => 'Onboarding plan ready — returned as an artifact.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        $all = $this->all();
        return $all[$id] ?? $all['summarize_page'];
    }
}
