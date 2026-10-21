<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\CaddyfileRenderer;
use App\Domain\Instance\Compose\Services\EdgeService;
use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\Console\FsaCommand;
use App\Domain\Instance\Console\SmtpInput;
use App\Domain\Instance\Docker\ContainerInfo;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\NetworkEndpoint;
use App\Domain\Instance\Docker\NetworkInfo;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * ADR 0080 amendment (§7, E8, R11, R22, R25, R50, AE9): `fsa install --smtp-*` installs straight into SMTP mode against
 * scripted Docker: no catcher and no catcher port, MAIL_* for app, queue and scheduler, the account only in their env
 * files, exit 2 for SMTP flags that do not fit, and the password nowhere else. The smoke test's mail, resume and repeat
 * rules: InstallSmokeTest. The real thing: tests/Install/Journeys/J04CaptureModeTest.php.
 */

const SMTP_INSTALL_PASSWORD = 'smtp-install-secret-5c2e';
const SMTP_INSTALL_USER = 'relay-install-user';

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
    app()->instance(SmtpInput::STDIN, fn (): string => SMTP_INSTALL_PASSWORD."\n");
    $this->progress = new BufferedOutput;
    app()->instance(FsaCommand::PROGRESS_OUTPUT, $this->progress);
});

afterEach(fn () => TemporaryInstance::cleanup());

/** @return array{0: int, 1: array<string, mixed>} */
function smtpInstall(InstanceCommandFixture $fixture, array $flags = []): array
{
    return $fixture->run('fsa:install', InstanceCommandFixture::installFlags([
        '--smtp-host' => 'smtp.example.com', '--smtp-username' => SMTP_INSTALL_USER, '--password-stdin' => true, '--from-address' => 'forms@example.com', ...$flags,
    ]));
}

/** Everything an install leaves outside the env files or prints: the envelope, the state, every journal, the progress. */
function smtpInstallTraces(InstanceCommandFixture $fixture, array $json, BufferedOutput $progress): string
{
    $journal = new OperationJournal($fixture->directory);
    $records = array_map(static fn (JournalRecord $record): string => json_encode($record->toArray()) ?: '', [...$journal->history(), ...array_filter([$journal->current()])]);

    return json_encode($json).$fixture->directory->read('state.json').implode("\n", $records).$progress->fetch();
}

it('installs straight into SMTP mode: no catcher, MAIL_* for app, queue and scheduler, the account only in their env files', function (): void {
    [$code, $json] = smtpInstall($this->fixture);

    $state = $this->fixture->state();
    $env = new EnvFiles($this->fixture->directory);
    $history = new OperationJournal($this->fixture->directory)->history()[0];
    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['mail'])->toBe(['mode' => 'smtp', 'catcher_url' => null])
        ->and(collect($json['warnings'])->pluck('code')->all())->not->toContain('sender_localhost')
        ->and(ComposeFile::services($this->fixture->directory))->toBe(['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'backup'])
        ->and($state->setting('mail'))->toBe('smtp')
        ->and($state->setting('smtp_host'))->toBe('smtp.example.com')
        ->and($state->setting('smtp_port'))->toBe(587)
        ->and($state->setting('smtp_scheme'))->toBeNull()
        ->and($state->setting('mail_from_address'))->toBe('forms@example.com')
        ->and($state->setting('mail_catcher'))->toBeNull()
        ->and($state->setting('mail_ui_port'))->toBeNull()
        ->and(array_column($this->fixture->docker->called('pullImage'), 'reference'))->toHaveCount(3)->not->toContain($state->digests()['mailpit'])
        ->and($history->context())->toMatchArray(['smtp_host' => 'smtp.example.com', 'smtp_port' => null, 'smtp_credentials' => 1, 'from_address' => 'forms@example.com'])
        ->and($history->phaseData('doctor')['mail'])->toBe(['mail' => 'smtp', 'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'mail_from_address' => 'forms@example.com'])
        ->and(substr(sprintf('%o', fileperms($this->fixture->directory->path('env/app.env'))), -3))->toBe('600')
        ->and(smtpInstallTraces($this->fixture, $json, $this->progress))->not->toContain(SMTP_INSTALL_PASSWORD)->not->toContain(SMTP_INSTALL_USER);
    foreach (['app', 'queue', 'scheduler'] as $role) {
        expect($env->read($role))->toMatchArray([
            'MAIL_MAILER' => 'smtp', 'MAIL_HOST' => 'smtp.example.com', 'MAIL_PORT' => '587', 'MAIL_USERNAME' => SMTP_INSTALL_USER,
            'MAIL_PASSWORD' => SMTP_INSTALL_PASSWORD, 'MAIL_FROM_ADDRESS' => 'forms@example.com', 'MAIL_REQUIRE_TLS' => 'true',
        ])->and($env->read($role))->not->toHaveKey('FIELDSET_MAIL_CAPTURE')->not->toHaveKey('MAIL_SCHEME');
    }
});

it('reads the password from --password-file and takes implicit TLS on 465, or the port and sender given', function (array $flags, int $port, ?string $scheme): void {
    $file = $this->fixture->directory->root.'/../'.basename($this->fixture->directory->root).'-smtp-password';
    file_put_contents($file, "file-secret-91\n");
    chmod($file, 0600);

    [$code, $json] = smtpInstall($this->fixture, ['--password-stdin' => false, '--password-file' => $file, '--from-name' => 'Acme Forms', ...$flags]);
    @unlink($file);

    $app = new EnvFiles($this->fixture->directory)->read('app');
    expect($code)->toBe(0, json_encode($json))
        ->and($this->fixture->state()->setting('smtp_port'))->toBe($port)
        ->and($this->fixture->state()->setting('smtp_scheme'))->toBe($scheme)
        ->and($app)->toMatchArray(['MAIL_PORT' => (string) $port, 'MAIL_PASSWORD' => 'file-secret-91', 'MAIL_FROM_NAME' => 'Acme Forms'])
        ->and($scheme === null ? $app : [])->not->toHaveKey('MAIL_SCHEME')
        ->and(json_encode($json))->not->toContain('file-secret-91');
})->with([
    'STARTTLS (default)' => [[], 587, null],
    'implicit TLS' => [['--smtp-encryption' => 'TLS'], 465, 'smtps'],
    'implicit TLS on another port' => [['--smtp-encryption' => 'tls', '--smtp-port' => '2465'], 2465, 'smtps'],
    'STARTTLS on 2525' => [['--smtp-encryption' => 'starttls', '--smtp-port' => '2525'], 2525, null],
    'plain relay (M11 gate)' => [['--smtp-encryption' => 'none'], 25, 'smtp'],
]);

it('talks plain SMTP only with --smtp-encryption none and warns when an account goes along (M11 gate, ADR 0080 amendment)', function (): void {
    [$code, $json] = smtpInstall($this->fixture, ['--smtp-encryption' => 'none']);

    $app = new EnvFiles($this->fixture->directory)->read('app');
    expect($code)->toBe(0, json_encode($json))
        ->and($app)->toMatchArray(['MAIL_SCHEME' => 'smtp', 'MAIL_PORT' => '25', 'MAIL_AUTO_TLS' => 'false'])->not->toHaveKey('MAIL_REQUIRE_TLS')
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('smtp_plaintext_auth');
});

it('installs without an account, and warns when mail would be sent from forms@localhost', function (): void {
    [$code, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--smtp-host' => 'relay.internal', '--smtp-port' => '25']));

    $app = new EnvFiles($this->fixture->directory)->read('app');
    expect($code)->toBe(0, json_encode($json))
        ->and($app)->toMatchArray(['MAIL_HOST' => 'relay.internal', 'MAIL_PORT' => '25', 'MAIL_FROM_ADDRESS' => 'forms@localhost'])
        ->and($app)->not->toHaveKey('MAIL_USERNAME')->not->toHaveKey('MAIL_PASSWORD')
        ->and(collect($json['warnings'])->firstWhere('code', 'sender_localhost')['hint'] ?? null)->toContain('--from-address')
        ->and(new OperationJournal($this->fixture->directory)->history()[0]->context()['smtp_credentials'])->toBeNull();
});

it('reports mail mode smtp in config show and status after such an install', function (): void {
    smtpInstall($this->fixture);

    [$showCode, $show] = $this->fixture->run('fsa:config', ['action' => 'show']);
    [$statusCode, $status] = $this->fixture->run('fsa:status');
    $mail = $show['data']['settings']['mail'] ?? null;
    $key = collect($show['data']['keys'] ?? [])->firstWhere('key', 'mail');

    expect($showCode)->toBe(0, json_encode($show))
        ->and($mail)->toMatchArray(['mode' => 'smtp', 'catcher_url' => null, 'smtp' => ['host' => 'smtp.example.com', 'port' => 587, 'scheme' => null, 'encryption' => 'starttls'], 'from_address' => 'forms@example.com'])
        ->and($key['value'] ?? null)->toBe('smtp')
        ->and(json_encode($show))->not->toContain(SMTP_INSTALL_PASSWORD)->not->toContain(SMTP_INSTALL_USER)
        ->and($statusCode)->toBe(0, json_encode($status))
        ->and($status['data']['mail'])->toBe(['mode' => 'smtp', 'catcher_url' => null])
        ->and(array_keys($status['data']['services']))->not->toContain(MailpitService::NAME);
});

it('switches an instance installed in SMTP mode to capture mode: the catcher appears with a free UI port and its own login', function (): void {
    smtpInstall($this->fixture);
    $this->fixture->withRunningStack();

    [$code, $json] = $this->fixture->run('fsa:config', ['action' => 'set', 'key' => 'mail', 'value' => 'capture']);

    $env = new EnvFiles($this->fixture->directory);
    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['mail'])->toMatchArray(['mode' => 'capture', 'catcher' => 'active', 'catcher_url' => 'http://localhost:8025'])
        ->and(InstanceSettings::of($this->fixture->state())->mailUiPort())->toBe(8025)
        ->and(ComposeFile::services($this->fixture->directory))->toContain(MailpitService::NAME)
        ->and($env->read('mailpit')['MP_UI_AUTH'] ?? '')->toMatch('/^fsa:[0-9a-f]{48}$/')
        ->and($env->read('app'))->toMatchArray(['MAIL_HOST' => 'mailpit', 'FIELDSET_MAIL_CAPTURE' => 'true'])
        ->and($env->read('app'))->not->toHaveKey('MAIL_PASSWORD');
});

it('refuses SMTP flags that do not fit with exit 2 before reading a password or touching Docker', function (array $flags, string $code, string $message): void {
    [$exit, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags($flags));

    expect($exit)->toBe(2, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe($code)
        ->and($json['errors'][0]['message'])->toContain($message)
        ->and($this->fixture->docker->calls)->toBe([])
        ->and($this->fixture->directory->hasState())->toBeFalse();
})->with([
    'port without a host' => [['--smtp-port' => '587'], 'input_required', '--smtp-port need(s) --smtp-host'],
    'sender without a host' => [['--from-address' => 'forms@example.com', '--from-name' => 'Forms'], 'input_required', '--from-address, --from-name need(s) --smtp-host'],
    'account without a host' => [['--smtp-username' => 'relay', '--password-stdin' => true], 'input_required', '--smtp-username, --password-stdin need(s) --smtp-host'],
    'user name without a password' => [['--smtp-host' => 'smtp.example.com', '--smtp-username' => 'relay'], 'password_required', '--smtp-username needs the password'],
    'password without a user name' => [['--smtp-host' => 'smtp.example.com', '--password-stdin' => true], 'invalid_flag', 'A password needs --smtp-username'],
    'both password sources' => [['--smtp-host' => 'smtp.example.com', '--smtp-username' => 'relay', '--password-stdin' => true, '--password-file' => '/nonexistent/pw'], 'invalid_flag', 'exclude each other'],
    'host' => [['--smtp-host' => 'smtp example.com'], 'invalid_value', '--smtp-host'],
    'port' => [['--smtp-host' => 'smtp.example.com', '--smtp-port' => '70000'], 'invalid_value', '--smtp-port'],
    'encryption' => [['--smtp-host' => 'smtp.example.com', '--smtp-encryption' => 'ssl'], 'invalid_value', '--smtp-encryption'],
    'sender address' => [['--smtp-host' => 'smtp.example.com', '--from-address' => 'forms'], 'invalid_value', '--from-address'],
    'sender name' => [['--smtp-host' => 'smtp.example.com', '--from-name' => "Forms\nBcc: x"], 'invalid_value', '--from-name'],
    'unreadable password file' => [['--smtp-host' => 'smtp.example.com', '--smtp-username' => 'relay', '--password-file' => '/nonexistent/pw'], 'invalid_flag', 'cannot be read'],
]);

it('refuses an empty password on stdin with exit 2 password_required', function (): void {
    app()->instance(SmtpInput::STDIN, fn (): string => "\n");

    [$exit, $json] = smtpInstall($this->fixture);

    expect($exit)->toBe(2)->and($json['errors'][0]['code'])->toBe('password_required')->and($this->fixture->docker->calls)->toBe([]);
});

it('refuses an SMTP password passed as a flag value with exit 2 and never echoes it (AE9)', function (): void {
    [$exit, $json] = $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags(['--smtp-host' => 'smtp.example.com', '--smtp-password' => SMTP_INSTALL_PASSWORD]));

    expect($exit)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('secret_in_argument')
        ->and($json['errors'][0]['hint'])->toContain('--password-stdin')->toContain('--password-file')
        ->and(json_encode($json))->not->toContain(SMTP_INSTALL_PASSWORD)
        ->and($this->fixture->docker->calls)->toBe([]);
});

it('takes --password-stdin under install.sh only as the prompt on a terminal: its stdin is the script (curl | sh)', function (bool $tty, int $exit): void {
    $this->fixture->facts(['launcher' => 'install.sh', 'tty' => $tty]);

    [$code, $json] = smtpInstall($this->fixture);

    expect($code)->toBe($exit, json_encode($json));
    if ($exit === 2) {
        expect($json['errors'][0]['code'])->toBe('password_stdin_unavailable')
            ->and($json['errors'][0]['hint'])->toContain('--password-file=<path>')
            ->and($this->fixture->docker->calls)->toBe([]);
    } else {
        expect(new EnvFiles($this->fixture->directory)->read('app')['MAIL_PASSWORD'] ?? null)->toBe(SMTP_INSTALL_PASSWORD);
    }
})->with(['no terminal' => [false, 2], 'terminal' => [true, 0]]);

it('combines with domain mode: the sender takes the domain and no catcher route reaches the edge', function (): void {
    $file = TemporaryInstance::path().'-pw';
    file_put_contents($file, 'domain-secret-3');
    $config = new ConfigFixture(['--domain' => ConfigFixture::DOMAIN, '--smtp-host' => 'smtp.example.com', '--smtp-username' => 'relay', '--password-file' => $file]);
    @unlink($file);
    $directory = $config->instance->directory;

    expect($config->state()->mode())->toBe('domain')
        ->and($config->state()->setting('mail'))->toBe('smtp')
        ->and(ComposeFile::services($directory))->not->toContain(MailpitService::NAME)
        ->and((string) $directory->read('edge/Caddyfile'))->not->toContain('/_mail')
        ->and(new EnvFiles($directory)->read('app'))->toMatchArray(['MAIL_FROM_ADDRESS' => 'forms@'.ConfigFixture::DOMAIN, 'MAIL_PASSWORD' => 'domain-secret-3'])
        ->and(new EnvFiles($directory)->read(EdgeService::NAME))->not->toHaveKey(CaddyfileRenderer::AUTHORIZATION_ENV);
});

it('combines with a shared proxy network: no catcher port is chosen at all', function (): void {
    $config = new ConfigFixture(['--behind-proxy' => 'traefik', '--proxy-network' => 'edge-net', '--url' => 'https://forms.example.com', '--smtp-host' => 'smtp.example.com'], function (InstanceCommandFixture $fixture): void {
        $id = str_repeat('5', 64);
        $fixture->docker->containers[$id] = new ContainerInfo($id, 'traefik-1', 'running', null, null, 'traefik:v3.7.15');
        $fixture->docker->withNetwork(new NetworkInfo('net0', 'edge-net', false, ['172.20.0.0/16'], ['172.20.0.1'], [new NetworkEndpoint($id, 'traefik-1', '172.20.0.5')]));
    });
    $catcherProbes = array_filter($config->instance->runSpecs('true'), static fn (ContainerSpec $spec): bool => preg_match('/:80(2[5-9]|3\d|4[0-4]):/', $spec->ports[0] ?? '') === 1);

    expect($config->state()->mode())->toBe('proxy')
        ->and($config->state()->setting('mail'))->toBe('smtp')
        ->and($config->state()->setting('mail_ui_port'))->toBeNull()
        ->and($catcherProbes)->toBe([])
        ->and(ComposeFile::services($config->instance->directory))->not->toContain(MailpitService::NAME);
});
