<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Exports;

use App\Domain\Audit\Export\AuditCsv;
use App\Domain\Audit\Query\AuditLogFilters;
use App\Domain\Audit\Query\AuditLogQuery;
use App\Domain\Identity\Models\User;
use App\Domain\Privacy\Enums\DataExportKind;
use App\Domain\Privacy\Enums\DataExportStatus;
use App\Domain\Privacy\Models\DataExport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Runs one data export inside its organization (RunDataExport): claims it, re-checks that the requester may
 * still have it (DataExportPolicy), writes the file in a temp directory in the requester's locale, stores it
 * on the private disk (`data-exports/{org}/{id}/…`) and notifies the requester with a signed, expiring link.
 */
final readonly class DataExportRunner
{
    public const string TEMP_PREFIX = 'data-export-';

    public function __construct(private UserDataArchive $archive, private DataExportNotifications $notifications) {}

    public function run(int $exportId): void
    {
        $claimed = DataExport::query()->whereKey($exportId)->where('status', DataExportStatus::Pending->value)
            ->update(['status' => DataExportStatus::Running->value, 'updated_at' => now()]);
        $export = $claimed === 1 ? DataExport::query()->with(['requester', 'subject'])->find($exportId) : null;
        if (! $export instanceof DataExport) {
            return;
        }
        $requester = $export->requester;
        if (! $requester instanceof User || ! Gate::forUser($requester)->allows('view', $export)) {
            $this->fail($exportId, new RuntimeException('The requester may no longer have this export.'));

            return;
        }

        try {
            $this->store($export);
        } catch (Throwable $e) {
            $this->fail($exportId, $e);

            throw $e;
        }

        $this->notifications->ready($export->refresh(), $requester);
    }

    public function fail(int $exportId, ?Throwable $exception): void
    {
        $export = DataExport::query()->with('requester')->find($exportId);
        if (! $export instanceof DataExport || ! in_array($export->status, [DataExportStatus::Pending, DataExportStatus::Running], true)) {
            return;
        }
        $export->forceFill([
            'status' => DataExportStatus::Failed, 'completed_at' => now(),
            'error' => mb_substr($exception instanceof Throwable ? class_basename($exception).': '.$exception->getMessage() : 'failed', 0, 1000),
        ])->save();
        if ($export->requester instanceof User) {
            $this->notifications->failed($export, $export->requester);
        }
    }

    private function store(DataExport $export): void
    {
        $directory = sys_get_temp_dir().'/'.self::TEMP_PREFIX.Str::uuid7();
        File::ensureDirectoryExists($directory);
        $previous = app()->getLocale();
        app()->setLocale(is_string($export->options['locale'] ?? null) ? $export->options['locale'] : $previous);

        try {
            [$localPath, $name, $rows] = $this->build($export, $directory);
            $path = $export->directory().'/'.$name;
            $stream = fopen($localPath, 'rb');
            if ($stream === false || ! Storage::disk($export->disk)->writeStream($path, $stream)) {
                throw new RuntimeException('The export file could not be stored.');
            }
            $export->forceFill([
                'status' => DataExportStatus::Completed, 'completed_at' => now(), 'path' => $path, 'file_name' => $name,
                'file_size' => (int) filesize($localPath), 'rows' => $rows, 'expires_at' => now()->addDays(DataExport::keepDays()),
            ])->save();
        } finally {
            app()->setLocale($previous);
            File::deleteDirectory($directory);
        }
    }

    /** @return array{0: string, 1: string, 2: int} local path, download name, rows */
    private function build(DataExport $export, string $directory): array
    {
        $timezone = is_string($export->options['timezone'] ?? null) ? $export->options['timezone'] : 'UTC';
        $stamp = now()->setTimezone($timezone)->format('Y-m-d-His');
        if ($export->kind === DataExportKind::AuditLog) {
            $path = $directory.'/audit-log.csv';
            $handle = fopen($path, 'wb') ?: throw new RuntimeException('The export file could not be written.');
            $filters = AuditLogFilters::fromArray(is_array($export->options['filters'] ?? null) ? $export->options['filters'] : []);
            $rows = AuditCsv::write($handle, $filters->apply(AuditLogQuery::forOrganization($export->organization_id)), app()->getLocale(), $timezone);
            fclose($handle);

            return [$path, 'audit-log-'.$stamp.'.csv', $rows];
        }

        $subject = $export->subject ?? throw new RuntimeException('The export has no subject.');
        $viewer = $export->requester ?? throw new RuntimeException('The export has no requester.');
        [$path, $rows] = $this->archive->write($subject, $viewer, $export->organization_id, (bool) ($export->options['self_service'] ?? false), $timezone, $directory);

        return [$path, 'personal-data-'.$subject->id.'-'.$stamp.'.zip', $rows];
    }
}
