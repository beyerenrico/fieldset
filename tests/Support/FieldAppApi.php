<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Field App API calls for a FieldAppScenario, usable from any Pest file (no global helper clashes). */
final class FieldAppApi
{
    /** @param  array<string, mixed>  $data */
    public static function put(FieldAppScenario $s, User $user, string $uuid, array $data, ?int $revision = null, ?int $versionId = null): TestResponse
    {
        $body = ['form_version_id' => $versionId ?? $s->version->id, 'data' => (object) $data];
        if ($revision !== null) {
            $body['revision'] = $revision;
        }

        return test()->withHeaders(apiHeaders($user, $s->org))->putJson("/api/v1/submissions/{$uuid}", $body);
    }

    public static function submit(FieldAppScenario $s, User $user, string $uuid, int $revision, ?string $key = null): TestResponse
    {
        return test()->withHeaders([...apiHeaders($user, $s->org), 'Idempotency-Key' => $key ?? (string) Str::uuid()])
            ->postJson("/api/v1/submissions/{$uuid}/submit", ['revision' => $revision]);
    }

    public static function signature(FieldAppScenario $s, User $user, string $submission, string $attachment): TestResponse
    {
        return test()->withHeaders(apiHeaders($user, $s->org))->post("/api/v1/submissions/{$submission}/attachments", [
            'file' => UploadedFile::fake()->createWithContent('signature.png', FieldAppScenario::png()),
            'attachment_uuid' => $attachment, 'kind' => 'signature', 'element_key' => 'customer_signature',
        ]);
    }

    /** A valid service-report draft (revision 1) with its signature uploaded; returns the submission uuid. */
    public static function readyDraft(FieldAppScenario $s, User $user, ?int $versionId = null): string
    {
        $uuid = FieldAppScenario::uuid();
        $signature = FieldAppScenario::uuid();
        self::put($s, $user, $uuid, FieldAppScenario::serviceReportData($signature), null, $versionId)->assertCreated();
        self::signature($s, $user, $uuid, $signature)->assertCreated();

        return $uuid;
    }
}
