<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Mail\Models\MailSetting;
use App\Filament\Admin\CaptureModeBanner;

require_once __DIR__.'/../Platform/Support.php';

/*
 * Plan U12, AE1 as amended by R59 (R24, KTD14; ADR 0080): while the instance mailer captures mail, every admin page of
 * an organization says so. Organizations on the instance mailer get the full banner, organizations with their own SMTP
 * server the narrower one (invitations and password resets); operators see the `fsa config set mail` command,
 * everyone else "ask your instance owner", members who manage the organization the Mail settings link. Once SMTP is
 * configured (fsa drops FSA_MAIL_CAPTURE) nobody sees a banner.
 */

beforeEach(function (): void {
    config(['mail.capture' => true]);
});

function bannerOwnSmtp(Organization $organization): void
{
    app(TenantContext::class)->run($organization, fn (): MailSetting => MailSetting::query()->create([
        'smtp_enabled' => true, 'smtp_host' => 'smtp.acme.test', 'smtp_port' => 587, 'smtp_from_address' => 'forms@acme.test',
    ]));
}

function bannerPage(User $user, Organization $organization): string
{
    return test()->actingAs($user)->get('/admin/'.$organization->slug)->assertOk()->getContent() ?: '';
}

it('shows the full banner to an admin of an organization on the instance mailer, with "ask your instance owner" and Mail settings', function (): void {
    $organization = makeOrganization(name: 'Instance Mailer Org');

    $page = bannerPage(ownerOf($organization), $organization);

    expect($page)->toContain('data-capture-banner="full"')
        ->toContain('role="region"')->toContain('aria-label="'.__('capture.label').'"')
        ->toContain(__('capture.full.title'))->toContain(__('capture.ask_owner'))
        ->toContain('data-capture-banner-settings')->toContain('/admin/'.$organization->slug.'/mail-settings')
        ->not->toContain('data-capture-banner-command')->not->toContain('fsa config set mail');
});

it('shows the narrower banner to an organization with its own SMTP server, without the Mail settings link (R59)', function (): void {
    $organization = makeOrganization(name: 'Own SMTP Org');
    bannerOwnSmtp($organization);

    $page = bannerPage(ownerOf($organization), $organization);

    expect($page)->toContain('data-capture-banner="narrow"')
        ->toContain(__('capture.narrow.title'))->toContain(__('capture.ask_owner'))
        ->not->toContain(__('capture.full.title'))->not->toContain('data-capture-banner-settings');
});

it('gives each organization its own variant (AE1)', function (): void {
    $a = makeOrganization(name: 'Org A');
    $b = makeOrganization(name: 'Org B');
    bannerOwnSmtp($b);

    expect(bannerPage(ownerOf($a), $a))->toContain('data-capture-banner="full"')
        ->and(bannerPage(ownerOf($b), $b))->toContain('data-capture-banner="narrow"');
});

it('shows a platform operator the fsa command instead of "ask your instance owner"', function (): void {
    $organization = makeOrganization(name: 'Operator Org');
    $operator = platformOperatorGrant(ownerOf($organization));

    $page = bannerPage($operator, $organization);

    expect($page)->toContain('data-capture-banner="full"')
        ->toContain('data-capture-banner-command')->toContain(e(CaptureModeBanner::COMMAND))
        ->not->toContain(__('capture.ask_owner'));
});

it('shows members who cannot manage the organization the banner without the Mail settings link', function (): void {
    $organization = makeOrganization(name: 'Designer Org');

    $page = bannerPage(memberOf($organization, SystemRole::Designer), $organization);

    expect($page)->toContain('data-capture-banner="full"')->toContain(__('capture.ask_owner'))->not->toContain('data-capture-banner-settings');
});

it('shows no banner once SMTP is configured (AE1: after fsa config set mail)', function (): void {
    $a = makeOrganization(name: 'Org A');
    $b = makeOrganization(name: 'Org B');
    bannerOwnSmtp($b);
    config(['mail.capture' => false]);

    expect(bannerPage(ownerOf($a), $a))->not->toContain('data-capture-banner')
        ->and(bannerPage(ownerOf($b), $b))->not->toContain('data-capture-banner');
});

it('shows nothing on the login page and outside an organization', function (): void {
    $this->get('/admin/login')->assertOk()->assertDontSee('data-capture-banner', false);

    expect(app(CaptureModeBanner::class)->render()->toHtml())->toBe('');
});

it('speaks German to a German-speaking member', function (): void {
    $organization = makeOrganization(owner: User::factory()->create(['locale' => 'de']), name: 'Deutsche Org');

    $page = bannerPage(ownerOf($organization), $organization);

    expect($page)->toContain(e(trans('capture.full.title', [], 'de')))->toContain(e(trans('capture.ask_owner', [], 'de')));
});

it('keeps the banner readable: white on dark blue, a named region, not sticky', function (): void {
    $organization = makeOrganization(name: 'Contrast Org');

    $page = bannerPage(ownerOf($organization), $organization);

    expect($page)->toContain('background:#1e3a8a;color:#fff')->not->toMatch('/data-capture-banner="full"[^>]*position:sticky/');
});
