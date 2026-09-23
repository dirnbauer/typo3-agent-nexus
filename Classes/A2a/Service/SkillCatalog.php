<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2a\Service;

use TYPO3\CMS\Core\SingletonInterface;

/**
 * The skills the site's A2A agent advertises — the single source of truth behind
 * the Agent Card, the console presets, the concierge's skill chips, the Agent
 * Card screen and the deterministic agent's scripts. Each skill optionally has
 * an `inputPrompt`: when present the task pauses in TASK_STATE_INPUT_REQUIRED
 * and asks the calling agent (or a human) for more detail before it finishes —
 * the cooperative equivalent of a human-in-the-loop gate.
 *
 * @phpstan-type Skill array{id: string, name: string, description: string, tags: list<string>, examples: list<string>, workingText: string, inputPrompt: string|null, resumeText?: string, artifactName: string, artifactText: string, completedText: string}
 */
final class SkillCatalog implements SingletonInterface
{
    /**
     * @return array<string, Skill>
     */
    public function all(): array
    {
        return [
            'summarize_page' => [
                'id' => 'summarize_page',
                'name' => 'Summarise a page',
                'description' => 'Writes a short, accurate summary of a page that another agent can act on.',
                'tags' => ['content', 'summarization'],
                'examples' => ['Summarise our pricing page', 'Summarise the getting-started guide'],
                'workingText' => 'Reading the page and picking out the key points…',
                'inputPrompt' => null,
                'artifactName' => 'summary.md',
                'artifactText' => "## Plans and pricing: summary\n\nThere are three plans. Community is free and includes all elements. Pro costs €49 per month and adds email support and early access to new elements. Agency costs €149 per month and adds unlimited projects and a quarterly editor onboarding. Paying yearly saves two months. Everything is licensed under GPL-2.0, so the plans differ in support, not in features.",
                'completedText' => 'Summary ready. It comes back as an artifact.',
            ],
            'draft_outreach' => [
                'id' => 'draft_outreach',
                'name' => 'Draft an outreach email',
                'description' => 'Writes a short outreach email in the brand\'s tone. Asks who the audience is first.',
                'tags' => ['content', 'email', 'marketing'],
                'examples' => ['Draft an outreach email about our new plans', 'Write an email to lapsed customers'],
                'workingText' => 'Choosing the angle and tone for the email…',
                'inputPrompt' => 'Who is the audience? For example: free users, agencies or lapsed customers.',
                'resumeText' => 'Thanks. Writing for that audience now…',
                'artifactName' => 'outreach-email.md',
                'artifactText' => "Subject: Pay yearly and get two months free\n\nHi there,\n\nYou already build with our free Community plan. If you move to Pro or Agency and pay yearly, you get two months free. Both plans add support and early access to new elements.\n\nThe open-source core stays the same. You get faster answers from the people who build it.\n\nReply to this email and we'll suggest the right plan for your team.\n\n— The team",
                'completedText' => 'Draft ready. It comes back as an artifact for you to review.',
            ],
            'plan_onboarding' => [
                'id' => 'plan_onboarding',
                'name' => 'Plan onboarding',
                'description' => 'Writes a short, step-by-step onboarding plan that another agent or a person can follow.',
                'tags' => ['planning', 'operations'],
                'examples' => ['Plan a 3-step onboarding for a new agency customer', 'Outline the first week of onboarding'],
                'workingText' => 'Putting the steps in order and naming an owner for each…',
                'inputPrompt' => null,
                'artifactName' => 'onboarding-plan.md',
                'artifactText' => "## Onboarding in 3 steps\n\n1. **Day 0: kickoff.** Set up the workspace, agree on goals and share the quick-start guide. Owner: customer success.\n2. **Day 3: first page.** Build and publish one real page together, using the element library. Owner: solutions team.\n3. **Day 7: review.** Look at usage, agree on the rollout plan and book the quarterly check-in. Owner: customer success.",
                'completedText' => 'Onboarding plan ready. It comes back as an artifact.',
            ],
        ];
    }

    /**
     * The skill with this id; an unknown id falls back to summarising rather
     * than failing.
     *
     * @return Skill
     */
    public function get(string $id): array
    {
        $all = $this->all();
        return $all[$id] ?? $all['summarize_page'];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->all());
    }
}
