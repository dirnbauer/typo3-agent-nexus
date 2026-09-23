<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Service;

use Webconsulting\AgentNexus\Shared\Store\ProtocolObject;

/**
 * A resume that answers an open interrupt: the interrupted run, the interrupt,
 * the answer, and the proposal it concerns as the interrupted run recorded it.
 */
final readonly class Resumption
{
    /**
     * @param array<string, mixed> $interrupt the Interrupt as RUN_FINISHED carried it
     * @param array{interruptId: string, status: string, payload?: mixed, metadata?: mixed} $entry
     * @param array<string, mixed> $proposal the arguments of the tool call the interrupt concerns
     */
    public function __construct(
        public ProtocolObject $run,
        public array $interrupt,
        public array $entry,
        public string $preset,
        public string $toolCallId,
        public array $proposal,
    ) {}
}
