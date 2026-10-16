<?php

declare(strict_types=1);

use App\Domain\Documents\Branding\Branding;
use App\Domain\Documents\Branding\ColorContrast;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Actions\QueueMail;
use App\Domain\Mail\Delivery\DocumentDelivery;
use App\Domain\Mail\Mailables\BrandedMail;
use App\Domain\Mail\Mailables\TestMail;
use App\Domain\Mail\Templates\MailTemplate;
use App\Domain\Mail\Templates\MailTokens;
use App\Domain\Submissions\Models\Submission;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Feature\Mail\MailFixtures;

beforeEach(function (): void {
    MailFixtures::fakeDisk();
    $this->org = MailFixtures::organization('Acme GmbH');
    $this->org->update([
        'branding' => ['primary_color' => '#0F766E', 'accent_color' => '#b45309', 'logo_path' => 'branding/logo.png'],
        'company_details' => ['legal_name' => 'Acme Service GmbH', 'address' => "Hauptstr. 1\n10115 Berlin", 'vat_id' => 'DE123456789', 'legal_footer' => 'Amtsgericht Berlin HRB 1'],
    ]);
});

it('renders the template in the organization branding with escaped token values', function (): void {
    $mail = new BrandedMail($this->org->id, new MailTemplate('Order {{submission.number}}', "# Hello {{recipient.name}}\n\nThanks for choosing **{{org.name}}**."), [
        'recipient.name' => '<b>Mallory</b>', 'submission.number' => 'LS-7',
    ]);

    $html = $mail->render();

    expect($html)->toContain('#0f766e')
        ->toContain('<h1>Hello &lt;b&gt;Mallory&lt;/b&gt;</h1>')
        ->toContain('<strong>Acme GmbH</strong>')
        ->toContain('Acme Service GmbH')
        ->toContain('Hauptstr. 1<br />')
        ->toContain('VAT ID: DE123456789')
        ->toContain('Amtsgericht Berlin HRB 1')
        ->not->toContain('<b>Mallory</b>')
        ->and($mail->subjectLine())->toBe('Order LS-7');
});

it('shows the company name instead of a logo when no usable logo is stored', function (): void {
    $html = new TestMail($this->org->id)->to('ann@example.com', 'Ann')->render();

    expect($html)->not->toContain('cid:')->toContain('Acme Service GmbH');
});

it('embeds the logo inline exactly once and references it by content id', function (): void {
    Storage::disk(MailFixtures::DISK)->put('branding/logo.png', base64_decode(MailFixtures::PNG));

    app(QueueMail::class)->handle(new TestMail($this->org->id), 'ann@example.com', 'Ann');

    $email = MailFixtures::lastSentEmail();
    $inline = array_values(array_filter($email->getAttachments(), fn (DataPart $part): bool => $part->hasContentId() && $part->getContentId() === BrandedMail::LOGO_CID));
    expect($inline)->toHaveCount(1)
        ->and($inline[0]->getMediaType().'/'.$inline[0]->getMediaSubtype())->toBe('image/png')
        ->and($inline[0]->getPreparedHeaders()->getHeaderBody('Content-Disposition'))->toBe('inline')
        ->and((string) $email->getHtmlBody())->toContain('src="cid:'.BrandedMail::LOGO_CID.'"')
        ->and((string) $email->getTextBody())->toContain('Hello Ann')->toContain('Acme Service GmbH');
});

it('ignores logos that are not mail-safe images', function (): void {
    Storage::disk(MailFixtures::DISK)->put('branding/logo.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

    app(QueueMail::class)->handle(new TestMail($this->org->id), 'ann@example.com');

    expect(MailFixtures::lastSentEmail()->getAttachments())->toBe([]);
});

it('provides submission, form and recipient tokens', function (): void {
    $submission = Submission::factory()->create(['number' => 'LS-2026-0042', 'submitted_at' => '2026-10-16 22:30:00']);
    $this->org->update(['timezone' => 'Europe/Berlin', 'locale' => 'de']);

    $tokens = MailTokens::for($this->org->fresh(), $submission->fresh(), 'Ann');

    expect($tokens)->toMatchArray([
        'org.name' => 'Acme GmbH', 'recipient.name' => 'Ann', 'submission.number' => 'LS-2026-0042',
        'submission.date' => '17.10.2026', 'form.name' => $submission->form->name,
    ])->and(array_keys($tokens))->each->toBeIn(MailTokens::AVAILABLE)
        ->and(app(TenantContext::class)->id())->toBe($this->org->id);
});

it('keeps link text and the download button readable with a light brand color', function (): void {
    config(['messaging.attachment_max_bytes' => 10]);
    $this->org->update(['branding' => ['primary_color' => '#facc15', 'accent_color' => '#facc15']]);
    $plan = app(DocumentDelivery::class)->plan(MailFixtures::document(5000));
    $branding = Branding::forOrganization($this->org->refresh());

    $html = new BrandedMail($this->org->id, new MailTemplate('Doc', 'See [our site](https://example.com).'), [], $plan)->render();

    expect($html)->toContain('.mail-body a { color: '.$branding->primaryText().'; }')
        ->toContain('background-color:#facc15;color:#111827;')
        ->and(ColorContrast::ratio($branding->primaryText(), '#ffffff'))->toBeGreaterThanOrEqual(ColorContrast::AA);
});
