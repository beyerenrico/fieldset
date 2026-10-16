<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Submissions\Models\Attachment;
use App\Domain\Submissions\Models\Submission;
use App\Http\Controllers\Admin\AttachmentDownloadController;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Admin\Submissions\InboxScenario;
use Tests\Support\FieldAppScenario;

/* M3-T09: thumbnails and files in the inbox go through a signed, authorized admin route (§11, §8.6). */

const INBOX_TEST_DISK = 'admin-inbox-test'; // own fake disk: Storage::fake('local') races with parallel runs

beforeEach(function (): void {
    Storage::fake(INBOX_TEST_DISK);
    $this->s = InboxScenario::make();
    $this->png = FieldAppScenario::png();
    $this->attachment = attachmentOf($this->s->org, $this->s->workerReport, $this->png);
    $this->url = AttachmentDownloadController::signedUrl($this->s->org, $this->attachment);
});

function attachmentOf(Organization $org, Submission $submission, string $bytes, string $mime = 'image/png'): Attachment
{
    return app(TenantContext::class)->run($org, function () use ($submission, $bytes, $mime): Attachment {
        $path = 'attachments/'.$submission->uuid.'/'.FieldAppScenario::uuid();
        Storage::disk(INBOX_TEST_DISK)->put($path, $bytes);

        return Attachment::factory()->create([
            'submission_uuid' => $submission->uuid, 'kind' => 'signature', 'element_key' => 'customer_signature',
            'original_name' => 'signature.png', 'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'disk' => INBOX_TEST_DISK, 'path' => $path,
        ]);
    });
}

it('streams an image inline to an owner with hardened headers', function (): void {
    $response = $this->actingAs($this->s->owner)->get($this->url);

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($response->streamedContent())->toBe($this->png)
        ->and((string) $response->headers->get('Content-Disposition'))->toStartWith('inline');
});

it('downloads non-image files as attachments', function (): void {
    $pdf = attachmentOf($this->s->org, $this->s->workerReport, '%PDF-1.4 test', 'application/pdf');

    $response = $this->actingAs($this->s->owner)->get(AttachmentDownloadController::signedUrl($this->s->org, $pdf));

    $response->assertOk();
    expect((string) $response->headers->get('Content-Disposition'))->toStartWith('attachment');
});

it('requires a valid, unexpired signature and a session', function (): void {
    $unsigned = route('admin.attachments.show', ['organization' => $this->s->org->slug, 'attachment' => $this->attachment->uuid]);

    $this->get($this->url)->assertRedirect('/admin/login');
    $this->actingAs($this->s->owner)->get($unsigned)->assertForbidden();
    $this->actingAs($this->s->owner)->get($this->url.'x')->assertForbidden();

    $this->travel(AttachmentDownloadController::LINK_MINUTES + 1)->minutes();
    $this->actingAs($this->s->owner)->get($this->url)->assertForbidden();
});

it('follows the submission visibility of the role', function (SystemRole $role, bool $allowed): void {
    $user = memberOf($this->s->org, $role);

    $response = $this->actingAs($user)->get($this->url);

    $allowed ? $response->assertOk() : $response->assertNotFound();
})->with([
    'admin' => [SystemRole::Admin, true],
    'viewer' => [SystemRole::Viewer, true],
    'designer of another form' => [SystemRole::Designer, false],
    'reviewer without group' => [SystemRole::Reviewer, false],
    'field worker (no admin access)' => [SystemRole::FieldWorker, false],
]);

it('serves the designer who created the form and a reviewer of the group', function (): void {
    $delivery = attachmentOf($this->s->org, $this->s->workerDelivery, $this->png);
    $reviewer = memberOf($this->s->org, SystemRole::Reviewer);
    $this->s->shareGroup($reviewer, $this->s->worker);

    $this->actingAs($this->s->designer)->get(AttachmentDownloadController::signedUrl($this->s->org, $delivery))->assertOk();
    $this->actingAs($reviewer)->get($this->url)->assertOk();
});

it('never serves attachments across organizations', function (): void {
    $other = InboxScenario::make();
    $attacker = $other->owner;

    // Victim's organization in the URL: the attacker is no member.
    $this->actingAs($attacker)->get($this->url)->assertNotFound();

    // Attacker's own organization with the victim's attachment id: scoped lookup finds nothing.
    $this->actingAs($attacker)->get(URL::temporarySignedRoute('admin.attachments.show', now()->addMinutes(5), [
        'organization' => $other->org->slug, 'attachment' => $this->attachment->uuid,
    ]))->assertNotFound();
});

it('answers 404 for unknown organizations, malformed and unknown ids', function (): void {
    $signed = fn (string $org, string $id): string => URL::temporarySignedRoute('admin.attachments.show', now()->addMinutes(5), ['organization' => $org, 'attachment' => $id]);

    $this->actingAs($this->s->owner)->get($signed('no-such-org', $this->attachment->uuid))->assertNotFound();
    $this->actingAs($this->s->owner)->get($signed($this->s->org->slug, 'not-a-uuid'))->assertNotFound();
    $this->actingAs($this->s->owner)->get($signed($this->s->org->slug, FieldAppScenario::uuid()))->assertNotFound();
});

it('refuses deactivated members', function (): void {
    $viewer = memberOf($this->s->org, SystemRole::Viewer);
    $viewer->forceFill(['status' => UserStatus::Deactivated])->save();

    $this->actingAs($viewer)->get($this->url)->assertNotFound();
});
