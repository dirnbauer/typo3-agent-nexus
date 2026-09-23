<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Agent;

/**
 * The agent will not do what the input asks — a resume that answers no open
 * approval, a new run on a thread that still waits for one, a price that
 * changed after the visitor approved it. The run ends with RUN_ERROR and
 * nothing is bought.
 */
final class RunRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message, 1758700801);
    }
}
