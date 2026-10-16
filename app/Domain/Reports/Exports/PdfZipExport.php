<?php

declare(strict_types=1);

namespace App\Domain\Reports\Exports;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Renditions\RenditionLinks;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Bulk PDF ZIP (BUILD_PROMPT §8.4): the latest rendition of every selected submission, named by
 * number. Stops at reports.pdf_zip.max_bytes / max_files; submissions without a PDF or beyond the cap
 * are counted as skipped and reported in the notification.
 */
final readonly class PdfZipExport
{
    /**
     * @param  Builder<Submission>  $query
     * @param  list<string>  $uuids  snapshot of the rows in explorer order (ExportRowReader::selection; no OFFSET paging)
     */
    public function write(ReportExport $export, Builder $query, array $uuids, string $directory): ExportFile
    {
        $zipPath = $directory.'/export.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the PDF archive.');
        }

        $maxBytes = (int) config('reports.pdf_zip.max_bytes');
        $maxFiles = (int) config('reports.pdf_zip.max_files');
        $state = ['rows' => 0, 'included' => 0, 'bytes' => 0, 'names' => []];
        $base = (clone $query)->reorder()->select(['submissions.uuid', 'submissions.number']);
        foreach (array_chunk($uuids, max(1, (int) config('reports.exports.chunk_size', 500))) as $chunk) {
            $found = (clone $base)->whereIn('submissions.uuid', $chunk)->get()->keyBy('uuid');
            $latest = $this->latestRenditions($chunk);
            foreach ($chunk as $uuid) {
                $submission = $found->get($uuid);
                if (! $submission instanceof Submission) {
                    continue; // no longer visible or matching since the snapshot
                }
                $state['rows']++;
                $rendition = $latest[$uuid] ?? null;
                if ($rendition === null || $state['included'] >= $maxFiles || $maxBytes < $state['bytes'] + $rendition->size) {
                    continue;
                }
                if ($this->add($zip, $directory, $rendition, $submission->number, $state['names'])) {
                    $state['included']++;
                    $state['bytes'] += $rendition->size;
                }
            }
            $export->forceFill(['processed_rows' => $state['rows'], 'successful_rows' => $state['included']])->save();
        }

        if ($state['included'] === 0) {
            $zip->addFromString('README.txt', (string) __('reports.export.pdf_none'));
        }
        $zip->close();

        return new ExportFile($zipPath, 'zip', $state['rows'], $state['included'], $state['rows'] - $state['included']);
    }

    /**
     * @param  list<string>  $uuids
     * @return array<string, Rendition> submission uuid => newest rendition
     */
    private function latestRenditions(array $uuids): array
    {
        $latest = [];
        Rendition::query()->whereIn('submission_uuid', $uuids)->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'uuid', 'organization_id', 'submission_uuid', 'disk', 'path', 'size', 'created_at'])
            ->each(function (Rendition $rendition) use (&$latest): void {
                $latest[$rendition->submission_uuid] ??= $rendition;
            });

        return $latest;
    }

    /** @param  array<string, true>  $names */
    private function add(ZipArchive $zip, string $directory, Rendition $rendition, ?string $number, array &$names): bool
    {
        $stream = Storage::disk($rendition->disk)->readStream($rendition->path);
        if (! is_resource($stream)) {
            return false;
        }
        $local = $directory.'/'.$rendition->uuid.'.pdf';
        file_put_contents($local, $stream);
        fclose($stream);

        $name = RenditionLinks::fileName($rendition, $number);
        for ($i = 2; isset($names[$name]); $i++) {
            $name = pathinfo(RenditionLinks::fileName($rendition, $number), PATHINFO_FILENAME).'-'.$i.'.pdf';
        }
        $names[$name] = true;

        return $zip->addFile($local, $name);
    }
}
