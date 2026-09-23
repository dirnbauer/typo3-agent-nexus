<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Agent;

use Webconsulting\AgentNexus\Agui\Protocol\Json;

/**
 * A person's answer to an interrupt, read from its resume entry.
 *
 * `status: "cancelled"` abandons the proposal; `status: "resolved"` answers it
 * with a payload shaped by {@see Scenario::responseSchema()}. A denial is an
 * answer too: `{"approved": false}`. An approval must carry the contact
 * fields the task needs; where the proposal may be edited, `editedArgs`
 * replaces its arguments as a whole.
 */
final readonly class Approval
{
    /**
     * @param array<string, mixed> $arguments what the approved action runs with
     * @param array<string, string> $contact the contact fields the person entered
     */
    private function __construct(
        public bool $approved,
        public string $decision,
        public array $arguments,
        public array $contact,
    ) {}

    /**
     * @param array{interruptId: string, status: string, payload?: mixed, metadata?: mixed} $entry
     * @param array<string, mixed> $proposal the arguments of the tool call the interrupt concerns
     * @throws \InvalidArgumentException with a message for the person who answered
     */
    public static function read(array $entry, Scenario $scenario, array $proposal): self
    {
        if ($entry['status'] === 'cancelled') {
            return new self(false, 'cancelled', $proposal, []);
        }
        $payload = $entry['payload'] ?? null;
        if (!Json::isObject($payload)) {
            throw new \InvalidArgumentException('Send your answer as an object with "approved" set to true or false.');
        }
        $answer = Json::members($payload);
        if (!is_bool($answer['approved'] ?? null)) {
            throw new \InvalidArgumentException('Say whether you approve: set "approved" to true or false.');
        }
        if ($answer['approved'] === false) {
            return new self(false, 'declined', $proposal, []);
        }

        $contact = [];
        foreach ($scenario->contactFields as $field) {
            $value = is_string($answer[$field] ?? null) ? trim($answer[$field]) : '';
            $valid = $value !== '' && mb_strlen($value) <= $scenario->maxLength($field)
                && ($field !== 'email' || filter_var($value, FILTER_VALIDATE_EMAIL) !== false);
            if (!$valid) {
                throw new \InvalidArgumentException(match ($field) {
                    'email' => 'Enter a valid email address to approve.',
                    'name' => 'Enter your name to approve.',
                    default => 'Enter a preferred time to approve.',
                });
            }
            $contact[$field] = $value;
        }

        $arguments = $proposal;
        if (array_key_exists('editedArgs', $answer)) {
            $arguments = self::edited($answer['editedArgs'], $scenario, $proposal);
        }
        return new self(true, 'approved', $arguments, $contact);
    }

    /**
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    private static function edited(mixed $edited, Scenario $scenario, array $proposal): array
    {
        if (!$scenario->editableArguments) {
            throw new \InvalidArgumentException('This proposal cannot be edited. Approve or decline it as it is.');
        }
        if (!Json::isObject($edited)) {
            throw new \InvalidArgumentException('editedArgs must be an object that replaces the proposed arguments.');
        }
        $arguments = Json::members(Json::toPhp($edited));
        $unknown = array_diff(array_keys($arguments), array_keys($proposal));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('editedArgs may only change the proposed arguments, not add %s.', implode(', ', $unknown)));
        }
        if (strlen(Json::encode($arguments)) > 16384) {
            throw new \InvalidArgumentException('The edited arguments are too long.');
        }
        return $arguments;
    }
}
