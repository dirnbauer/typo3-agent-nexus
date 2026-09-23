<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use Webconsulting\AgentNexus\Agui\Agent\Approval;
use Webconsulting\AgentNexus\Agui\Agent\Audience;
use Webconsulting\AgentNexus\Agui\Agent\Scenario;
use Webconsulting\AgentNexus\Agui\Protocol\Json;
use Webconsulting\AgentNexus\Shared\Configuration\ExtensionSettings;

/**
 * Carries out what a person approved, and only that.
 *
 * The outcome is the run's result: RUN_FINISHED carries it and the run record
 * keeps it, which is where a visitor's request (the lead) is stored now that
 * there is no lead table. Writes stay simulated unless the `aguiReallyApply`
 * extension setting is on — the teaching point is the approval gate, not the
 * write — so the result says `simulated: true` by default.
 */
final readonly class Applier
{
    public function __construct(
        private ExtensionSettings $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function apply(Scenario $scenario, Approval $approval): array
    {
        $result = [
            'status' => $scenario->audience === Audience::Site ? 'sent' : 'applied',
            'preset' => $scenario->id,
            'updated' => 1,
            'simulated' => !$this->settings->bool('aguiReallyApply', false),
            'arguments' => Json::object($approval->arguments),
        ];
        if ($approval->contact !== []) {
            $result['lead'] = $approval->contact;
        }
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function decline(Scenario $scenario, Approval $approval): array
    {
        return [
            'status' => 'declined',
            'preset' => $scenario->id,
            'updated' => 0,
            'decision' => $approval->decision,
        ];
    }
}
