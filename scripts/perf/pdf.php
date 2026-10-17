<?php

declare(strict_types=1);

/*
 * PDF timing (M8-T05, BUILD_PROMPT §11 "a 10-page PDF in under 10 s (queued)"). Runs INSIDE the app
 * container, so the files land on the storage volume the queue worker reads:
 *
 *     docker compose exec -T app php -- [rows=160] [photos=6] [runs=5] < scripts/perf/pdf.php
 *
 * Creates one submitted service report of the demo organization with `rows` repeater lines, `photos`
 * generated 1600×1200 JPEG photos and a signature, then measures
 * - inline: RenderSubmission in this process (compose HTML → Gotenberg → store), `runs` re-renders;
 * - queued: GenerateRendition dispatched to the queue (Horizon) until the rendition row exists, `runs` times.
 * Prints JSON (page count, size, timings). DEVELOPMENT ONLY: the submission is marked like the load
 * generator's rows; `migrate:fresh --seed` removes everything.
 */

use App\Domain\Dashboards\Performance\LoadGenerator;
use App\Domain\Documents\Models\Rendition;
use App\Domain\Documents\Renditions\GenerateRendition;
use App\Domain\Documents\Renditions\RenderSubmission;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (app()->isProduction()) {
    fwrite(STDERR, "Refusing to run in production.\n");
    exit(1);
}

$rows = (int) ($argv[1] ?? 160);
$photoCount = (int) ($argv[2] ?? 6);
$runs = (int) ($argv[3] ?? 5);
$org = Organization::query()->where('slug', 'demo')->firstOrFail();
$worker = User::query()->where('email', 'worker@demo.test')->firstOrFail();

function jpeg(int $seed): string
{
    $image = imagecreatetruecolor(1600, 1200);
    mt_srand($seed);
    for ($y = 0; $y < 1200; $y += 8) {
        for ($x = 0; $x < 1600; $x += 8) {
            imagefilledrectangle($image, $x, $y, $x + 7, $y + 7, (int) imagecolorallocate($image, ($x + $seed * 40) % 256, ($y + mt_rand(0, 60)) % 256, mt_rand(0, 255)));
        }
    }
    ob_start();
    imagejpeg($image, null, 85);

    return (string) ob_get_clean();
}

function png(): string
{
    $image = imagecreatetruecolor(600, 200);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    imagesetthickness($image, 4);
    imageline($image, 20, 160, 580, 40, (int) imagecolorallocate($image, 20, 40, 160));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

$result = app(TenantContext::class)->run($org, function () use ($org, $worker, $rows, $photoCount, $runs): array {
    $form = Form::query()->where('key', 'service_report')->firstOrFail();
    $uuid = (string) Str::uuid7();
    $attachments = [];
    $store = function (string $kind, string $key, string $mime, string $ext, string $bytes) use ($org, $uuid, $worker, &$attachments): string {
        $id = (string) Str::uuid7();
        $path = "attachments/{$org->id}/{$uuid}/{$id}.{$ext}";
        Storage::disk('local')->put($path, $bytes);
        $attachments[] = [
            'uuid' => $id, 'organization_id' => $org->id, 'submission_uuid' => $uuid, 'kind' => $kind, 'element_key' => $key, 'original_name' => "{$key}.{$ext}",
            'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'disk' => 'local', 'path' => $path,
            'created_by' => $worker->id, 'created_at' => now(), 'updated_at' => now(),
        ];

        return $id;
    };

    $items = [];
    $net = '0';
    for ($i = 1; $i <= $rows; $i++) {
        $qty = number_format(($i % 8 + 1) * 0.25 + 0.5, 2, '.', '');
        $price = number_format(12 + ($i * 7) % 180 + 0.5, 2, '.', '');
        $line = bcmul($qty, $price, 2);
        $net = bcadd($net, $line, 2);
        $items[] = ['description' => "Position {$i}: Ventil geprüft, Dichtung ersetzt, Leitung gespült", 'qty' => $qty,
            'unit' => ['h', 'pcs', 'm'][$i % 3], 'unit_price' => $price, 'line_total' => $line];
    }
    $vat = bcdiv(bcadd(bcmul($net, '0.19', 4), '0.005', 4), '1', 2);
    $photos = [];
    for ($p = 1; $p <= $photoCount; $p++) {
        $photos[] = $store('photo', 'photos', 'image/jpeg', 'jpg', jpeg($p));
    }
    $signature = $store('signature', 'customer_signature', 'image/png', 'png', png());

    $data = [
        'customer_name' => 'Perf Kältetechnik GmbH', 'customer_email' => 'perf@example.com', 'site_address' => "Musterweg 1\n10115 Berlin",
        'service_date' => now()->toDateString(), 'technician' => $worker->name, 'service_type' => 'maintenance',
        'defect' => str_repeat('Anlage verliert Druck, Brenner startet verzögert. ', 6), 'work_done' => str_repeat('Brenner eingestellt, Filter gereinigt, Abgasmessung durchgeführt. ', 8),
        'items' => $items, 'net_total' => $net, 'vat' => $vat, 'gross_total' => bcadd($net, $vat, 2),
        'photos' => $photos, 'work_accepted' => true,
        'customer_signature' => ['attachment' => $signature, 'signerName' => 'Perf Kältetechnik GmbH', 'signedAt' => now()->toIso8601String()],
    ];
    DB::table('submissions')->insert([
        'uuid' => $uuid, 'organization_id' => $org->id, 'form_id' => $form->id, 'form_version_id' => $form->current_version_id,
        'status' => 'submitted', 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'revision' => 1, 'number' => 'PERF-'.substr($uuid, -6),
        'created_by' => $worker->id, 'updated_by' => $worker->id, 'submitted_by' => $worker->id, 'submitted_at' => now(),
        'device' => json_encode(['generator' => LoadGenerator::MARKER, 'run' => 'pdf']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('attachments')->insert($attachments);
    $submission = Submission::query()->findOrFail($uuid);

    $inline = [];
    $pages = null;
    $size = null;
    for ($r = 0; $r < $runs; $r++) {
        $started = microtime(true);
        $rendition = app(RenderSubmission::class)->handle($submission, $r === 0 ? Rendition::REASON_SUBMITTED : Rendition::REASON_RERENDER, null, (string) Str::uuid7());
        $inline[] = round((microtime(true) - $started) * 1000);
        $pages = $rendition->page_count;
        $size = $rendition->size;
    }

    $queued = [];
    for ($r = 0; $r < $runs; $r++) {
        $before = Rendition::query()->where('submission_uuid', $uuid)->count();
        $started = microtime(true);
        GenerateRendition::dispatch($uuid, $org->id, Rendition::REASON_RERENDER);
        while (Rendition::query()->where('submission_uuid', $uuid)->count() === $before) {
            if (microtime(true) - $started > 60) {
                throw new RuntimeException('Queued render did not finish within 60 s.');
            }
            usleep(50_000);
        }
        $queued[] = round((microtime(true) - $started) * 1000);
    }

    return ['submission' => $uuid, 'rows' => $rows, 'photos' => $photoCount, 'pages' => $pages, 'pdfBytes' => $size,
        'inlineMs' => $inline, 'queuedMs' => $queued];
});

echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
