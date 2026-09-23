<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Ap2\Mandate;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One line of a verification: what was checked, whether it held, and the
 * evidence ("€447.00 ≤ €500.00").
 */
#[Exclude]
final readonly class Check
{
    public function __construct(
        public CheckType $type,
        public bool $pass,
        public string $detail = '',
    ) {}

    public static function pass(CheckType $type, string $detail = ''): self
    {
        return new self($type, true, $detail);
    }

    public static function fail(CheckType $type, string $detail): self
    {
        return new self($type, false, $detail);
    }

    public function label(): string
    {
        return $this->type->label();
    }

    /**
     * @return array{id: string, label: string, pass: bool, detail: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->type->value,
            'label' => $this->label(),
            'pass' => $this->pass,
            'detail' => $this->detail,
        ];
    }
}
