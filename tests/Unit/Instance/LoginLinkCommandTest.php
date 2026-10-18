<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\State\InstanceState;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U5 / M9-T08: `fsa login-link <email>` (R28, KTD21, KTD25): the app-side one-off issues the link, fsa hands it
 * out only under `secrets`, in --secrets-file or through the browser hand-over (ADR 0056, ADR 0070).
 */

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
});

afterEach(fn () => TemporaryInstance::cleanup());

it('issues the link through a socket-less one-off and returns it only under secrets', function (): void {
    [$code, $json] = $this->fixture->run('fsa:login-link', ['email' => ' Owner@Example.com ']);
    $call = $this->fixture->oneOffCalls('identity:login-link')[0];

    expect($code)->toBe(0)
        ->and($json['changed'])->toBeTrue()
        ->and($json['data'])->toBe(['email' => 'owner@example.com', 'kind' => 'admin', 'sign_in_link' => ['expires_at' => '2026-10-18T10:15:00Z', 'delivered' => 'secrets']])
        ->and($json['secrets'])->toBe([['name' => 'sign_in_link', 'value' => InstanceCommandFixture::ADMIN_LINK, 'expires_at' => '2026-10-18T10:15:00Z']])
        ->and($call['service'])->toBe('app')
        ->and($call['command'])->toBe(['php', 'artisan', 'identity:login-link', '--actor-uid='.posix_getuid(), '--json', '--', 'owner@example.com']);
});

it('writes the link to --secrets-file, returns it as JSON on a desktop, and hands it to the browser with --open', function (): void {
    $file = $this->fixture->directory->root.'/link.json';
    [, $filed] = $this->fixture->run('fsa:login-link', ['email' => 'owner@example.com', '--secrets-file' => $file]);
    $this->fixture->facts(['desktop' => true, 'tty' => true, 'openUrlFile' => '.fsa-open-url-00000000000000000000000000000000']);
    [, $json] = $this->fixture->run('fsa:login-link', ['email' => 'owner@example.com']);
    [, $browser] = $this->fixture->run('fsa:login-link', ['email' => 'owner@example.com', '--open' => true]);

    expect($filed['secrets'][0])->not->toHaveKey('value')
        ->and(file_get_contents($file))->toContain(InstanceCommandFixture::ADMIN_LINK)
        ->and($json['secrets'][0]['value'])->toBe(InstanceCommandFixture::ADMIN_LINK)
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets')
        ->and($browser['secrets'])->toBe([])
        ->and($browser['data']['sign_in_link']['delivered'])->toBe('browser')
        ->and($browser['data']['sign_in_link']['hint'])->toBe('If no browser opened, run `fsa login-link owner@example.com --secrets-file <file>`.')
        ->and(json_encode($browser))->not->toContain('admin-token');
});

it('ignores --open without a desktop session and returns the link under secrets', function (): void {
    [, $json] = $this->fixture->run('fsa:login-link', ['email' => 'owner@example.com', '--open' => true]);

    expect($json['data']['sign_in_link']['delivered'])->toBe('secrets')
        ->and($json['secrets'][0]['value'])->toBe(InstanceCommandFixture::ADMIN_LINK);
});

it('maps the app errors to exit codes and never leaks a link', function (string $error, int $exit): void {
    $this->fixture->oneOffs['identity:login-link'] = InstanceCommandFixture::oneOffJson(['command' => 'identity:login-link', 'status' => 'failed', 'errors' => [['code' => $error, 'message' => "Refused: {$error}.", 'hint' => null, 'retryable' => false]]]);

    [$code, $json] = $this->fixture->run('fsa:login-link', ['email' => 'someone@example.com']);

    expect($code)->toBe($exit)->and($json['errors'][0]['code'])->toBe($error)->and($json['secrets'])->toBe([]);
})->with([
    ['user_not_found', 2], ['not_admin', 2], ['inactive', 2], ['sso_required', 2], ['rate_limited', 9],
]);

it('exits 4 (retryable) when the one-off prints no result, and 2 for a malformed e-mail or an instance that is not installed', function (): void {
    $this->fixture->oneOffs['identity:login-link'] = new ProcessOutcome(1, '', 'could not connect to server');
    [$brokenCode, $broken] = $this->fixture->run('fsa:login-link', ['email' => 'owner@example.com']);
    $this->fixture->oneOffs['identity:login-link'] = new ProcessOutcome(124, '', '', true);
    [$slowCode, $slow] = $this->fixture->run('fsa:login-link', ['email' => 'owner@example.com']);
    $this->fixture->oneOffs['identity:login-link'] = InstanceCommandFixture::oneOffJson(['command' => 'identity:login-link', 'status' => 'failed', 'errors' => [['code' => 'internal_error', 'message' => 'Unexpected.', 'hint' => null, 'retryable' => false]]]);
    [$appCode] = $this->fixture->run('fsa:login-link', ['email' => 'owner@example.com']);
    [$badCode] = $this->fixture->run('fsa:login-link', ['email' => 'not-an-email']);

    $fresh = new InstanceCommandFixture;
    $fresh->directory->saveState(InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $fresh->directory->root, 1, 1, 1));
    [$notInstalledCode, $notInstalled] = $fresh->run('fsa:login-link', ['email' => 'owner@example.com']);

    expect($brokenCode)->toBe(4)
        ->and($broken['errors'][0]['code'])->toBe('one_off_failed')
        ->and($broken['errors'][0]['retryable'])->toBeTrue()
        ->and($broken['data']['output'])->toContain('could not connect')
        ->and($slowCode)->toBe(4)
        ->and($slow['errors'][0]['code'])->toBe('one_off_timeout')
        ->and($appCode)->toBe(1)
        ->and($badCode)->toBe(2)
        ->and($notInstalledCode)->toBe(2)
        ->and($notInstalled['errors'][0]['code'])->toBe('not_installed');
});
