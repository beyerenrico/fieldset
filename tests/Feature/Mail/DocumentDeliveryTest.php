<?php

declare(strict_types=1);

use App\Domain\Audit\Models\Activity;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Actions\QueueMail;
use App\Domain\Mail\Actions\SendDocuments;
use App\Domain\Mail\Delivery\DocumentDelivery;
use App\Domain\Mail\Delivery\DocumentFile;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Models\DocumentLink;
use App\Domain\Mail\Models\MailMessage;
use App\Domain\Mail\Templates\MailTemplate;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Mail\MailFixtures;

beforeEach(function (): void {
    MailFixtures::fakeDisk();
    $this->org = MailFixtures::organization();
    $this->delivery = app(DocumentDelivery::class);
});

/** Sends a document mail and returns the plain URL token of its first link, if any. */
function mailDocuments(Organization $org, DocumentFile ...$documents): ?string
{
    $plan = app(DocumentDelivery::class)->plan(...$documents);
    $template = new MailTemplate('Your document {{document.name}}', 'Please find {{document.name}} here: {{document.link}}');
    app(QueueMail::class)->handle(new BrandedMail($org->id, $template, [], $plan), 'customer@example.com', 'Customer', $documents[0]->submissionUuid);

    return isset($plan->links[0]) ? Str::afterLast($plan->links[0]->url, '/') : null;
}

it('attaches documents up to the size limit', function (): void {
    config(['messaging.attachment_max_bytes' => 2048]);

    expect(mailDocuments($this->org, MailFixtures::document(2048)))->toBeNull();

    $email = MailFixtures::lastSentEmail();
    $attachment = $email->getAttachments()[0];
    expect($email->getAttachments())->toHaveCount(1)
        ->and($attachment->getFilename())->toBe('LS-2026-0001.pdf')
        ->and($attachment->getMediaType().'/'.$attachment->getMediaSubtype())->toBe('application/pdf')
        ->and((string) $email->getHtmlBody())->toContain('here: attachment &quot;LS-2026-0001.pdf&quot;')
        ->and(DocumentLink::query()->count())->toBe(0);
});

it('sends larger documents as an expiring link and stores only the token hash', function (): void {
    config(['messaging.attachment_max_bytes' => 1000]);

    $token = mailDocuments($this->org, MailFixtures::document(5000));

    $link = DocumentLink::query()->sole();
    $url = route('documents.link', ['token' => $token]);
    $email = MailFixtures::lastSentEmail();
    expect($token)->toHaveLength(DocumentDelivery::TOKEN_LENGTH)
        ->and($link->token_hash)->toBe(hash('sha256', (string) $token))
        ->and(json_encode(DB::table('document_links')->first()))->not->toContain((string) $token)
        ->and($link->expires_at->diffInDays(now()->addDays(14), true))->toBeLessThan(0.01)
        ->and($email->getAttachments())->toBe([])
        ->and((string) $email->getHtmlBody())->toContain('href="'.$url.'"')->toContain('Download LS-2026-0001.pdf')
        ->and((string) $email->getTextBody())->toContain($url);
});

it('attaches within the total budget and links the rest', function (): void {
    config(['messaging.attachment_max_bytes' => 1000, 'messaging.links.ttl_days' => 3]);

    $plan = $this->delivery->plan(MailFixtures::document(600, 'a.pdf'), MailFixtures::document(600, 'b.pdf'), MailFixtures::document(300, 'c.pdf'));

    expect(array_map(fn (DocumentFile $f): string => $f->filename, $plan->attachments))->toBe(['a.pdf', 'c.pdf'])
        ->and($plan->links)->toHaveCount(1)
        ->and($plan->links[0]->filename)->toBe('b.pdf')
        ->and($plan->links[0]->expiresAt->isSameDay(now()->addDays(3)))->toBeTrue();
});

it('streams a valid link, counts downloads and audits them on the submission', function (): void {
    config(['messaging.attachment_max_bytes' => 10]);
    $submission = Submission::factory()->create();
    $token = mailDocuments($this->org, MailFixtures::document(64, 'Report 1/2.pdf', $submission->uuid));
    app(TenantContext::class)->clear();

    $response = $this->get(route('documents.link', ['token' => $token]));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertDownload('Report 1-2.pdf');
    expect($response->streamedContent())->toBe(str_repeat('%', 64));
    app(TenantContext::class)->set($this->org);
    $audit = Activity::query()->where('event', 'document.downloaded')->sole();
    expect(DocumentLink::query()->sole()->download_count)->toBe(1)
        ->and($audit->subject_id)->toBe($submission->uuid)
        ->and($audit->organization_id)->toBe($this->org->id)
        ->and($audit->properties['filename'])->toBe('Report 1-2.pdf');
});

it('records "document sent" once when a mail with documents is delivered', function (): void {
    $submission = Submission::factory()->create();

    mailDocuments($this->org, MailFixtures::document(64, 'a.pdf', $submission->uuid));

    $audit = Activity::query()->where('event', 'document.sent')->sole();
    expect($audit->subject_id)->toBe($submission->uuid)
        ->and($audit->properties['mail_message_id'])->toBe(MailMessage::query()->sole()->id)
        ->and($audit->properties['attached'][0]['filename'])->toBe('a.pdf');
});

it('answers 410 Gone after expiry or when the file is gone', function (): void {
    config(['messaging.attachment_max_bytes' => 10]);
    $token = mailDocuments($this->org, MailFixtures::document(64));
    $url = route('documents.link', ['token' => $token]);

    $this->travel(15)->days();
    $this->get($url)->assertStatus(410);

    $this->travelBack();
    DocumentLink::query()->update(['path' => 'missing.pdf']);
    $this->get($url)->assertStatus(410);
    expect(Activity::query()->where('event', 'document.downloaded')->count())->toBe(0);
});

it('answers 404 for unknown or malformed tokens', function (): void {
    $this->get(route('documents.link', ['token' => Str::random(DocumentDelivery::TOKEN_LENGTH)]))->assertNotFound();
    $this->get('/documents/links/short')->assertNotFound();
});

it('rate-limits the public download route', function (): void {
    $url = route('documents.link', ['token' => Str::random(DocumentDelivery::TOKEN_LENGTH)]);
    $limit = (int) config('messaging.links.rate_limit');

    for ($i = 0; $i < $limit; $i++) {
        $this->get($url)->assertNotFound();
    }

    $this->get($url)->assertTooManyRequests();
});

it('prunes links long after expiry across organizations', function (): void {
    $old = DocumentLink::factory()->create(['expires_at' => now()->subDays(91)]);
    $recent = DocumentLink::factory()->create(['expires_at' => now()->subDays(10)]);
    app(TenantContext::class)->clear();

    $this->artisan('model:prune', ['--model' => [DocumentLink::class]])->assertSuccessful();

    app(TenantContext::class)->set($this->org);
    expect(DocumentLink::query()->pluck('id')->all())->toBe([$recent->id])->and($old->id)->not->toBe($recent->id);
});

it('sends documents for a submission with its tokens and logs the message against it', function (): void {
    $submission = Submission::factory()->create(['number' => 'LS-2026-0042']);
    $template = new MailTemplate('Delivery note {{submission.number}} – {{form.name}}', 'Hello {{recipient.name}}, see {{document.link}}.');

    $message = app(SendDocuments::class)->handle($template, 'carla@example.com', 'Carla', $submission, MailFixtures::document(64, 'LS-2026-0042.pdf', $submission->uuid));

    $email = MailFixtures::lastSentEmail();
    expect($message->fresh())->submission_uuid->toBe($submission->uuid)->subject->toBe('Delivery note LS-2026-0042 – '.$submission->form->name)
        ->and($email->getSubject())->toBe($message->subject)
        ->and((string) $email->getHtmlBody())->toContain('Hello Carla, see attachment &quot;LS-2026-0042.pdf&quot;.')
        ->and($email->getAttachments()[0]->getFilename())->toBe('LS-2026-0042.pdf');
});
