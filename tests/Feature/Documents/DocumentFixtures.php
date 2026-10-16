<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Actions\SubmitSubmission;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FieldAppScenario;

/** Shared fixtures for the document tests (designs, renditions, Gotenberg). */
final class DocumentFixtures
{
    /** Own fake disk: Storage::fake('local') is shared between concurrent test runs (LEARNINGS #23). */
    public const string DISK = 'documents-tests';

    public const string GOTENBERG_ROUTE = '*/forms/chromium/convert/html';

    public static function fakeDisk(): void
    {
        Storage::fake(self::DISK);
        config(['documents.disk' => self::DISK, 'documents.render_on_submit' => true]);
    }

    /** A minimal, valid PDF with N pages; a random comment makes every "render" byte-distinct. */
    public static function pdf(int $pages = 1): string
    {
        $kids = implode(' ', array_map(static fn (int $i): string => (3 + $i).' 0 R', range(0, $pages - 1)));
        $objects = "1 0 obj <</Type /Catalog /Pages 2 0 R>> endobj\n2 0 obj <</Type /Pages /Kids [{$kids}] /Count {$pages}>> endobj\n";
        for ($i = 0; $i < $pages; $i++) {
            $objects .= (3 + $i)." 0 obj <</Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]>> endobj\n";
        }

        return "%PDF-1.4\n%".bin2hex(random_bytes(8))."\n{$objects}trailer <</Root 1 0 R>>\n%%EOF\n";
    }

    /** Set to an HTTP status to make the faked Gotenberg fail (e.g. 503). */
    public static ?int $gotenbergFailure = null;

    public static function fakeGotenberg(int $pages = 1): void
    {
        self::$gotenbergFailure = null;
        Http::fake([self::GOTENBERG_ROUTE => fn () => self::$gotenbergFailure !== null
            ? Http::response('unavailable', self::$gotenbergFailure)
            : Http::response(self::pdf($pages), 200, ['Content-Type' => 'application/pdf'])]);
    }

    /** The parts posted to Gotenberg, keyed by file name (index.html, header.html, footer.html) or field. */
    public static function sentParts(): array
    {
        $parts = [];
        foreach (Http::recorded() as [$request]) {
            foreach ($request->data() as $part) {
                $parts[$part['filename'] ?? $part['name']] = (string) $part['contents'];
            }
        }

        return $parts;
    }

    /**
     * A draft service report by the scenario's worker with `$lines` repeater lines and a stored
     * signature on the fake disk (no API, so the files never touch the shared "local" disk).
     */
    public static function draft(FieldAppScenario $s, int $lines = 2, ?User $author = null): Submission
    {
        $author ??= $s->worker;
        $signature = FieldAppScenario::uuid();

        return app(TenantContext::class)->run($s->org, function () use ($s, $lines, $author, $signature): Submission {
            $submission = Submission::factory()->create([
                'form_id' => $s->form->id, 'form_version_id' => $s->version->id, 'created_by' => $author->id,
                'data' => json_decode((string) json_encode(FieldAppScenario::serviceReportData($signature, $lines))),
            ]);
            $png = self::signaturePng();
            $path = "attachments/{$s->org->id}/{$submission->uuid}/{$signature}.png";
            Storage::disk(self::DISK)->put($path, $png);
            Attachment::factory()->create([
                'uuid' => $signature, 'submission_uuid' => $submission->uuid, 'kind' => 'signature', 'element_key' => 'customer_signature',
                'mime' => 'image/png', 'size' => strlen($png), 'sha256' => hash('sha256', $png), 'disk' => self::DISK, 'path' => $path,
            ]);

            return $submission;
        });
    }

    /** Submits a draft through the real action (number, status, SubmissionSubmitted after commit). */
    public static function submit(FieldAppScenario $s, Submission $submission, ?User $author = null): Submission
    {
        $author ??= $s->worker;

        return app(TenantContext::class)->run($s->org, fn (): Submission => app(SubmitSubmission::class)->handle($author, $s->org, $submission, $submission->revision));
    }

    public static function signaturePng(): string
    {
        $image = imagecreatetruecolor(300, 100);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imageline($image, 10, 80, 290, 20, (int) imagecolorallocate($image, 20, 40, 160));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** Text of a PDF via poppler's pdftotext (installed on the host and in CI; a missing binary fails loudly). */
    public static function pdfText(string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($file, $pdf);
        try {
            $result = Process::run(['pdftotext', '-layout', $file, '-']);
            if (! $result->successful()) {
                throw new RuntimeException('pdftotext failed (install poppler-utils): '.$result->errorOutput());
            }

            return $result->output();
        } finally {
            @unlink($file);
        }
    }

    /** "pdfinfo" facts (Pages, Page size, …). */
    public static function pdfInfo(string $pdf): string
    {
        $file = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($file, $pdf);
        try {
            $result = Process::run(['pdfinfo', $file]);
            if (! $result->successful()) {
                throw new RuntimeException('pdfinfo failed (install poppler-utils): '.$result->errorOutput());
            }

            return $result->output();
        } finally {
            @unlink($file);
        }
    }
}
