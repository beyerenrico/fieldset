<?php

declare(strict_types=1);

namespace Tests\Support\TenantIsolation;

use App\Domain\Documents\Models\Rendition;
use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormVersion;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Str;

/**
 * One cross-tenant attack per tenant-scoped API route (routes with the `organization` middleware).
 * Each case receives the victim organization and the attacker (a member of another organization)
 * and returns [method, uri, payload, header org ('victim'|'attacker'), extra headers]. The suite
 * fails if a tenant route has no case here (BUILD_PROMPT §6.2).
 */
final class ApiRouteCases
{
    /**
     * @return array<string, callable(Organization, Organization, User): array<int, mixed>>
     */
    public static function all(): array
    {
        return [
            'api.forms.index' => fn (Organization $v): array => ['GET', '/api/v1/forms', [], 'victim'],
            'api.forms.version' => function (Organization $v): array {
                $version = self::victimVersion($v);

                return ['GET', "/api/v1/forms/{$version->form_id}/versions/{$version->id}"];
            },
            'api.sync.manifest' => fn (Organization $v): array => ['GET', '/api/v1/sync/manifest', [], 'victim'],
            'api.submissions.index' => fn (Organization $v): array => ['GET', '/api/v1/submissions', [], 'victim'],
            'api.submissions.show' => fn (Organization $v): array => ['GET', '/api/v1/submissions/'.self::victimSubmission($v)->uuid],
            'api.submissions.upsert' => function (Organization $v): array {
                $submission = self::victimSubmission($v);

                return ['PUT', '/api/v1/submissions/'.$submission->uuid, ['form_version_id' => $submission->form_version_id, 'data' => ['x' => 1]]];
            },
            'api.submissions.submit' => fn (Organization $v): array => [
                'POST', '/api/v1/submissions/'.self::victimSubmission($v)->uuid.'/submit', ['revision' => 1], 'attacker',
                ['Idempotency-Key' => (string) Str::uuid()],
            ],
            'api.attachments.store' => fn (Organization $v): array => ['POST', '/api/v1/submissions/'.self::victimSubmission($v)->uuid.'/attachments', []],
            'api.attachments.show' => function (Organization $v): array {
                $attachment = app(TenantContext::class)->run($v, fn () => Attachment::factory()->create(['submission_uuid' => self::victimSubmission($v)->uuid]));

                return ['GET', "/api/v1/submissions/{$attachment->submission_uuid}/attachments/{$attachment->uuid}"];
            },
            'api.submissions.document' => function (Organization $v): array {
                $rendition = app(TenantContext::class)->run($v, fn () => Rendition::factory()->create(['submission_uuid' => self::victimSubmission($v)->uuid]));

                return ['GET', "/api/v1/submissions/{$rendition->submission_uuid}/document"];
            },
            'api.submissions.send' => function (Organization $v): array {
                $submission = self::victimSubmission($v);
                app(TenantContext::class)->run($v, fn () => $submission->forceFill(['status' => 'submitted', 'number' => 'X-1'])->save());

                return ['POST', "/api/v1/submissions/{$submission->uuid}/send", ['email' => 'victim@example.com'], 'attacker',
                    ['Idempotency-Key' => (string) Str::uuid()]];
            },
        ];
    }

    private static function victimVersion(Organization $victim): FormVersion
    {
        return app(TenantContext::class)->run($victim, function (): FormVersion {
            $form = Form::factory()->create();

            return FormVersion::factory()->create(['form_id' => $form->id]);
        });
    }

    private static function victimSubmission(Organization $victim): Submission
    {
        $version = self::victimVersion($victim);

        return app(TenantContext::class)->run($victim, fn (): Submission => Submission::factory()->create([
            'form_version_id' => $version->id, 'form_id' => $version->form_id,
        ]));
    }
}
