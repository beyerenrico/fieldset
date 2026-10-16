<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Steps;

use App\Domain\Forms\Schema\DefinitionTools;
use App\Domain\Mail\CustomerSend\CustomerRecipient;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Workflows\Engine\StepContext;
use App\Domain\Workflows\Support\WorkflowRecipients;

/**
 * Recipients of a `send_email` step: fixed addresses (`to_addresses`), submitted field values
 * (`to_fields`: an element key or a semantic role such as `customer.email`), the submitter
 * (`to_submitter`) and group members (`to_group_ids`). Invalid addresses are skipped; duplicates are
 * removed case-insensitively (first occurrence wins, keeping its name).
 */
final readonly class EmailRecipients
{
    public function __construct(private WorkflowRecipients $users) {}

    /** @return list<array{email: string, name: string|null}> */
    public function resolve(StepContext $context): array
    {
        $list = array_map(static fn (string $email): array => ['email' => $email, 'name' => null], $context->strings('to_addresses'));
        $submission = $context->submission;
        if ($submission instanceof Submission) {
            foreach ($context->strings('to_fields') as $field) {
                $list[] = $this->fromField($submission, $field);
            }
            if ($context->bool('to_submitter')) {
                foreach ($this->users->submitter($submission) as $user) {
                    $list[] = ['email' => $user->email, 'name' => $user->name];
                }
            }
        }
        foreach ($this->users->users(groupIds: $context->ints('to_group_ids')) as $user) {
            $list[] = ['email' => $user->email, 'name' => $user->name];
        }

        return $this->unique($list);
    }

    /** @return array{email: string, name: string|null} */
    private function fromField(Submission $submission, string $field): array
    {
        $definition = $submission->formVersion->definition;
        $key = str_contains($field, '.') ? DefinitionTools::keyForRole($definition, $field) : $field;
        $value = $key === null ? null : ($submission->data->{$key} ?? null);
        $name = $field === CustomerRecipient::EMAIL_ROLE ? CustomerRecipient::defaults($submission)['name'] : null;

        return ['email' => is_string($value) ? trim($value) : '', 'name' => $name];
    }

    /**
     * @param  list<array{email: string, name: string|null}>  $list
     * @return list<array{email: string, name: string|null}>
     */
    private function unique(array $list): array
    {
        $unique = [];
        foreach ($list as $recipient) {
            $key = strtolower($recipient['email']);
            if (! isset($unique[$key]) && filter_var($recipient['email'], FILTER_VALIDATE_EMAIL) !== false) {
                $unique[$key] = $recipient;
            }
        }

        return array_values($unique);
    }
}
