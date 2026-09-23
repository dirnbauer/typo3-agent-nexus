<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ucp\Http;

/**
 * The five operations of the checkout capability's REST binding, named as the
 * OpenAPI description names them (services/shopping/rest.openapi.json).
 */
enum Operation: string
{
    case Create = 'create_checkout';
    case Get = 'get_checkout';
    case Update = 'update_checkout';
    case Complete = 'complete_checkout';
    case Cancel = 'cancel_checkout';

    public function routeId(): string
    {
        return match ($this) {
            self::Create => 'ucp.checkout.create',
            self::Get => 'ucp.checkout.get',
            self::Update => 'ucp.checkout.update',
            self::Complete => 'ucp.checkout.complete',
            self::Cancel => 'ucp.checkout.cancel',
        };
    }

    public function method(): string
    {
        return match ($this) {
            self::Get => 'GET',
            self::Update => 'PUT',
            default => 'POST',
        };
    }

    /** Every operation but a read changes state and needs an Idempotency-Key. */
    public function isMutation(): bool
    {
        return $this !== self::Get;
    }
}
