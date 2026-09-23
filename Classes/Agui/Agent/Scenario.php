<?php

declare(strict_types=1);

namespace Webconsulting\AgentNexus\Agui\Agent;

/**
 * One scripted task of the demo agents: what the agent reasons, answers and
 * proposes, and what it says once a person has decided.
 *
 * The proposal is always a tool call that waits for approval as an AG-UI
 * interrupt; {@see self::responseSchema()} describes the answer the next run
 * must carry in RunAgentInput.resume.
 */
final readonly class Scenario
{
    /** Contact fields a visitor's approval can collect, as JSON Schema. */
    private const array CONTACT_FIELDS = [
        'name' => ['type' => 'string', 'title' => 'Name', 'minLength' => 1, 'maxLength' => 120],
        'email' => ['type' => 'string', 'title' => 'Email', 'format' => 'email', 'maxLength' => 254],
        'preferredTime' => ['type' => 'string', 'title' => 'Preferred time', 'minLength' => 1, 'maxLength' => 120],
    ];

    /**
     * @param array<string, mixed> $toolArgs the proposal the tool call carries
     * @param mixed $state STATE_SNAPSHOT before the answer, or null for none
     * @param list<array<string, mixed>> $stateDelta STATE_DELTA after the snapshot
     * @param array<string, mixed>|null $activity ACTIVITY_SNAPSHOT content, or null for none
     * @param list<string> $contactFields contact fields the approval collects (keys of CONTACT_FIELDS)
     */
    public function __construct(
        public string $id,
        public Audience $audience,
        public string $reasoning,
        public string $answer,
        public string $tool,
        public array $toolArgs,
        public string $approvalPrompt,
        public string $doneText,
        public string $simulatedNote,
        public string $declinedText,
        public mixed $state = null,
        public array $stateDelta = [],
        public ?array $activity = null,
        public array $contactFields = [],
        public bool $editableArguments = false,
        public string $defaultMessage = '',
    ) {}

    /**
     * JSON Schema of the resume payload: `approved`, plus the contact fields
     * an approval needs, plus `editedArgs` — a full replacement of the
     * arguments — where the proposal may be edited before approval.
     *
     * @return array<string, mixed>
     */
    public function responseSchema(): array
    {
        $properties = ['approved' => ['type' => 'boolean', 'title' => 'Approve']];
        foreach ($this->contactFields as $field) {
            $properties[$field] = self::CONTACT_FIELDS[$field] ?? ['type' => 'string', 'title' => $field];
        }
        if ($this->editableArguments) {
            $properties['editedArgs'] = [
                'type' => 'object',
                'title' => 'Edited arguments',
                'description' => 'Full replacement of the tool arguments. Not merged.',
            ];
        }
        $schema = ['type' => 'object', 'properties' => $properties, 'required' => ['approved']];
        if ($this->contactFields !== []) {
            $schema['if'] = ['properties' => ['approved' => ['const' => true]], 'required' => ['approved']];
            $schema['then'] = ['required' => $this->contactFields];
        }
        return $schema;
    }

    /** Longest value a contact field accepts. */
    public function maxLength(string $field): int
    {
        return self::CONTACT_FIELDS[$field]['maxLength'] ?? 120;
    }
}
