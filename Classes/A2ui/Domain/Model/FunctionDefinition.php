<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\A2ui\Domain\Model;

/**
 * One function of the official basic catalogue: what it returns and the named
 * arguments it takes. Renderers run these functions; an agent only names them.
 */
final readonly class FunctionDefinition
{
    /** @var array<string, PropertyDefinition> */
    public array $arguments;

    /**
     * @param list<PropertyDefinition> $arguments
     * @param list<string> $oneOfRequired at least one of these arguments must be present
     */
    public function __construct(
        public string $name,
        public string $returnType,
        array $arguments,
        public array $oneOfRequired = [],
    ) {
        $indexed = [];
        foreach ($arguments as $argument) {
            $indexed[$argument->name] = $argument;
        }
        $this->arguments = $indexed;
    }

    /** Whether the function decides if a value is valid (a check). */
    public function isValidation(): bool
    {
        return $this->returnType === 'boolean' || $this->returnType === 'validationResult';
    }

    /**
     * @return array{name: string, returnType: string, arguments: list<array{name: string, type: string, required: bool, values?: list<string>, default?: string|int|float|bool, minimum?: int}>, oneOfRequired?: list<string>}
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'returnType' => $this->returnType,
            'arguments' => array_values(array_map(
                static fn(PropertyDefinition $argument): array => $argument->toArray(),
                $this->arguments,
            )),
        ];
        if ($this->oneOfRequired !== []) {
            $data['oneOfRequired'] = $this->oneOfRequired;
        }
        return $data;
    }
}
