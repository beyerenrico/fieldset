<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Filters\ReportScope;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Schema\FieldCatalog;
use Illuminate\Support\Facades\Gate;

/**
 * Requests an export (BUILD_PROMPT §8.4): checks `reports.view` (§8.6), counts the rows the user may see,
 * stores the request (Filament's Export model), writes one `report.exported` audit entry (§8.11) and
 * queues RunExport after commit. The rows are selected again when the job runs.
 */
final readonly class StartExport
{
    public function __construct(private ReportScope $scope, private AuditLogger $audit) {}

    /** @param  list<string>|null  $uuids  a bulk selection, or null for every row matching the filters */
    public function handle(User $user, Form $form, ExportFormat $format, ReportFilters $filters, string $timezone, ?array $uuids = null, ?string $locale = null): ReportExport
    {
        Gate::forUser($user)->authorize('create', ReportExport::class);

        $options = new ExportOptions($filters, $uuids === null ? null : ExportOptions::uuids($uuids), $locale ?? app()->getLocale(), $timezone);
        $query = $this->scope->submissions($user, $form, $filters, $timezone, null, FieldCatalog::forForm($form));
        if ($options->uuids !== null) {
            $query->whereIn('submissions.uuid', $options->uuids);
        }

        $export = ReportExport::query()->create([
            'user_id' => $user->id, 'form_id' => $form->id, 'format' => $format, 'status' => 'pending',
            'exporter' => RunExport::class, 'file_disk' => (string) config('reports.exports.disk', 'local'),
            'total_rows' => $query->count(), 'options' => $options->toArray(),
        ]);

        $this->audit->record('report.exported', $export, [
            'format' => $format->value, 'form_id' => $form->id, 'form' => $form->name, 'rows' => $export->total_rows,
            'selected' => $options->uuids === null ? null : count($options->uuids), 'filters' => $filters->toArray(),
        ], $user);

        dispatch(new RunExport($export->id, $export->organization_id));

        return $export;
    }
}
