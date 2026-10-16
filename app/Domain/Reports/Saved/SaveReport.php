<?php

declare(strict_types=1);

namespace App\Domain\Reports\Saved;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Reports\Enums\ReportMetric;
use App\Domain\Reports\Enums\ReportSchedule;
use App\Domain\Reports\Enums\ReportVisibility;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Models\SavedReport;
use App\Domain\Reports\ReportForms;
use App\Domain\Reports\Schema\FieldCatalog;
use App\Domain\Reports\Schema\ReportField;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Creates or updates a saved report from untrusted input (Filament form): the form must be one the
 * actor can report on, group-by and metric key must exist in the form's catalog, filters are normalized
 * (ReportFilters) and recipients are reduced to active members with `reports.view` — only the creator
 * for a private report. Switching a schedule on or changing it sets `last_sent_at` to now, so the first
 * mail covers the next complete period.
 */
final readonly class SaveReport
{
    public function __construct(private ReportForms $forms, private TenantContext $tenant) {}

    /** @param  array<string, mixed>  $data */
    public function handle(User $actor, ?SavedReport $report, array $data): SavedReport
    {
        Gate::forUser($actor)->authorize($report instanceof SavedReport ? 'update' : 'create', $report ?? SavedReport::class);

        $form = $this->forms->find($actor, is_numeric($data['form_id'] ?? null) ? (int) $data['form_id'] : null)
            ?? throw new InvalidArgumentException((string) __('validation.exists', ['attribute' => __('reports.saved.form')]));
        $catalog = FieldCatalog::forForm($form);
        $metric = ReportMetric::tryFrom(is_string($data['metric'] ?? null) ? $data['metric'] : '') ?? ReportMetric::Count;
        $visibility = ReportVisibility::tryFrom(is_string($data['visibility'] ?? null) ? $data['visibility'] : '') ?? ReportVisibility::Private;
        $creatorId = $report->created_by ?? $actor->id;

        $attributes = [
            'form_id' => $form->id,
            'name' => mb_substr(trim(is_string($data['name'] ?? null) ? $data['name'] : ''), 0, 120) ?: (string) __('reports.saved.label'),
            'filters' => $this->filters(is_array($data['filters'] ?? null) ? $data['filters'] : []),
            'group_by' => ReportGrouping::parse(is_string($data['group_by'] ?? null) ? $data['group_by'] : null, $catalog)->value(),
            'metric' => $metric,
            'metric_key' => $this->metricKey($metric, $catalog, $data['metric_key'] ?? null),
            'visibility' => $visibility,
            'schedule' => ReportSchedule::tryFrom(is_string($data['schedule'] ?? null) ? $data['schedule'] : '') ?? ReportSchedule::None,
            'recipients' => $this->recipients(is_array($data['recipients'] ?? null) ? $data['recipients'] : [], $visibility, $creatorId),
        ];

        $report ??= new SavedReport(['created_by' => $actor->id]);
        $scheduleChanged = ! $report->exists || $report->schedule !== $attributes['schedule'];
        $report->fill($attributes);
        if ($scheduleChanged && $attributes['schedule'] !== ReportSchedule::None) {
            // Baseline for the next mail: switching a schedule on (or changing it) never mails a past period at once.
            $report->forceFill(['last_sent_at' => now()]);
        }
        $report->save();

        return $report;
    }

    /**
     * Active members of the organization holding `reports.view` (the people a report may be mailed to).
     *
     * @return array<int, string> user id => name
     */
    public function recipientOptions(): array
    {
        $organization = Organization::query()->findOrFail($this->tenant->require());

        return $organization->activeMembers()->orderBy('name')->get(['users.id', 'users.name', 'users.status'])
            ->filter(fn (User $user): bool => $user->isActive() && $user->hasPermissionIn($organization->id, Permission::ReportsView))
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->name])->all();
    }

    /**
     * Normalized filters; a field filter that does not survive normalization is an error, not dropped.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private function filters(array $data): array
    {
        $filters = ReportFilters::fromArray($data);
        if (count($filters->fields) !== count(is_array($data['fields'] ?? null) ? $data['fields'] : [])) {
            throw new InvalidArgumentException((string) __('reports.saved.invalid_filter'));
        }

        return $filters->toArray();
    }

    private function metricKey(ReportMetric $metric, FieldCatalog $catalog, mixed $key): ?string
    {
        if (! $metric->needsField()) {
            return null;
        }
        $field = is_string($key) ? $catalog->get($key) : null;
        if (! $field instanceof ReportField || ! $field->isNumeric()) {
            throw new InvalidArgumentException((string) __('reports.saved.invalid_metric'));
        }

        return $field->key;
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    private function recipients(array $ids, ReportVisibility $visibility, int $creatorId): array
    {
        $ids = array_map(intval(...), array_filter($ids, is_numeric(...)));
        if ($visibility === ReportVisibility::Private) {
            $ids = array_intersect($ids, [$creatorId]);
        }

        return array_values(array_unique(array_intersect($ids, array_keys($this->recipientOptions()))));
    }
}
