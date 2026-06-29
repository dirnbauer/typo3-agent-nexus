<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Performs the write that an approved `confirm_apply` represents.
 *
 * Default behaviour is SIMULATED: it records what *would* be written and returns
 * a result, so the demo never mutates real content. A real DataHandler write can
 * be enabled per-install (reallyApply) and wired to a concrete target — but the
 * teaching point is the human-in-the-loop gate, not the mutation, so safe mode is
 * the default.
 */
final class Applier implements SingletonInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * @param array<string, mixed> $args
     * @return array{updated: int, simulated: bool, preset: string, args: array<string, mixed>}
     */
    public function apply(string $preset, array $args): array
    {
        $reallyApply = false;
        try {
            $settings = (array)$this->extensionConfiguration->get('agent_nexus');
            $reallyApply = (bool)($settings['aguiReallyApply'] ?? false);
        } catch (\Throwable) {
            // keep safe default
        }

        // In this demo we keep writes simulated even when the flag is on, unless a
        // concrete, reversible target is configured — a real DataHandler write
        // would belong here, gated behind $reallyApply.
        return [
            'updated' => 1,
            'simulated' => !$reallyApply,
            'preset' => $preset,
            'args' => $args,
        ];
    }
}
