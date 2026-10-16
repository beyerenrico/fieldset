<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Audit\AuditLogger;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Filters\ReportFilters;
use App\Domain\Reports\Filters\ReportScope;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Schema\FieldCatalog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Number;

/**
 * Requests an export (BUILD_PROMPT §8.4): checks `reports.view` (§8.6), counts the rows the user may see,
 * stores the request (Filament's Export model), writes one `report.exported` audit entry (§8.11) and
 * queues RunExport after commit on the exports queue. The rows are selected again when the job runs.
 * Limits (review M5, ExportRefused): `reports.exports.per_minute` requests per user, at most
 * `max_pending` pending/running exports per user, and "export all" of at most `max_rows` rows.
 */
final readonly class StartExport
{
    public function __construct(private ReportScope $scope, private AuditLogger $audit) {}

    /** @param  list<string>|null  $uuids  a bulk selection, or null for every row matching the filters */
    public function handle(User $user, Form $form, ExportFormat $format, ReportFilters $filters, string $timezone, ?array $uuids = null, ?string $locale = null): ReportExport
    {
        Gate::forUser($user)->authorize('create', ReportExport::class);
        $this->throttle($user);

        $options = new ExportOptions($filters, $uuids === null ? null : ExportOptions::uuids($uuids), $locale ?? app()->getLocale(), $timezone);
        $query = $this->scope->submissions($user, $form, $filters, $timezone, null, FieldCatalog::forForm($form));
        if ($options->uuids !== null) {
            $query->whereIn('submissions.uuid', $options->uuids);
        }
        $rows = $query->count();
        $max = self::maxRows();
        if ($options->uuids === null && $rows > $max) {
            throw new ExportRefused((string) __('reports.export.too_many_rows', [
                'max' => Number::format($max, locale: app()->getLocale()), 'rows' => Number::format($rows, locale: app()->getLocale()),
            ]));
        }

        $export = ReportExport::query()->create([
            'user_id' => $user->id, 'form_id' => $form->id, 'format' => $format, 'status' => 'pending',
            'exporter' => RunExport::class, 'file_disk' => (string) config('reports.exports.disk', 'local'),
            'total_rows' => $rows, 'options' => $options->toArray(),
        ]);

        $this->audit->record('report.exported', $export, [
            'format' => $format->value, 'form_id' => $form->id, 'form' => $form->name, 'rows' => $export->total_rows,
            'selected' => $options->uuids === null ? null : count($options->uuids), 'filters' => $filters->toArray(),
        ], $user);

        dispatch(new RunExport($export->id, $export->organization_id));

        return $export;
    }

    /** Most rows an export of everything matching the filters may have (a bulk selection: max_selected). */
    public static function maxRows(): int
    {
        return max(1, (int) config('reports.exports.max_rows', 50000));
    }

    private function throttle(User $user): void
    {
        $maxPending = max(1, (int) config('reports.exports.max_pending', 2));
        $open = ReportExport::query()->where('user_id', $user->id)
            ->whereIn('status', [ExportStatus::Pending->value, ExportStatus::Running->value])->count();
        if ($open >= $maxPending) {
            throw new ExportRefused((string) __('reports.export.too_many_pending', ['max' => $maxPending]));
        }

        $key = 'report-export:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('reports.exports.per_minute', 10)))) {
            throw new ExportRefused((string) __('reports.export.throttled', ['seconds' => RateLimiter::availableIn($key)]));
        }
        RateLimiter::hit($key, 60);
    }
}
