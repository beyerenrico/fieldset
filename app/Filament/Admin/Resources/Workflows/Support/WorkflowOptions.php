<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Workflows\Support;

use App\Domain\Documents\Models\Design;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Enums\SubmissionStatus;
use App\Domain\Workflows\Models\WebhookEndpoint;
use App\Filament\Admin\Support\OrganizationOptions;

/** Select options of the workflow editor, scoped to the current organization. */
final class WorkflowOptions
{
    /** @return array<int, string> active members, by name */
    public static function members(): array
    {
        $organization = app(TenantContext::class)->organization();
        if ($organization === null) {
            return [];
        }

        return $organization->members()->wherePivot('status', MembershipStatus::Active->value)->orderBy('name')->get(['users.id', 'users.name', 'users.email'])
            ->mapWithKeys(fn (User $u): array => [$u->id => "{$u->name} ({$u->email})"])->all();
    }

    /** @return array<int, string> */
    public static function groups(): array
    {
        return OrganizationOptions::groups();
    }

    /** @return array<string, string> every role of the organization */
    public static function roles(): array
    {
        return Role::query()->where('organization_id', app(TenantContext::class)->require())->orderBy('id')->pluck('name')
            ->mapWithKeys(fn (mixed $name): array => [(string) $name => OrganizationOptions::roleLabel((string) $name)])->all();
    }

    /** @return array<int, string> */
    public static function forms(): array
    {
        return Form::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> design key => name */
    public static function designs(): array
    {
        return Design::query()->orderBy('name')->pluck('name', 'key')->all();
    }

    /** @return array<int, string> endpoints by name, disabled ones marked "(inactive)" */
    public static function endpoints(): array
    {
        return WebhookEndpoint::query()->orderBy('name')->get(['id', 'name', 'active'])
            ->mapWithKeys(fn (WebhookEndpoint $e): array => [$e->id => $e->active ? $e->name : $e->name.' '.__('workflows.webhooks.inactive_suffix')])->all();
    }

    /**
     * @param  list<SubmissionStatus>  $statuses
     * @return array<string, string>
     */
    public static function statusOptions(array $statuses): array
    {
        $options = [];
        foreach ($statuses as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    /**
     * @param  list<SubmissionStatus>  $except
     * @return array<string, string>
     */
    public static function statuses(array $except = [SubmissionStatus::Draft]): array
    {
        $options = [];
        foreach (SubmissionStatus::cases() as $status) {
            if (! in_array($status, $except, true)) {
                $options[$status->value] = $status->label();
            }
        }

        return $options;
    }
}
