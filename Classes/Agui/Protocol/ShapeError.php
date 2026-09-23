<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Protocol;

/**
 * A value does not have the shape AG-UI 1.0 gives it; `$pointer` (RFC 6901)
 * says where.
 */
final class ShapeError extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $pointer,
        string $message,
    ) {
        parent::__construct(($pointer === '' ? '(root)' : $pointer) . ': ' . $message, 1758700110);
    }
}
