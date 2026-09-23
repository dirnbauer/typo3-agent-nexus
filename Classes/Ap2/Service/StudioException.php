<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Service;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A studio request that cannot be carried out as asked. The key names the
 * reason for the backend's label file (`error.<key>`); the message is the
 * English fallback.
 */
#[Exclude]
final class StudioException extends \RuntimeException
{
    public function __construct(
        public readonly string $key,
        string $message,
    ) {
        parent::__construct($message, 1758700601);
    }
}
