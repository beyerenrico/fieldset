<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\CaddyfileRenderer;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\State\InstanceSettings;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\DomainFixtures;

/*
 * Plan U12 (KTD13; ADR 0080): what the renderer makes of the mail modes. Capture mode tells the app roles
 * (FIELDSET_MAIL_CAPTURE, for the banner and the health check); SMTP with the catcher kept renders Mailpit read-only (its
 * SMTP listener on the container's loopback) and keeps its UI, behind the edge in domain mode; a removed catcher
 * leaves neither service, env nor `/_mail/` route.
 */

it('marks capture mode for app, queue and scheduler only', function (): void {
    $rendered = ComposeFixtures::render();

    foreach (['app', 'queue', 'scheduler'] as $role) {
        expect($rendered->environments[$role])->toMatchArray([ServiceEnvironments::CAPTURE_ENV => 'true', 'MAIL_HOST' => 'mailpit']);
    }
    expect($rendered->environments['mailpit'])->not->toHaveKey('MP_SMTP_BIND_ADDR')
        ->and($rendered->environments['postgres'])->not->toHaveKey(ServiceEnvironments::CAPTURE_ENV);
});

it('keeps the catcher read-only after the switch to SMTP', function (): void {
    $state = ComposeFixtures::state([...ComposeFixtures::smtpSettings(), 'mail_catcher' => InstanceSettings::CATCHER_KEPT]);
    $rendered = ComposeFixtures::render([...ComposeFixtures::smtpSettings(), 'mail_catcher' => InstanceSettings::CATCHER_KEPT]);
    $settings = InstanceSettings::of($state);

    expect($rendered->services())->toContain(MailpitService::NAME)
        ->and($rendered->environments['mailpit'])->toMatchArray(['MP_SMTP_BIND_ADDR' => ServiceEnvironments::MAILPIT_KEPT_SMTP_BIND])
        ->and($rendered->service(MailpitService::NAME)['ports'] ?? [])->not->toBe([])
        ->and($rendered->environments['app'])->toMatchArray(['MAIL_HOST' => 'smtp.example.com'])
        ->and($rendered->environments['app'])->not->toHaveKey(ServiceEnvironments::CAPTURE_ENV)
        ->and($settings->keepsCatcher())->toBeTrue()
        ->and($settings->runsCatcher())->toBeTrue()
        ->and($settings->catcherUrl())->toBe('http://localhost:8025');
});

it('drops the catcher with SMTP and no kept catcher', function (): void {
    $rendered = ComposeFixtures::render(ComposeFixtures::smtpSettings());

    expect($rendered->services())->not->toContain(MailpitService::NAME)
        ->and(InstanceSettings::of(ComposeFixtures::state(ComposeFixtures::smtpSettings()))->catcherUrl())->toBeNull();
});

it('keeps /_mail/ behind the edge in domain mode while the catcher is kept, and drops it once removed', function (): void {
    $kept = DomainFixtures::render([...ComposeFixtures::smtpSettings(), 'mail_catcher' => InstanceSettings::CATCHER_KEPT]);
    $removed = DomainFixtures::render(ComposeFixtures::smtpSettings());

    expect($kept->files['edge/Caddyfile'])->toContain('/_mail/*')
        ->and($kept->environments['edge'])->toHaveKey(CaddyfileRenderer::AUTHORIZATION_ENV)
        ->and($kept->environments['mailpit'])->toMatchArray(['MP_SMTP_BIND_ADDR' => ServiceEnvironments::MAILPIT_KEPT_SMTP_BIND, 'MP_WEBROOT' => '/_mail/'])
        ->and($removed->files['edge/Caddyfile'])->not->toContain('_mail')
        ->and($removed->environments['edge'])->toBe([]);
});

it('treats a kept catcher setting as nothing in capture mode', function (): void {
    $settings = InstanceSettings::of(ComposeFixtures::state(['mail_catcher' => InstanceSettings::CATCHER_KEPT]));

    expect($settings->keepsCatcher())->toBeFalse()->and($settings->runsCatcher())->toBeTrue();
});
