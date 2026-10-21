<?php

declare(strict_types=1);

use App\Domain\Instance\Console\FsaCommand;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Console\SecretFlagGuard;
use App\Domain\Instance\Docker\CliDockerGateway;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\OpsContextRequired;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Instance\ScriptedFsaCommand;
use Tests\Support\Instance\TemporaryInstance;

beforeEach(function (): void {
    $this->directory = TemporaryInstance::withState();
    app()->instance(HostFacts::class, new HostFacts(tty: false, uid: 1000));
});

afterEach(fn () => TemporaryInstance::cleanup());

/** @return array{0: int, 1: array<string, mixed>} */
function fsaJson(array $parameters = []): array
{
    $code = Artisan::call('fsa:scripted', ['--json' => true, ...$parameters]);
    $output = trim(Artisan::output());

    return [$code, json_decode($output, true, 512, JSON_THROW_ON_ERROR)];
}

it('renders one JSON envelope with schema_version and status', function (): void {
    ScriptedFsaCommand::register(fn (FsaContext $c): CommandResult => CommandResult::ok('scripted', ['dir' => $c->directory()->root], changed: true));

    [$code, $json] = fsaJson(['--dir' => $this->directory->root]);

    expect($code)->toBe(0)
        ->and(array_keys($json))->toBe(['schema_version', 'command', 'status', 'exit_code', 'changed', 'data', 'secrets', 'warnings', 'errors']) // exit_code: M12 gate O10
        ->and($json)->toMatchArray(['schema_version' => 1, 'command' => 'scripted', 'status' => 'ok', 'changed' => true, 'data' => ['dir' => $this->directory->root], 'secrets' => [], 'warnings' => [], 'errors' => []]);
});

it('adds the common flags to every fsa command', function (): void {
    $definition = ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted'))->getDefinition();

    expect($definition->hasOption('json'))->toBeTrue()
        ->and($definition->hasOption('yes'))->toBeTrue()
        ->and($definition->hasOption('dir'))->toBeTrue()
        ->and($definition->hasOption('secrets-file'))->toBeTrue();
});

it('refuses to run outside a marked instance directory (exit 2)', function (): void {
    $command = ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted'));
    $unmarked = TemporaryInstance::path();
    mkdir($unmarked);

    [$code, $json] = fsaJson(['--dir' => $unmarked]);

    expect($code)->toBe(2)
        ->and($json['status'])->toBe('failed')
        ->and($json['errors'][0]['code'])->toBe('not_an_instance_directory')
        ->and($command->performed)->toBe(0);
});

it('finds the instance from FIELDSET_INSTANCE_DIR or a relative --dir', function (): void {
    ScriptedFsaCommand::register(fn (FsaContext $c): CommandResult => CommandResult::ok('scripted', ['dir' => $c->directory()->root]));
    app()->instance(HostFacts::class, new HostFacts(tty: false, instanceDir: $this->directory->root));

    [, $fromEnv] = fsaJson();
    $cwd = getcwd();
    chdir(dirname($this->directory->root));

    try {
        [, $relative] = fsaJson(['--dir' => basename($this->directory->root).'/']);
    } finally {
        chdir((string) $cwd);
    }

    expect($fromEnv['data']['dir'])->toBe($this->directory->root)
        ->and($relative['data']['dir'])->toEndWith('/'.basename($this->directory->root));
});

it('runs a command that needs no instance directory anywhere (fsa version)', function (): void {
    ScriptedFsaCommand::register(fn (FsaContext $c): CommandResult => CommandResult::ok('scripted', ['has_dir' => $c->hasDirectory()]), needsInstance: false);

    [$code, $json] = fsaJson(['--dir' => '/nonexistent']);

    expect($code)->toBe(0)->and($json['data']['has_dir'])->toBeFalse();
});

it('makes the Docker gateway resolvable only inside a marked instance directory', function (): void {
    expect(fn () => app(DockerGateway::class))->toThrow(OpsContextRequired::class);
    ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted', ['gateway' => app(DockerGateway::class)::class]));

    [, $json] = fsaJson(['--dir' => $this->directory->root]);

    expect($json['data']['gateway'])->toBe(CliDockerGateway::class)
        ->and(app(InstanceDirectory::class)->root)->toBe($this->directory->root);
});

it('rejects a secret given as a flag value with exit 2 and a stdin/file hint (AE9)', function (array $parameters): void {
    $command = ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted'));

    [$code, $json] = fsaJson(['--dir' => $this->directory->root, ...$parameters]);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('secret_in_argument')
        ->and($json['errors'][0]['hint'])->toContain('stdin')->toContain('-file')
        ->and(json_encode($json))->not->toContain('hunter2')
        ->and($command->performed)->toBe(0);
})->with([
    'undefined smtp password' => [['--smtp-password' => 'hunter2']],
    'app key' => [['--app-key' => 'base64:hunter2']],
]);

it('rejects a secret flag in human mode without echoing it', function (): void {
    ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted'));

    $code = Artisan::call('fsa:scripted --dir='.escapeshellarg($this->directory->root).' --backup-passphrase=hunter2');

    expect($code)->toBe(2)
        ->and(Artisan::output())->toContain('secret_in_argument')->toContain('Fix: ')->not->toContain('hunter2');
});

it('accepts file variants of secret flags', function (): void {
    $command = ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted'));

    [$code] = fsaJson(['--dir' => $this->directory->root, '--smtp-password-file' => '/run/secrets/smtp']);

    expect($code)->toBe(0)->and($command->performed)->toBe(1);
});

it('classifies secret-looking flag names', function (string $flag, bool $secret): void {
    expect(SecretFlagGuard::isSecret($flag))->toBe($secret);
})->with([
    ['smtp-password', true], ['password', true], ['token', true], ['app-key', true], ['backup_secret', true],
    ['smtp-password-file', false], ['password-stdin', false], ['passphrase-fd', false], ['public-key', false],
    ['keep-backups', false], ['include-secrets', false], ['secrets-file', false], ['json', false], ['monkey', false],
]);

it('reports other usage errors as exit 2 in the envelope', function (): void {
    ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted'));

    [$code, $json] = fsaJson(['--dir' => $this->directory->root, '--no-such-flag' => true]);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('invalid_usage');
});

it('exits 6 for a destructive command without a TTY and without --yes', function (): void {
    $command = ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted', changed: true), prompt: 'Delete all data?');

    [$code, $json] = fsaJson(['--dir' => $this->directory->root]);

    expect($code)->toBe(6)
        ->and($json['status'])->toBe('aborted')
        ->and($json['errors'][0]['code'])->toBe('confirmation_required')
        ->and($json['errors'][0]['hint'])->toContain('--yes')
        ->and($command->performed)->toBe(0);
});

it('treats --json like no TTY for confirmations, and --yes confirms', function (): void {
    app()->instance(HostFacts::class, new HostFacts(tty: true));
    $command = ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted'), prompt: 'Delete all data?');

    [$refused] = fsaJson(['--dir' => $this->directory->root]);
    [$confirmed] = fsaJson(['--dir' => $this->directory->root, '--yes' => true]);

    expect($refused)->toBe(6)->and($confirmed)->toBe(0)->and($command->performed)->toBe(1);
});

it('asks on a terminal and aborts unchanged when the answer is no', function (string $answer, int $exit): void {
    app()->instance(HostFacts::class, new HostFacts(tty: true));
    ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted'), prompt: 'Delete all data?');

    $this->artisan('fsa:scripted', ['--dir' => $this->directory->root])
        ->expectsConfirmation('Delete all data?', $answer)
        ->assertExitCode($exit);
})->with([['no', 9], ['yes', 0]]);

it('prints secrets only under secrets and scrubs them from data, warnings, errors and progress', function (): void {
    $progress = new BufferedOutput;
    app()->instance(FsaCommand::PROGRESS_OUTPUT, $progress);
    ScriptedFsaCommand::register(function (FsaContext $c): CommandResult {
        $link = $c->secret('sign_in_link', 'https://forms.test/l#tok-123456', CarbonImmutable::parse('2026-10-17 11:00:00', 'UTC'));
        $c->progress->phase('login', 'Issued https://forms.test/l#tok-123456');

        return CommandResult::ok('scripted', ['echo' => 'link https://forms.test/l#tok-123456'])
            ->withSecret($link)
            ->withWarning('w', 'see https://forms.test/l#tok-123456');
    });

    [, $json] = fsaJson(['--dir' => $this->directory->root]);
    $event = json_decode(trim($progress->fetch()), true);

    expect($json['secrets'])->toBe([['name' => 'sign_in_link', 'value' => 'https://forms.test/l#tok-123456', 'expires_at' => '2026-10-17T11:00:00Z']])
        ->and($json['data']['echo'])->toBe('link [redacted]')
        ->and($json['warnings'][0]['message'])->toBe('see [redacted]')
        ->and(array_keys($event))->toBe(['ts', 'phase', 'level', 'message'])
        ->and($event['phase'])->toBe('login')
        ->and($event['message'])->toBe('Issued [redacted]');
});

it('writes secrets to a 0600 --secrets-file and leaves their values out of stdout', function (): void {
    $file = $this->directory->root.'/secrets.json';
    ScriptedFsaCommand::register(fn (FsaContext $c): CommandResult => CommandResult::ok('scripted')->withSecret($c->secret('demo_password', 'pw-abcdef-123')));

    [$code, $json] = fsaJson(['--dir' => $this->directory->root, '--secrets-file' => $file]);
    $written = json_decode((string) file_get_contents($file), true);

    expect($code)->toBe(0)
        ->and(fileperms($file) & 0777)->toBe(0600)
        ->and($written['secrets'][0])->toMatchArray(['name' => 'demo_password', 'value' => 'pw-abcdef-123'])
        ->and($json['secrets'][0])->toBe(['name' => 'demo_password', 'written_to' => $file, 'expires_at' => null])
        ->and(json_encode($json))->not->toContain('pw-abcdef-123');
});

it('rejects an unusable --secrets-file before doing anything', function (string $path): void {
    $command = ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted'));

    [$code, $json] = fsaJson(['--dir' => $this->directory->root, '--secrets-file' => str_replace('{dir}', $this->directory->root, $path)]);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('secrets_file_unwritable')
        ->and($command->performed)->toBe(0);
})->with(['/nonexistent-dir/secrets.json', '{dir}']);

it('drops secrets and exits 2 when the secrets file cannot be written at the end', function (): void {
    $file = $this->directory->root.'/out/secrets.json';
    mkdir(dirname($file));
    ScriptedFsaCommand::register(function (FsaContext $c) use ($file): CommandResult {
        rmdir(dirname($file));

        return CommandResult::ok('scripted')->withSecret($c->secret('demo_password', 'pw-abcdef-123'))->withWarning('w', 'kept');
    });

    [$code, $json] = fsaJson(['--dir' => $this->directory->root, '--secrets-file' => $file]);

    expect($code)->toBe(2)
        ->and($json['errors'][0]['code'])->toBe('secrets_file_unwritable')
        ->and($json['secrets'])->toBe([])
        ->and($json['warnings'][0]['code'])->toBe('w')
        ->and(json_encode($json))->not->toContain('pw-abcdef-123');
});

it('maps instance exceptions to their exit code and anything else to exit 1', function (Throwable $e, int $exit, string $code): void {
    ScriptedFsaCommand::register(fn (): CommandResult => throw $e);

    [$actual, $json] = fsaJson(['--dir' => $this->directory->root]);

    expect($actual)->toBe($exit)->and($json['errors'][0]['code'])->toBe($code);
})->with([
    'preflight' => [new InstanceException('port taken', ExitCode::PreflightFailed, 'port_taken', 'Use --port'), 3, 'port_taken'],
    'unexpected' => [new RuntimeException('boom'), 1, 'internal_error'],
]);

it('prints a minimal envelope instead of a stack trace when the result cannot be rendered', function (): void {
    ScriptedFsaCommand::register(fn (FsaContext $c): CommandResult => CommandResult::ok('scripted', ['ratio' => NAN], changed: true)->withSecret($c->secret('demo_password', 'pw-render-123')));

    [$code, $json] = fsaJson(['--dir' => $this->directory->root]);

    expect($code)->toBe(1)
        ->and($json['status'])->toBe('failed')
        ->and($json['changed'])->toBeTrue()
        ->and($json['errors'][0]['code'])->toBe('render_failed')
        ->and($json['data'])->toBe(['exit_code' => 0])
        ->and($json['secrets'])->toBe([])
        ->and(json_encode($json))->not->toContain('pw-render-123');
});

it('exits 7 instead of rewriting a state file from a newer major version', function (): void {
    $state = $this->directory->path('state.json');
    $newer = json_encode([...json_decode((string) file_get_contents($state), true), 'schema_version' => '2.0']);
    file_put_contents($state, $newer);
    ScriptedFsaCommand::register(function (FsaContext $c): CommandResult {
        $c->directory()->saveState($c->directory()->state()->withUrl('http://localhost:8080'));

        return CommandResult::ok('scripted', changed: true);
    });

    [$code, $json] = fsaJson(['--dir' => $this->directory->root]);

    expect($code)->toBe(7)
        ->and($json['errors'][0]['code'])->toBe('incompatible_schema')
        ->and(file_get_contents($state))->toBe($newer);
});

it('renders readable output without --json', function (): void {
    ScriptedFsaCommand::register(fn (FsaContext $c): CommandResult => CommandResult::failed('scripted', ExitCode::HealthCheckFailed, 'smoke_failed', 'The app did not become healthy.', 'Run `fsa logs app`.', true, ['url' => 'http://localhost:8080', 'services' => ['app']], true)
        ->withWarning('mail_capture', 'Mail is captured.')
        ->withSecret($c->secret('sign_in_link', 'https://x.test/#t-999999')));

    $code = Artisan::call('fsa:scripted', ['--dir' => $this->directory->root]);
    $output = Artisan::output();

    expect($code)->toBe(4)
        ->and($output)->toContain('fsa scripted: failed')
        ->toContain("url: 'http://localhost:8080'")
        ->toContain('services: ["app"]')
        ->toContain('sign_in_link: https://x.test/#t-999999')
        ->toContain('Warning [mail_capture]: Mail is captured.')
        ->toContain('Error [smoke_failed]: The app did not become healthy.')
        ->toContain('Fix: Run `fsa logs app`.');
});

it('scrubs secrets from the instance env files out of every output', function (): void {
    new EnvFiles($this->directory)->write('app', ['APP_KEY' => 'base64:c2VjcmV0LWFwcC1rZXk=', 'DB_PASSWORD' => 'db-pass-4711', 'DB_HOST' => 'postgres']);
    ScriptedFsaCommand::register(fn (): CommandResult => CommandResult::ok('scripted', ['stderr' => 'auth failed for db-pass-4711 on postgres with base64:c2VjcmV0LWFwcC1rZXk=']));

    [, $json] = fsaJson(['--dir' => $this->directory->root]);

    expect($json['data']['stderr'])->toBe('auth failed for [redacted] on postgres with [redacted]');
});
