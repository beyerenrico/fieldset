<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Submissions\Models\Submission;
use App\Domain\Submissions\Support\SubmissionVisibility;

/**
 * Forms a user can report on: those with at least one submission (not a draft) the user may see
 * (§8.6 "View submissions"). Owners, admins and viewers (view_all) can pick any published form.
 */
final readonly class ReportForms
{
    public function __construct(private SubmissionVisibility $visibility, private TenantContext $tenant) {}

    public function mayReport(User $user): bool
    {
        $orgId = $this->tenant->id();

        return $orgId !== null && $user->isActive() && $user->hasPermissionIn($orgId, Permission::ReportsView);
    }

    /** @return array<int, string> form id => name */
    public function options(User $user): array
    {
        if (! $this->mayReport($user)) {
            return [];
        }

        $query = Form::query()->whereNotNull('current_version_id');
        if (! $user->hasPermissionIn($this->tenant->require(), Permission::SubmissionsViewAll)) {
            $query->whereIn('id', $this->visibility->apply(Submission::query(), $user)
                ->where('status', '!=', SubmissionStatus::Draft->value)->select('form_id'));
        }

        return $query->orderBy('name')->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all();
    }

    public function find(User $user, ?int $formId): ?Form
    {
        return $formId !== null && array_key_exists($formId, $this->options($user)) ? Form::query()->find($formId) : null;
    }
}
