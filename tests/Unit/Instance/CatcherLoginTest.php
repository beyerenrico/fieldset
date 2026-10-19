<?php

declare(strict_types=1);

use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\ConfigRollout;
use App\Domain\Instance\Config\MailOptions;
use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\Console\ConfigCommand;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSecrets;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U12 (R23, R50, ADR 0080): the catcher UI's generated password reaches an operator only through
 * `fsa config show --catcher-login`, as the one-time secret `catcher_password` (stdout `secrets` or --secrets-file),
 * and only where the UI is a loopback port; domain mode signs in through the edge. Also: a `config set mail` killed
 * during apply resumes with the previous credentials kept beside the env files.
 */

afterEach(fn () => TemporaryInstance::cleanup());

function catcherPassword(ConfigFixture $config): string
{
    $auth = new EnvFiles($config->instance->directory)->read('mailpit')['MP_UI_AUTH'];

    return substr($auth, strlen(InstanceSecrets::CATCHER_USER.':'));
}

it('adds the catcher password to secrets, nowhere else', function (): void {
    $config = new ConfigFixture;
    $password = catcherPassword($config);

    [$code, $json] = $config->instance->run('fsa:config', ['action' => 'show', '--catcher-login' => true]);

    expect($code)->toBe(0)
        ->and($json['data']['catcher_login'])->toBe(['url' => 'http://localhost:8025', 'user' => 'fsa', 'secret' => 'catcher_password'])
        ->and($json['secrets'])->toBe([['name' => 'catcher_password', 'value' => $password, 'expires_at' => null]])
        ->and(json_encode($json['data']))->not->toContain($password);
});

it('writes the catcher password to --secrets-file', function (): void {
    $config = new ConfigFixture;
    $file = TemporaryInstance::path().'-catcher.json';

    [$code, $json] = $config->instance->run('fsa:config', ['action' => 'show', '--catcher-login' => true, '--secrets-file' => $file]);

    expect($code)->toBe(0)
        ->and(json_encode($json))->not->toContain(catcherPassword($config))
        ->and((string) file_get_contents($file))->toContain(catcherPassword($config));
    @unlink($file);
});

it('refuses the catcher login in domain mode and without a catcher', function (): void {
    $domain = new ConfigFixture;
    $domain->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);
    [$code, $json] = $domain->instance->run('fsa:config', ['action' => 'show', '--catcher-login' => true]);
    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('catcher_login_not_needed')->and($json['secrets'])->toBe([]);

    $removed = new ConfigFixture;
    app()->instance(ConfigCommand::STDIN, fn (): string => 'pw');
    $removed->set('mail', 'smtp', ['--smtp-host' => 'smtp.example.com', '--remove-catcher' => true]);
    [$none, $noneJson] = $removed->instance->run('fsa:config', ['action' => 'show', '--catcher-login' => true]);
    expect($none)->toBe(2)->and($noneJson['errors'][0]['code'])->toBe('no_catcher');
});

it('resumes a mail change killed during apply and reverts to the credentials kept beside the env files', function (): void {
    $config = new ConfigFixture;
    app()->instance(ConfigCommand::STDIN, fn (): string => 'first-secret');
    $config->set('mail', 'smtp', ['--smtp-host' => 'smtp.example.com', '--smtp-username' => 'first-user', '--password-stdin' => true]);
    $directory = $config->instance->directory;
    $request = new ConfigRequest('mail', 'smtp', mail: new MailOptions(smtpHost: 'relay.example.net', credentials: true), credentials: new SmtpCredentials('second-user', 'second-secret'));
    $config->interruptedDuringApply($request);
    // The killed run had kept the previous credentials and written the new ones.
    $env = new EnvFiles($directory);
    $directory->write(ConfigRollout::CREDENTIALS_STASH, EnvFiles::encode(['MAIL_USERNAME' => 'first-user', 'MAIL_PASSWORD' => 'first-secret']), 0600);
    $env->write('app', [...$env->read('app'), 'MAIL_USERNAME' => 'second-user', 'MAIL_PASSWORD' => 'second-secret']);
    $config->instance->oneOffs['instance:mail-test'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:mail-test', 'status' => 'failed',
        'errors' => [['code' => 'test_mail_failed', 'message' => 'refused', 'hint' => null, 'retryable' => false]]]);
    app()->instance(ConfigCommand::STDIN, fn (): string => 'second-secret');

    [$code, $json] = $config->set('mail', 'smtp', ['--smtp-host' => 'relay.example.net', '--smtp-username' => 'second-user', '--password-stdin' => true]);

    expect($code)->toBe(5, json_encode($json))
        ->and($env->read('app'))->toMatchArray(['MAIL_HOST' => 'smtp.example.com', 'MAIL_USERNAME' => 'first-user', 'MAIL_PASSWORD' => 'first-secret'])
        ->and($directory->exists(ConfigRollout::CREDENTIALS_STASH))->toBeFalse()
        ->and(json_encode($json))->not->toContain('second-secret')->not->toContain('first-secret');
});
