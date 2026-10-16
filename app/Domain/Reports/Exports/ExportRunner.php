<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Filters\ReportScope;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Schema\FieldCatalog;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Runs one export inside its organization (RunExport): re-checks that the requester may still export,
 * selects the rows they may see NOW (ReportScope + optional selection), writes the file in a temp
 * directory, stores it on the private export disk and notifies them (export_ready).
 */
final readonly class ExportRunner
{
    /** Prefix of the temp directories a run writes into (ExportSweep removes leftovers of killed workers). */
    public const string TEMP_PREFIX = 'report-export-';

    public function __construct(
        private ReportScope $scope,
        private TabularExport $tabular,
        private PdfZipExport $pdfZip,
        private ExportNotifications $notifications,
    ) {}

    public function run(int $exportId): void
    {
        // Claim the export: a second delivery of the same job (or a retry) finds it running and stops.
        $claimed = ReportExport::query()->whereKey($exportId)->where('status', ExportStatus::Pending->value)
            ->update(['status' => ExportStatus::Running->value, 'updated_at' => now()]);
        $export = $claimed === 1 ? ReportExport::query()->with(['user', 'form'])->find($exportId) : null;
        if (! $export instanceof ReportExport) {
            return;
        }
        $user = $export->user;
        $form = $export->form;
        if (! $user instanceof User || ! $form instanceof Form || ! $user->isActive() || ! $user->hasPermissionIn($export->organization_id, Permission::ReportsView)) {
            $this->fail($exportId, new RuntimeException('The requester may no longer export.'));

            return;
        }

        try {
            $file = $this->store($export, $user, $form, ExportOptions::fromArray($export->options));
        } catch (Throwable $e) {
            $this->fail($exportId, $e);

            throw $e;
        }

        $this->notifications->ready($export, $user, $file->skipped);
    }

    /** Marks an unfinished export failed and tells the requester (also called by RunExport::failed). */
    public function fail(int $exportId, ?Throwable $exception): void
    {
        $export = ReportExport::query()->with(['user', 'form'])->find($exportId);
        if (! $export instanceof ReportExport || ! in_array($export->status, [ExportStatus::Pending, ExportStatus::Running], true)) {
            return;
        }
        $export->forceFill([
            'status' => ExportStatus::Failed, 'completed_at' => now(),
            'error' => mb_substr($exception instanceof Throwable ? class_basename($exception).': '.$exception->getMessage() : 'failed', 0, 1000),
        ])->save();

        if ($export->user instanceof User) {
            $this->notifications->failed($export, $export->user);
        }
    }

    private function store(ReportExport $export, User $user, Form $form, ExportOptions $options): ExportFile
    {
        $directory = sys_get_temp_dir().'/'.self::TEMP_PREFIX.Str::uuid7();
        File::ensureDirectoryExists($directory);

        try {
            $file = $this->inLocale($options->locale, fn (): ExportFile => $this->build($export, $user, $form, $options, $directory));
            $name = Str::slug($form->key).'-'.now()->setTimezone($options->timezone)->format('Y-m-d-His').'.'.$file->extension;
            $path = $export->getFileDirectory().'/'.$name;
            $this->upload($export->file_disk, $path, $file->localPath);

            try {
                $export->forceFill([
                    'status' => ExportStatus::Completed, 'completed_at' => now(), 'file_path' => $path, 'file_name' => $name,
                    'file_size' => (int) filesize($file->localPath), 'total_rows' => $file->rows, 'processed_rows' => $file->rows,
                    'successful_rows' => $file->included, 'expires_at' => now()->addDays(max(1, (int) config('reports.exports.keep_days', 7))),
                ])->save();
            } catch (Throwable $e) {
                Storage::disk($export->file_disk)->delete($path); // no orphaned file without its row

                throw $e;
            }

            return $file;
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function upload(string $disk, string $path, string $localPath): void
    {
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The export file could not be read.');
        }

        try {
            if (! Storage::disk($disk)->writeStream($path, $handle)) {
                throw new RuntimeException('The export file could not be stored.');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private function build(ReportExport $export, User $user, Form $form, ExportOptions $options, string $directory): ExportFile
    {
        $catalog = FieldCatalog::forForm($form);
        $query = $this->scope->submissions($user, $form, $options->filters, $options->timezone, null, $catalog);
        if ($options->uuids !== null) {
            $query->whereIn('submissions.uuid', $options->uuids);
        }

        return $export->format === ExportFormat::PdfZip
            ? $this->pdfZip->write($export, $query, $directory)
            : $this->tabular->write($export, $query, $catalog, $options, $directory);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function inLocale(string $locale, Closure $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
