<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Agent;

/**
 * Who an agent works for: visitors of the site (the public endpoint and the
 * assistant widget) or editors in the backend (the run console). An interrupt
 * raised for one audience can only be answered through that audience's
 * endpoint.
 */
enum Audience: string
{
    case Site = 'site';
    case Editor = 'editor';
}
