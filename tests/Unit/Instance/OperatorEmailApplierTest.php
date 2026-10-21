<?php

declare(strict_types=1);

use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\OperationJournal;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U11/U12 (R32; ADR 0080): `fieldset config set operator-email <email>` grants the operator role in a socket-less
 * one-off, delivers the new account's first sign-in link only through `secrets` or --secrets-file, revokes the others
 * only with --revoke-previous (confirmed), and needs no render, journal or restart.
 */

beforeEach(function (): void {
    $this->config = new ConfigFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

it('grants the new operator in a one-off and hands the sign-in link over as a secret', function (): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('operator-email', 'New-Ops@Example.com');

    $call = $this->config->instance->oneOffCalls('instance:operator-email')[0];
    expect($code)->toBe(0, json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and($json['data'])->toMatchArray(['key' => 'operator-email', 'to' => 'new-ops@example.com', 'created' => true, 'granted' => true, 'revoked' => []])
        ->and($json['data']['sign_in_link'])->toMatchArray(['delivered' => 'secrets'])
        ->and($json['secrets'][0])->toMatchArray(['name' => 'sign_in_link', 'value' => InstanceCommandFixture::LINK])
        ->and(json_encode($json['data']))->not->toContain('/login-link#')
        ->and($call['command'])->toBe(['php', 'artisan', 'instance:operator-email', '--email=new-ops@example.com', '--actor-uid='.posix_getuid(), '--json'])
        ->and($this->config->methodsSince($mark))->not->toContain('composeUp')->not->toContain('composeStop')
        ->and($this->config->openJournal())->toBeNull()
        ->and(app(InstanceLock::class)->current())->toBeNull();
});

it('writes the link to --secrets-file instead of stdout', function (): void {
    $file = TemporaryInstance::path().'-operator.json';

    [$code, $json] = $this->config->set('operator-email', 'new-ops@example.com', ['--secrets-file' => $file]);

    expect($code)->toBe(0)
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets_file')
        ->and(json_encode($json))->not->toContain('/login-link#')
        ->and((string) file_get_contents($file))->toContain('/login-link#');
    @unlink($file);
});

it('needs confirmation to revoke the previous operators, and passes --revoke-previous on with --yes', function (): void {
    [$refused, $question] = $this->config->set('operator-email', 'new-ops@example.com', ['--revoke-previous' => true]);
    expect($refused)->toBe(6)->and($question['errors'][0]['code'])->toBe('confirmation_required')
        ->and($this->config->instance->oneOffCalls('instance:operator-email'))->toBe([]);

    $this->config->instance->oneOffs['instance:operator-email'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:operator-email', 'status' => 'ok', 'changed' => true,
        'data' => ['email' => 'new-ops@example.com', 'user_id' => 2, 'created' => false, 'granted' => true, 'revoked' => ['owner@example.com'], 'password_set' => true, 'login_url' => 'http://localhost:8080/platform/login']]);

    [$code, $json] = $this->config->set('operator-email', 'new-ops@example.com', ['--revoke-previous' => true, '--yes' => true]);

    expect($code)->toBe(0)
        ->and($json['data'])->toMatchArray(['revoked' => ['owner@example.com'], 'created' => false])
        ->and($json['data'])->not->toHaveKey('sign_in_link')
        ->and($json['secrets'])->toBe([])
        ->and($this->config->instance->oneOffCalls('instance:operator-email')[0]['command'])->toContain('--revoke-previous');
});

it('reports an address that is already the operator as unchanged', function (): void {
    $this->config->instance->oneOffs['instance:operator-email'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:operator-email', 'status' => 'ok',
        'data' => ['email' => 'owner@example.com', 'user_id' => 1, 'created' => false, 'granted' => false, 'revoked' => [], 'password_set' => true]]);

    [$code, $json] = $this->config->set('operator-email', 'owner@example.com');

    expect($code)->toBe(0)->and($json['changed'])->toBeFalse();
});

it('reports a link issued on a repeat as a change, with a warning', function (): void {
    $this->config->instance->oneOffs['instance:operator-email'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:operator-email', 'status' => 'ok', 'changed' => true,
        'data' => ['email' => 'new-ops@example.com', 'user_id' => 2, 'created' => false, 'granted' => false, 'revoked' => [], 'password_set' => false, 'link' => 'issued', 'link_expires_at' => '2026-10-18T11:00:00Z'],
        'secrets' => [['name' => 'sign_in_link', 'value' => InstanceCommandFixture::LINK, 'expires_at' => '2026-10-18T11:00:00Z']]]);

    [$code, $json] = $this->config->set('operator-email', 'new-ops@example.com');

    expect($code)->toBe(0, json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets')
        ->and(collect($json['warnings'])->pluck('code')->all())->toBe(['sign_in_link_issued']);
});

it('keeps a delivered link that can still be used: changed: false, a warning with its expiry', function (): void {
    $this->config->instance->oneOffs['instance:operator-email'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:operator-email', 'status' => 'ok',
        'data' => ['email' => 'new-ops@example.com', 'user_id' => 2, 'created' => false, 'granted' => false, 'revoked' => [], 'password_set' => false, 'link' => 'outstanding', 'link_expires_at' => '2026-10-18T11:00:00Z']]);

    [$code, $json] = $this->config->set('operator-email', 'new-ops@example.com');

    expect($code)->toBe(0, json_encode($json))
        ->and($json['changed'])->toBeFalse()
        ->and($json['secrets'])->toBe([])
        ->and($json['data'])->not->toHaveKey('sign_in_link')
        ->and($json['warnings'][0]['code'])->toBe('sign_in_link_outstanding')
        ->and($json['warnings'][0]['message'])->toContain('2026-10-18T11:00:00Z')
        ->and($json['warnings'][0]['hint'])->toContain('fieldset config set operator-email new-ops@example.com');
});

it('says when to retry a rate-limited link', function (): void {
    $this->config->instance->oneOffs['instance:operator-email'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:operator-email', 'status' => 'failed',
        'errors' => [['code' => 'rate_limited', 'message' => 'Too many sign-in links for this account. Try again in 60 seconds.', 'hint' => null, 'retryable' => true]]]);

    [$code, $json] = $this->config->set('operator-email', 'new-ops@example.com');

    expect($code)->toBe(9)->and($json['data']['retry_after'])->toBe(60);
});

it('refuses while another operation is unfinished (exit 9), before the one-off, and leaves the lock free', function (): void {
    $journal = new OperationJournal($this->config->instance->directory);
    $journal->begin('demo', posix_getuid(), ['rotate' => false]);
    $journal->startPhase('demo');

    [$code, $json] = $this->config->set('operator-email', 'new-ops@example.com');

    expect($code)->toBe(9, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($json['errors'][0]['hint'])->toContain('fieldset demo')
        ->and($this->config->instance->oneOffCalls('instance:operator-email'))->toBe([])
        ->and(app(InstanceLock::class)->current())->toBeNull();
});

it('maps app refusals to exit codes', function (string $error, int $exit, bool $retryable): void {
    $this->config->instance->oneOffs['instance:operator-email'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:operator-email', 'status' => 'failed',
        'errors' => [['code' => $error, 'message' => 'refused', 'hint' => null, 'retryable' => $retryable]]]);

    [$code, $json] = $this->config->set('operator-email', 'new-ops@example.com');

    expect($code)->toBe($exit)->and($json['errors'][0])->toMatchArray(['code' => $error, 'retryable' => $retryable]);
})->with([
    'sso' => ['sso_required', 2, false],
    'inactive' => ['inactive', 2, false],
    'rate limited' => ['rate_limited', 9, true],
    'app down' => ['one_off_failed', 4, true],
]);

it('refuses an invalid address and options of other keys with exit 2', function (string $value, array $options): void {
    [$code, $json] = $this->config->set('operator-email', $value, $options);

    expect($code)->toBe(2)->and($this->config->instance->oneOffCalls('instance:operator-email'))->toBe([]);
})->with([
    'no address' => ['ops', []],
    'smtp flag' => ['ops@example.com', ['--smtp-host' => 'smtp.example.com']],
    'acme flag' => ['ops@example.com', ['--acme-email' => 'ops@example.com']],
]);

it('refuses --revoke-previous on other keys', function (): void {
    [$code, $json] = $this->config->set('port', '8090', ['--revoke-previous' => true]);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('invalid_flag');
});

it('needs an installed instance', function (): void {
    $fixture = new InstanceCommandFixture;

    [$code, $json] = $fixture->run('fieldset:config', ['action' => 'set', 'key' => 'operator-email', 'value' => 'ops@example.com']);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('not_installed');
});
