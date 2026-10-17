<?php

declare(strict_types=1);

namespace App\Domain\Audit\Export;

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Models\Activity;
use App\Domain\Audit\Query\AuditLogFilters;
use App\Domain\Audit\Query\AuditLogQuery;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Exports\RequestDataExport;
use App\Domain\Privacy\Models\DataExport;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of the filtered audit log (§8.11, ADR 0038): up to `audit.export.sync_max_rows` (default 2,000)
 * entries download at once; more are written by a queued data export (the M5 pattern) and the requester gets
 * a notification with a signed link. Either way one `audit.exported` entry records the filters and the count.
 */
final readonly class ExportAuditLog
{
    public const int DEFAULT_SYNC_MAX_ROWS = 2000;

    public function __construct(private AuditLogger $audit, private RequestDataExport $exports) {}

    public static function syncMaxRows(): int
    {
        return max(1, (int) config('audit.export.sync_max_rows', self::DEFAULT_SYNC_MAX_ROWS));
    }

    public function handle(User $user, AuditLogFilters $filters, string $locale): StreamedResponse|DataExport
    {
        Gate::forUser($user)->authorize('viewAny', Activity::class);
        $query = $filters->apply(AuditLogQuery::forCurrentOrganization());
        $rows = (clone $query)->count();
        if ($rows > self::syncMaxRows()) {
            return $this->exports->auditLog($user, $filters, $locale, $rows);
        }

        $this->audit->record('audit.exported', null, ['filters' => $filters->toArray(), 'rows' => $rows, 'queued' => false], $user);
        $name = 'audit-log-'.now()->setTimezone($filters->timezone)->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $locale, $filters): void {
            $handle = fopen('php://output', 'wb');
            if ($handle !== false) {
                AuditCsv::write($handle, $query, $locale, $filters->timezone);
                fclose($handle);
            }
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
