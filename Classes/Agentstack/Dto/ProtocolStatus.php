<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agentstack\Dto;

/**
 * What the hub knows about one protocol right now.
 *
 * Deliberately a value object rather than an array: the overview template reads
 * these fields, the functional tests assert on them, and a typed shape makes it
 * impossible for a renamed key to silently blank a card.
 */
final readonly class ProtocolStatus
{
    public const HEALTH_OK = 'ok';
    public const HEALTH_WARN = 'warn';
    public const HEALTH_DANGER = 'danger';

    /**
     * The four derived values are properties, not methods, because Fluid cannot
     * call a method: `{status.healthLabel}` resolves `getHealthLabel()`,
     * `isHealthLabel()`, `hasHealthLabel()` or a public property of that name,
     * and nothing else. A `healthLabel()` method therefore rendered as an empty
     * string — silently, in both the backend hub's health chips and the
     * frontend hub's cards. Computing them once in the constructor keeps the
     * single source of truth and makes them readable from a template.
     */
    public string $health;
    public string $healthIcon;
    public string $healthLabel;
    public string $modeLabel;
    public bool $hasActivity;

    /**
     * @param string $key                 Protocol key (a2ui, agui, a2a, ucp, ap2)
     * @param string $label               Short name shown on the card (A2UI)
     * @param string $name                Spelled-out name (Agent-to-UI)
     * @param string $tagline             One line: what this protocol is for
     * @param string $icon                Icon identifier for the card
     * @param bool   $endpointsRegistered Every eID endpoint this protocol needs is registered
     * @param int    $endpointCount       How many endpoints it expects
     * @param bool   $llmEnabled          A real model may be used (otherwise the deterministic demo runs)
     * @param string $llmReason           Why not, when $llmEnabled is false
     * @param bool   $storageReady        A storage folder is configured and exists
     * @param int|null $lastRun           Timestamp of the most recent logged activity
     * @param int    $runsLast24h         Logged activity in the last 24 hours
     * @param string $moduleIdentifier    Backend module to open from the card
     * @param string $playgroundUri       Resolved backend URI of that module ('' when unroutable)
     * @param string|null $frontendUrl    Seeded demo page, when one is reachable
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $name,
        public string $tagline,
        public string $icon,
        public bool $endpointsRegistered,
        public int $endpointCount,
        public bool $llmEnabled,
        public string $llmReason,
        public bool $storageReady,
        public ?int $lastRun,
        public int $runsLast24h,
        public string $moduleIdentifier,
        public string $playgroundUri,
        public ?string $frontendUrl,
    ) {
        // A missing endpoint is the only thing that actually breaks the
        // protocol; everything else is a degraded but working demo.
        $this->health = match (true) {
            !$endpointsRegistered => self::HEALTH_DANGER,
            !$storageReady => self::HEALTH_WARN,
            default => self::HEALTH_OK,
        };
        $this->healthIcon = 'agentnexus-status-' . $this->health;
        $this->healthLabel = match ($this->health) {
            self::HEALTH_DANGER => 'Endpoints missing',
            self::HEALTH_WARN => 'No storage folder',
            default => 'Ready',
        };
        // The demos run without a model; say which mode a visitor would get.
        $this->modeLabel = $llmEnabled ? 'Model-backed' : 'Deterministic demo';
        $this->hasActivity = $lastRun !== null;
    }
}
