<?php

declare(strict_types=1);

use App\Domain\Forms\Models\FormVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FieldAppApi;
use Tests\Support\FieldAppScenario;

beforeEach(function (): void {
    Storage::fake('local');
    $this->s = FieldAppScenario::make();
    $this->uuid = FieldAppScenario::uuid();
    FieldAppApi::put($this->s, $this->s->worker, $this->uuid, ['customer_name' => 'A'])->assertCreated();
});

function uploadTo(FieldAppScenario $s, string $submission, string $name, string $bytes, string $kind, string $element, string $uuid): TestResponse
{
    return test()->withHeaders(apiHeaders($s->worker, $s->org))->post("/api/v1/submissions/{$submission}/attachments", [
        'file' => UploadedFile::fake()->createWithContent($name, $bytes),
        'attachment_uuid' => $uuid, 'kind' => $kind, 'element_key' => $element,
    ]);
}

function pngOfSize(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('refuses an attachment uuid that exists in another organization without writing a file', function (): void {
    $other = FieldAppScenario::make();
    $otherDraft = FieldAppScenario::uuid();
    FieldAppApi::put($other, $other->worker, $otherDraft, ['customer_name' => 'B'])->assertCreated();
    $id = FieldAppScenario::uuid();
    uploadTo($other, $otherDraft, 's.png', FieldAppScenario::png(), 'signature', 'customer_signature', $id)->assertCreated();

    uploadTo($this->s, $this->uuid, 's.png', FieldAppScenario::png(), 'signature', 'customer_signature', $id)
        ->assertStatus(409)->assertJsonPath('error.code', 'attachment_conflict');

    expect(Storage::disk('local')->allFiles("attachments/{$this->s->org->id}"))->toBe([]);
});

it('treats a concurrent upload of the same attachment uuid as a repeat and writes no file of its own', function (): void {
    $id = FieldAppScenario::uuid();
    $s = $this->s;
    $submission = $this->uuid;
    $raced = false;
    // Another request stores the same attachment uuid after our lookup, right before our insert.
    Event::listen('eloquent.retrieved: '.FormVersion::class, function () use (&$raced, $id, $s, $submission): void {
        if ($raced) {
            return;
        }
        $raced = true;
        DB::table('attachments')->insert([
            'uuid' => $id, 'organization_id' => $s->org->id, 'submission_uuid' => $submission, 'kind' => 'signature',
            'element_key' => 'customer_signature', 'mime' => 'image/png', 'size' => 1, 'sha256' => str_repeat('0', 64),
            'disk' => 'local', 'path' => "attachments/{$s->org->id}/{$submission}/{$id}.png", 'created_by' => $s->worker->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    uploadTo($s, $submission, 's.png', FieldAppScenario::png(), 'signature', 'customer_signature', $id)
        ->assertOk()->assertJsonPath('data.attachment.uuid', $id);

    expect($raced)->toBeTrue()->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('rejects images whose dimensions exceed the decode limits before decoding them', function (): void {
    uploadTo($this->s, $this->uuid, 'wide.png', pngOfSize(12001, 1), 'photo', 'photos', FieldAppScenario::uuid())
        ->assertUnprocessable()->assertJsonPath('error.code', 'image_too_large');

    // A tiny file whose header claims 8000 × 8000 px (64 megapixels).
    $bomb = substr_replace(FieldAppScenario::png(), pack('NN', 8000, 8000), 16, 8);
    uploadTo($this->s, $this->uuid, 'bomb.png', $bomb, 'photo', 'photos', FieldAppScenario::uuid())
        ->assertUnprocessable()->assertJsonPath('error.code', 'image_too_large');

    uploadTo($this->s, $this->uuid, 'ok.png', pngOfSize(1600, 1200), 'photo', 'photos', FieldAppScenario::uuid())->assertCreated();
});

it('serves downloads with a sandboxing CSP and forces non-images to download', function (): void {
    $image = FieldAppScenario::uuid();
    uploadTo($this->s, $this->uuid, 's.png', FieldAppScenario::png(), 'signature', 'customer_signature', $image)->assertCreated();
    $pdf = FieldAppScenario::uuid();
    $path = "attachments/{$this->s->org->id}/{$this->uuid}/{$pdf}.pdf";
    Storage::disk('local')->put($path, "%PDF-1.4\n%%EOF");
    DB::table('attachments')->insert([
        'uuid' => $pdf, 'organization_id' => $this->s->org->id, 'submission_uuid' => $this->uuid, 'kind' => 'file',
        'element_key' => 'photos', 'original_name' => 'report.pdf', 'mime' => 'application/pdf', 'size' => 14,
        'sha256' => str_repeat('0', 64), 'disk' => 'local', 'path' => $path, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $get = fn (string $id) => $this->withHeaders(apiHeaders($this->s->worker, $this->s->org))->get("/api/v1/submissions/{$this->uuid}/attachments/{$id}");

    $inline = $get($image)->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect((string) $inline->headers->get('Content-Disposition'))->toStartWith('inline');

    $download = $get($pdf)->assertOk()->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect((string) $download->headers->get('Content-Disposition'))->toStartWith('attachment')->toContain('report.pdf');
});
