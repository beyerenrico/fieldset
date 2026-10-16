<?php

declare(strict_types=1);

namespace App\Domain\Documents\Renditions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\Designs\DesignResolver;
use App\Domain\Documents\Events\RenditionCreated;
use App\Domain\Documents\Models\DesignVersion;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Rendering\DocumentRenderer;
use App\Domain\Documents\Rendering\RenderedDocument;
use App\Domain\Documents\Rendering\RenderFailedException;
use App\Domain\Documents\Rendering\RenderInputFactory;
use App\Domain\Identity\Models\User;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Renders a submission into a new immutable rendition (§8.5): pinned design version, form version,
 * renderer version, input hash, file hash and page count; the PDF lands on the private disk. An
 * automatic render whose input equals the latest rendition's (a retried job, a duplicate event)
 * returns that rendition; an explicit re-render always creates a new one.
 */
final readonly class RenderSubmission
{
    public function __construct(
        private DesignResolver $designs,
        private RenderInputFactory $inputs,
        private DocumentRenderer $renderer,
        private AuditLogger $audit,
    ) {}

    public function handle(Submission $submission, string $reason = Rendition::REASON_SUBMITTED, ?User $actor = null): Rendition
    {
        $designVersion = $this->designs->forSubmission($submission);
        $prepared = $this->renderer->prepare($this->inputs->forSubmission($submission, $designVersion));
        $latest = $this->latest($submission);
        if ($latest instanceof Rendition && $this->isDuplicate($reason, $latest, $prepared->inputHash)) {
            return $latest;
        }

        $document = $this->renderer->convert($prepared);
        $uuid = (string) Str::uuid7();
        $disk = (string) config('documents.disk', 'local');
        $path = sprintf('renditions/%d/%s/%s.pdf', $submission->organization_id, $submission->uuid, $uuid);
        if (! Storage::disk($disk)->put($path, $document->pdf)) {
            throw new RuntimeException("Could not write rendition file [{$path}].");
        }

        try {
            $rendition = $this->persist($submission, $designVersion, $document, [
                'uuid' => $uuid, 'disk' => $disk, 'path' => $path, 'reason' => $reason, 'created_by' => $actor?->id,
            ]);
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($path);
            throw $e;
        }
        if ($rendition->uuid !== $uuid) {
            Storage::disk($disk)->delete($path); // a concurrent render of the same input won

            return $rendition;
        }

        $this->audit->record('submission.pdf_generated', $submission, [
            'rendition' => $rendition->uuid, 'reason' => $reason, 'design_version_id' => $designVersion->id,
            'form_version_id' => $submission->form_version_id, 'input_hash' => $rendition->input_hash, 'pages' => $rendition->page_count,
        ], $actor);
        DB::afterCommit(fn () => event(new RenditionCreated($rendition->id, $submission->uuid, $submission->organization_id)));

        return $rendition;
    }

    /**
     * Advisory lock id serializing rendition inserts of one submission. "Send to customer" takes it
     * too, so a send request never misses a rendition committed at the same moment.
     */
    public static function lockId(string $submissionUuid): int
    {
        return crc32('renditions:'.$submissionUuid);
    }

    /**
     * Inserts under a per-submission advisory lock: re-checks the duplicate and the design pin, so
     * concurrent renders (a re-delivered job, a re-render racing the first render) stay consistent.
     *
     * @param  array{uuid: string, disk: string, path: string, reason: string, created_by: int|null}  $file
     */
    private function persist(Submission $submission, DesignVersion $designVersion, RenderedDocument $document, array $file): Rendition
    {
        return DB::transaction(function () use ($submission, $designVersion, $document, $file): Rendition {
            DB::select('select pg_advisory_xact_lock(?)', [self::lockId($submission->uuid)]);
            $latest = $this->latest($submission);
            if ($latest instanceof Rendition && $this->isDuplicate($file['reason'], $latest, $document->inputHash)) {
                return $latest;
            }
            $pinned = Rendition::query()->where('submission_uuid', $submission->uuid)->orderBy('id')->value('design_version_id');
            if (is_numeric($pinned) && (int) $pinned !== $designVersion->id) {
                throw RenderFailedException::because(new RuntimeException('The design version was pinned by a concurrent render; retry.'));
            }

            return Rendition::query()->create([...$file,
                'submission_uuid' => $submission->uuid, 'form_version_id' => $submission->form_version_id,
                'design_version_id' => $designVersion->id, 'input_hash' => $document->inputHash, 'file_sha256' => $document->sha256(),
                'renderer_version' => $document->rendererVersion, 'page_count' => $document->pageCount, 'size' => strlen($document->pdf),
            ]);
        });
    }

    private function latest(Submission $submission): ?Rendition
    {
        return Rendition::query()->where('submission_uuid', $submission->uuid)->orderByDesc('id')->first();
    }

    /** Automatic renders reuse the latest rendition when the input is unchanged; re-renders never do. */
    private function isDuplicate(string $reason, Rendition $latest, string $inputHash): bool
    {
        return $reason === Rendition::REASON_SUBMITTED && $latest->input_hash === $inputHash;
    }
}
