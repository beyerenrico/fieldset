<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Retention;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Submissions\Models\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The stored files of one submission (attachments and PDF renditions) and the rendition hashes kept as proof
 * of what was issued. Files are removed only after the database change committed (deleteLater), so a
 * rolled-back anonymization or deletion never loses a file.
 */
final readonly class SubmissionFiles
{
    /**
     * @param  list<array{disk: string, path: string}>  $attachments
     * @param  list<array{disk: string, path: string}>  $renditionFiles
     * @param  list<array<string, mixed>>  $renditionHashes
     */
    private function __construct(public array $attachments, public array $renditionFiles, public array $renditionHashes) {}

    public static function of(string $submissionUuid): self
    {
        $attachments = Attachment::query()->where('submission_uuid', $submissionUuid)->get(['disk', 'path'])
            ->map(fn (Attachment $a): array => ['disk' => $a->disk, 'path' => $a->path])->values()->all();
        $renditions = Rendition::query()->where('submission_uuid', $submissionUuid)->orderBy('id')
            ->get(['uuid', 'disk', 'path', 'input_hash', 'file_sha256', 'form_version_id', 'design_version_id', 'created_at']);

        return new self(
            array_values($attachments),
            array_values($renditions->map(fn (Rendition $r): array => ['disk' => $r->disk, 'path' => $r->path])->all()),
            array_values($renditions->map(fn (Rendition $r): array => [
                'uuid' => $r->uuid, 'input_hash' => $r->input_hash, 'file_sha256' => $r->file_sha256,
                'form_version_id' => $r->form_version_id, 'design_version_id' => $r->design_version_id,
                'created_at' => $r->created_at->toIso8601String(),
            ])->all()),
        );
    }

    /** Deletes attachment files and, with $renditions, the PDF files, once the transaction commits. */
    public function deleteLater(bool $renditions): void
    {
        $files = [...$this->attachments, ...($renditions ? $this->renditionFiles : [])];
        if ($files === []) {
            return;
        }
        DB::afterCommit(static function () use ($files): void {
            foreach ($files as $file) {
                try {
                    Storage::disk($file['disk'])->delete($file['path']);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }
}
