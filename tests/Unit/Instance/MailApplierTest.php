<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\Config\ConfigRollout;
use App\Domain\Instance\Console\ConfigCommand;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U12 (R22, R25, R50, AE9, KTD13; ADR 0080): `fsa config set mail smtp|capture`. The password arrives only on
 * stdin or from a file and never reaches the state, the journal, the JSON or the audit; a test mail through the new
 * server verifies the change and a refused one reverts to the previous settings (exit 5); the catcher stays read-only
 * until --remove-catcher; switching back to capture brings it back.
 */

const MAIL_PASSWORD = 'relay-secret-7f3a9c';
const MAIL_USER = 'relay-user-91';

beforeEach(function (): void {
    $this->config = new ConfigFixture;
    app()->instance(ConfigCommand::STDIN, fn (): string => MAIL_PASSWORD."\n");
});

afterEach(fn () => TemporaryInstance::cleanup());

/** @return array{0: int, 1: array<string, mixed>} */
function mailSmtp(ConfigFixture $config, array $options = []): array
{
    return $config->set('mail', 'smtp', ['--smtp-host' => 'smtp.example.com', '--smtp-username' => MAIL_USER, '--password-stdin' => true, '--from-address' => 'forms@example.com', ...$options]);
}

/** Everything a change leaves on disk or prints, to look for the credentials. */
function mailTraces(ConfigFixture $config, array $json): string
{
    $directory = $config->instance->directory;
    $journals = implode("\n", array_map(static fn (JournalRecord $record): string => json_encode($record->toArray()) ?: '', $config->archivedJournals()));

    return json_encode($json).$directory->read('state.json').$journals.json_encode($config->instance->oneOffCalls('instance:config-changed'));
}

it('switches capture mode to SMTP with the password from stdin, keeps the catcher read-only and reports what was captured', function (): void {
    $mark = $this->config->mark();

    [$code, $json] = mailSmtp($this->config);

    $env = new EnvFiles($this->config->instance->directory);
    $state = $this->config->state();
    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['key' => 'mail', 'from' => 'capture', 'to' => 'smtp://smtp.example.com:587 (catcher kept)', 'reverted' => false])
        ->and($json['data']['mail'])->toMatchArray(['mode' => 'smtp', 'catcher' => 'kept', 'catcher_url' => 'http://localhost:8025', 'captured' => ['invitations' => 2, 'password_resets' => 1], 'remove_catcher_command' => 'fsa config set mail smtp --remove-catcher', 'catcher_login_command' => 'fsa config show --catcher-login'])
        ->and($json['data']['checked'])->toContain('test mail to owner@example.com accepted by smtp://smtp.example.com:587 (catcher kept)')
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('captured_mail')
        ->and($state->setting('mail'))->toBe('smtp')
        ->and($state->setting('smtp_port'))->toBe(587)
        ->and($state->setting('mail_catcher'))->toBe('kept')
        ->and($env->read('app'))->toMatchArray(['MAIL_HOST' => 'smtp.example.com', 'MAIL_PORT' => '587', 'MAIL_USERNAME' => MAIL_USER, 'MAIL_PASSWORD' => MAIL_PASSWORD, 'MAIL_FROM_ADDRESS' => 'forms@example.com'])
        ->and($env->read('app'))->not->toHaveKey('FSA_MAIL_CAPTURE')
        ->and($env->read('queue'))->toMatchArray(['MAIL_HOST' => 'smtp.example.com', 'MAIL_PASSWORD' => MAIL_PASSWORD])
        ->and($env->read('mailpit'))->toMatchArray(['MP_SMTP_BIND_ADDR' => '127.0.0.1:1025'])
        ->and(ComposeFile::services($this->config->instance->directory))->toContain(MailpitService::NAME)
        ->and($this->config->callsSince($mark, 'composeStop')[0]['services'])->toBe(['queue', 'scheduler'])
        ->and($this->config->instance->directory->exists(ConfigRollout::CREDENTIALS_STASH))->toBeFalse()
        ->and(mailTraces($this->config, $json))->not->toContain(MAIL_PASSWORD)->not->toContain(MAIL_USER);
});

it('refuses an SMTP password passed as a flag value with exit 2 and changes nothing (AE9)', function (): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('mail', 'smtp', ['--smtp-host' => 'smtp.example.com', '--smtp-password' => MAIL_PASSWORD]);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('secret_in_argument')
        ->and($json['errors'][0]['hint'])->toContain('--password-stdin')->toContain('--password-file')
        ->and(json_encode($json))->not->toContain(MAIL_PASSWORD)
        ->and($this->config->methodsSince($mark))->toBe([]);
});

it('reverts to capture mode when the server refuses the test mail (exit 5) and keeps no credential', function (): void {
    $this->config->instance->oneOffs['instance:mail-test'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:mail-test', 'status' => 'failed',
        'errors' => [['code' => 'test_mail_failed', 'message' => 'The SMTP server did not accept the test mail: 535 authentication failed', 'hint' => null, 'retryable' => false]]]);

    [$code, $json] = mailSmtp($this->config);

    $env = new EnvFiles($this->config->instance->directory);
    expect($code)->toBe(5, json_encode($json))
        ->and($json['status'])->toBe('rolled_back')
        ->and($json['errors'][0])->toMatchArray(['code' => 'test_mail_failed', 'retryable' => false])
        ->and($json['errors'][0]['message'])->toContain('535 authentication failed')
        ->and($json['data'])->toMatchArray(['reverted' => true])
        ->and($json['data']['command'])->toContain('--smtp-username <the same user name> --password-stdin')
        ->and($this->config->state()->setting('mail'))->toBeNull()
        ->and($env->read('app'))->toMatchArray(['MAIL_HOST' => 'mailpit', 'FSA_MAIL_CAPTURE' => 'true'])
        ->and($env->read('app'))->not->toHaveKey('MAIL_PASSWORD')
        ->and($env->read('mailpit'))->not->toHaveKey('MP_SMTP_BIND_ADDR')
        ->and($this->config->instance->directory->exists(ConfigRollout::CREDENTIALS_STASH))->toBeFalse()
        ->and(mailTraces($this->config, $json))->not->toContain(MAIL_PASSWORD)->not->toContain(MAIL_USER);
});

it('restores the previous server\'s own credentials when a change to another server is reverted', function (): void {
    mailSmtp($this->config);
    app()->instance(ConfigCommand::STDIN, fn (): string => 'other-secret-2');
    $this->config->instance->oneOffs['instance:mail-test'] = InstanceCommandFixture::oneOffJson(['command' => 'instance:mail-test', 'status' => 'failed',
        'errors' => [['code' => 'test_mail_failed', 'message' => 'connection refused', 'hint' => null, 'retryable' => false]]]);

    [$code] = $this->config->set('mail', 'smtp', ['--smtp-host' => 'relay.example.net', '--smtp-username' => 'other-user', '--password-stdin' => true]);

    expect($code)->toBe(5)
        ->and(new EnvFiles($this->config->instance->directory)->read('app'))->toMatchArray(['MAIL_HOST' => 'smtp.example.com', 'MAIL_USERNAME' => MAIL_USER, 'MAIL_PASSWORD' => MAIL_PASSWORD])
        ->and($this->config->state()->setting('smtp_host'))->toBe('smtp.example.com');
});

it('removes the catcher and its volume only with --remove-catcher, without a new test mail when delivery stays the same', function (): void {
    mailSmtp($this->config);
    $state = $this->config->state();
    $volume = $state->project().'_'.MailpitService::VOLUME;
    $this->config->instance->docker->withResource(new DockerResource(DockerResource::VOLUME, $volume, [Labels::INSTANCE => $state->uuid()]));
    $tests = count($this->config->instance->oneOffCalls('instance:mail-test'));

    [$code, $json] = $this->config->set('mail', 'smtp', ['--remove-catcher' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['mail'])->toMatchArray(['catcher' => 'removed', 'catcher_url' => null, 'catcher_volume_removed' => $volume])
        ->and($json['data']['mail'])->not->toHaveKey('captured')
        ->and(ComposeFile::services($this->config->instance->directory))->not->toContain(MailpitService::NAME)
        ->and($this->config->instance->docker->called('removeVolume'))->toBe([['name' => $volume]])
        ->and($this->config->instance->oneOffCalls('instance:mail-test'))->toHaveCount($tests)
        ->and(new EnvFiles($this->config->instance->directory)->read('app'))->toMatchArray(['MAIL_PASSWORD' => MAIL_PASSWORD]);

    [$again, $unchanged] = $this->config->set('mail', 'smtp', ['--remove-catcher' => true]);
    expect($again)->toBe(0)->and($unchanged['changed'])->toBeFalse();
});

it('switches back to capture mode: the catcher returns on a free port and the credentials are dropped', function (): void {
    mailSmtp($this->config, ['--remove-catcher' => true]);
    $this->config->takenLoopback = [8025];

    [$code, $json] = $this->config->set('mail', 'capture');

    $env = new EnvFiles($this->config->instance->directory);
    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['from' => 'smtp://smtp.example.com:587', 'to' => 'capture'])
        ->and($json['data']['mail'])->toMatchArray(['mode' => 'capture', 'catcher' => 'active', 'catcher_url' => 'http://localhost:8026'])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('catcher_port_changed')
        ->and(ComposeFile::services($this->config->instance->directory))->toContain(MailpitService::NAME)
        ->and($env->read('app'))->toMatchArray(['MAIL_HOST' => 'mailpit', 'FSA_MAIL_CAPTURE' => 'true'])
        ->and($env->read('app'))->not->toHaveKey('MAIL_USERNAME');
});

it('answers the same mail settings again with changed: false and no Docker call', function (): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('mail', 'capture');

    expect($code)->toBe(0)->and($json['changed'])->toBeFalse()->and($this->config->methodsSince($mark))->toBe([]);
});

it('reads the password from --password-file and uses implicit TLS on 465 for --smtp-encryption tls', function (): void {
    $file = TemporaryInstance::path().'-password';
    file_put_contents($file, MAIL_PASSWORD."\n");

    [$code, $json] = $this->config->set('mail', 'smtp', ['--smtp-host' => 'smtp.example.com', '--smtp-encryption' => 'tls', '--smtp-username' => MAIL_USER, '--password-file' => $file]);
    @unlink($file);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['to'])->toBe('smtps://smtp.example.com:465 (catcher kept)')
        ->and(new EnvFiles($this->config->instance->directory)->read('app'))->toMatchArray(['MAIL_SCHEME' => 'smtps', 'MAIL_PORT' => '465', 'MAIL_PASSWORD' => MAIL_PASSWORD])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('sender_localhost');
});

it('refuses invalid mail input with exit 2 before anything changes', function (array $options, string $value, string $error): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('mail', $value, $options);

    expect($code)->toBe(2, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe($error)
        ->and($this->config->methodsSince($mark))->toBe([]);
})->with([
    // M11 gate: the same code as `fsa install` (was `missing_flag`); data.missing: MailConfigSafetyTest.
    'no host from capture' => [['--smtp-port' => '587'], 'smtp', 'input_required'],
    'user without password' => [['--smtp-host' => 'smtp.example.com', '--smtp-username' => MAIL_USER], 'smtp', 'password_required'],
    'password without user' => [['--smtp-host' => 'smtp.example.com', '--password-stdin' => true], 'smtp', 'invalid_flag'],
    'both password sources' => [['--smtp-host' => 'smtp.example.com', '--password-stdin' => true, '--password-file' => '/tmp/x'], 'smtp', 'invalid_flag'],
    'bad host' => [['--smtp-host' => 'smtp example'], 'smtp', 'invalid_value'],
    'bad port' => [['--smtp-host' => 'smtp.example.com', '--smtp-port' => '70000'], 'smtp', 'invalid_value'],
    'bad encryption' => [['--smtp-host' => 'smtp.example.com', '--smtp-encryption' => 'ssl'], 'smtp', 'invalid_value'],
    'bad sender' => [['--smtp-host' => 'smtp.example.com', '--from-address' => 'nobody'], 'smtp', 'invalid_value'],
    'bad sender name' => [['--smtp-host' => 'smtp.example.com', '--from-name' => 'Acme "Forms"'], 'smtp', 'invalid_value'],
    'bad test recipient' => [['--smtp-host' => 'smtp.example.com', '--test-to' => 'ops'], 'smtp', 'invalid_value'],
    'capture with flags' => [['--smtp-host' => 'smtp.example.com'], 'capture', 'invalid_flag'],
    'other value' => [[], 'log', 'invalid_value'],
]);

it('refuses SMTP options on other keys', function (array $options): void {
    [$code, $json] = $this->config->set('port', '8090', $options);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('invalid_flag');
})->with([
    'host' => [['--smtp-host' => 'smtp.example.com']],
    'stdin' => [['--password-stdin' => true]],
    'catcher' => [['--remove-catcher' => true]],
    'catcher login' => [['--catcher-login' => true]],
]);

it('rejects an empty or multi-line password from stdin', function (string $input, string $error): void {
    app()->instance(ConfigCommand::STDIN, fn (): string => $input);

    [$code, $json] = mailSmtp($this->config);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe($error)->and(json_encode($json))->not->toContain('second-line');
})->with([
    'empty' => ["\n", 'password_required'],
    'two lines' => ["first\nsecond-line\n", 'invalid_value'],
]);
