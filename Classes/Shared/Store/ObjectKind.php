<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Shared\Store;

use Webconsulting\AgentNexus\Shared\Protocol;

/**
 * The kinds of protocol object Agent Nexus keeps, one per protocol.
 */
enum ObjectKind: string
{
    /** An A2UI surface: the messages that built it and the data model it returned. */
    case Surface = 'surface';
    /** An AG-UI run: its input, outcome and the interrupts it raised. */
    case Run = 'run';
    /** An A2A task: status history, messages and artifacts. */
    case Task = 'task';
    /** A UCP checkout session, as the merchant returned it last. */
    case Checkout = 'checkout';
    /** An AP2 mandate or receipt: the token, its decoded claims and the checks it passed. */
    case Mandate = 'mandate';

    public function protocol(): Protocol
    {
        return match ($this) {
            self::Surface => Protocol::A2ui,
            self::Run => Protocol::Agui,
            self::Task => Protocol::A2a,
            self::Checkout => Protocol::Ucp,
            self::Mandate => Protocol::Ap2,
        };
    }

    /** The inspector module that lists this kind. */
    public function inspectorModule(): string
    {
        return 'agentnexus_inspector_' . match ($this) {
            self::Surface => 'surfaces',
            self::Run => 'runs',
            self::Task => 'tasks',
            self::Checkout => 'checkouts',
            self::Mandate => 'mandates',
        };
    }

    public static function forProtocol(Protocol $protocol): self
    {
        return match ($protocol) {
            Protocol::A2ui => self::Surface,
            Protocol::Agui => self::Run,
            Protocol::A2a => self::Task,
            Protocol::Ucp => self::Checkout,
            Protocol::Ap2 => self::Mandate,
        };
    }
}
