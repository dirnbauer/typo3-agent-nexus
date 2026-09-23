<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Sandbox;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Webconsulting\AgentNexus\Shared\Traffic\Channel;

/**
 * Where the objects of one run come from: the channel, the page they are
 * stored on and the backend user, if any.
 */
#[Exclude]
final readonly class RecordingContext
{
    public function __construct(
        public Channel $source,
        public int $pid = 0,
        public int $beUser = 0,
    ) {}
}
