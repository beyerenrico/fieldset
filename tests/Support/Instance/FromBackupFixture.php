<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Restore\BackupPreparer;
use App\Domain\Instance\Restore\MigrationSet;
use App\Domain\Instance\Restore\UnsafeArchive;
use Closure;
use RuntimeException;

/**
 * A fresh instance directory (InstanceCommandFixture, nothing installed) whose Docker answers `fsa install --from-backup`
 * (plan U17): the socket-less prepare container runs the real BackupPreparer in-process (fake age, pg_restore answers
 * from fixtures), `instance:restore-db` keeps an in-memory list of databases, pg_restore and tar succeed unless told
 * otherwise, `instance:restore-finish` answers like the app, and the smoke test's curls (internal and through the edge)
 * answer like a healthy host. Backups live outside the instance directory, where `--from-backup` finds them.
 */
final class FromBackupFixture
{
    public const string HOST_ADDRESS = '8.8.4.4';

    public InstanceCommandFixture $instance;

    public string $outside;

    /** @var list<string> */
    public array $databases = ['form_schema', 'postgres'];

    /** @var list<array{action: string, options: list<string>}> */
    public array $databaseCalls = [];

    public ?ProcessOutcome $pgRestore = null;

    /** @var list<string>|null the dump's migrations (null: this image's set) */
    public ?array $migrations = null;

    /** @var list<string> curl URLs in call order */
    public array $curls = [];

    public function __construct()
    {
        $this->instance = new InstanceCommandFixture;
        $this->instance->facts(['lanAddresses' => [self::HOST_ADDRESS]]);
        $this->outside = sys_get_temp_dir().'/fsa-test-move-'.bin2hex(random_bytes(4));
        mkdir($this->outside, 0700, true);
        app()->instance(AgeCli::class, new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen')));
        $run = $this->instance->docker->runResponder;
        $this->instance->docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => $this->answerRun($spec) ?? ($run instanceof Closure ? $run($spec) : null));
        $oneOff = $this->instance->docker->composeRunResponder;
        $this->instance->docker->respondToComposeRun(fn (array $command, ?string $input = null): ?ProcessOutcome => $this->answerOneOff($command) ?? ($oneOff instanceof Closure ? $oneOff($command, $input) : null));
    }

    public function cleanup(): void
    {
        exec('rm -rf '.escapeshellarg($this->outside));
        TemporaryInstance::cleanup();
    }

    /** A DNS name that points at this host (else the doctor finds it elsewhere: certificate pending). */
    public function pointHere(string $domain): void
    {
        $this->instance->probes->dns[$domain] = ['a' => [self::HOST_ADDRESS], 'aaaa' => []];
    }

    /**
     * A plain fsa backup outside the instance directory.
     *
     * @param  array<string, mixed>  $settings  settings.json `settings`
     * @param  array<string, string>  $extra
     */
    /** @param  list<string>  $recipients  the backup's age recipients (settings.json) */
    public function backup(string $mode = 'local', array $settings = [], string $appKey = BackupSetFixture::APP_KEY, array $extra = [], string $name = 'manual-20261019T120000Z', array $recipients = []): string
    {
        $dir = BackupSetFixture::fsa($this->outside.'/'.$name, appKey: $appKey, extra: $extra, settings: $settings);
        $document = json_decode((string) file_get_contents($dir.'/settings.json'), true);
        $document['mode'] = $mode;
        $document['recipients'] = $recipients;
        file_put_contents($dir.'/settings.json', json_encode($document, JSON_UNESCAPED_SLASHES));
        $files = array_values(array_diff(scandir($dir) ?: [], ['.', '..', 'SHA256SUMS', 'SHA256SUMS.hmac']));
        BackupChecksums::write($dir, $files, BackupSetFixture::hmacKey($appKey));

        return $dir;
    }

    public function keyFile(string $contents = BackupSetFixture::APP_KEY."\n"): string
    {
        file_put_contents($this->outside.'/app-key', $contents);

        return $this->outside.'/app-key';
    }

    /**
     * @param  array<string, mixed>  $flags
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function install(string $backup, array $flags = []): array
    {
        return $this->instance->run('fsa:install', ['--from-backup' => $backup, ...$flags]);
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_column($this->databaseCalls, 'action');
    }

    /** @return list<ContainerSpec> */
    public function specs(string $match): array
    {
        $specs = array_map(static fn (array $call): ContainerSpec => $call['spec'], $this->instance->docker->called('runContainer'));

        return array_values(array_filter($specs, static fn (ContainerSpec $spec): bool => $spec->entrypoint === $match || ($spec->command[1] ?? null) === $match));
    }

    private function answerRun(ContainerSpec $spec): ?ProcessOutcome
    {
        if (($spec->command[0] ?? null) === 'fsa' && ($spec->command[1] ?? null) === 'restore-prepare') {
            return $this->prepare($spec);
        }
        if ($spec->entrypoint === 'pg_restore') {
            return $this->pgRestore ?? new ProcessOutcome(0);
        }
        if ($spec->entrypoint === 'tar') {
            return new ProcessOutcome(0);
        }

        return $spec->entrypoint === 'curl' ? $this->curl($spec) : null;
    }

    private function curl(ContainerSpec $spec): ?ProcessOutcome
    {
        $catcher = InstanceCommandFixture::catcherLookup($spec);
        if ($catcher instanceof ProcessOutcome) {
            return $catcher;
        }
        $urls = array_values(array_filter($spec->command, static fn (string $arg): bool => str_starts_with($arg, 'http://') || str_starts_with($arg, 'https://')));
        $this->curls = [...$this->curls, ...$urls];
        $last = $urls[array_key_last($urls)] ?? '';

        return match (true) {
            str_starts_with($last, 'https://') => new ProcessOutcome(0, '200'),
            str_starts_with($last, 'http://app:'), str_starts_with($last, 'http://gotenberg:') => new ProcessOutcome(0, "200 http://app:8080/up\n"),
            str_starts_with($last, 'http://') => new ProcessOutcome(0, '308 '.str_replace('http://', 'https://', $last)),
            default => null,
        };
    }

    private function prepare(ContainerSpec $spec): ProcessOutcome
    {
        $calls = $this->instance->docker->called('runContainer');
        $input = json_decode((string) ($calls[array_key_last($calls)]['input'] ?? '{}'), true) ?: [];
        $options = [];
        foreach (array_slice($spec->command, 2) as $argument) {
            if (preg_match('/^--([a-z-]+)=(.*)$/D', $argument, $match) === 1) {
                $options[$match[1]] = $match[2];
            }
        }
        $keys = array_map(static fn (array $key): array => ['fingerprint' => $key['fingerprint'], 'key' => BackupChecksums::key($key['hmac_key'])], $input['keys'] ?? []);
        $preparer = new BackupPreparer(app(AgeCli::class), BackupSetFixture::tools($this->migrations));

        try {
            $prepared = $preparer->prepare($options['source'], $options['scratch'], $keys, MigrationSet::image(base_path('database/migrations')), $options['identity-file'] ?? null, $input['passphrase'] ?? null);
        } catch (UnsafeArchive $e) {
            return $this->failed('backup_unsafe', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->failed($e->getMessage() === 'identity_required' ? 'identity_required' : 'decrypt_failed', $e->getMessage());
        }

        return InstanceCommandFixture::oneOffJson(['command' => 'restore-prepare', 'status' => 'ok', 'data' => $prepared->toArray()]);
    }

    /** @param  list<string>  $command */
    private function answerOneOff(array $command): ?ProcessOutcome
    {
        return match (true) {
            ($command[2] ?? null) === 'instance:restore-db' => $this->database($command),
            ($command[2] ?? null) === 'instance:restore-finish' => InstanceCommandFixture::oneOffJson(['command' => 'instance:restore-finish', 'status' => 'ok', 'changed' => true, 'data' => [
                'epoch' => 2, 'previous_epoch' => 1, 'activity_id' => 5, 'restored_heads' => [], 'carried' => null, 'sequences' => null,
                'cleared' => ['sessions' => 2, 'remember_tokens' => 1, 'password_reset_tokens' => 0, 'login_links' => 1], 'grants' => ['revoked_public' => 3], 'decrypt' => ['tested' => 1, 'failed' => []],
            ]]),
            default => null,
        };
    }

    /** @param  list<string>  $command */
    private function database(array $command): ProcessOutcome
    {
        $action = $command[3] ?? '';
        $options = [];
        foreach (array_slice($command, 4) as $argument) {
            if (preg_match('/^--([a-z-]+)=(.*)$/D', $argument, $match) === 1) {
                $options[$match[1]] = $match[2];
            }
        }
        $this->databaseCalls[] = ['action' => $action, 'options' => array_slice($command, 4)];
        $data = match ($action) {
            'create' => (function () use ($options): array {
                $this->databases[] = $options['database'];

                return ['database' => $options['database'], 'restricted' => isset($options['restricted-role'])];
            })(),
            'drop' => ['dropped' => (function () use ($options): bool {
                $known = in_array($options['database'], $this->databases, true);
                $this->databases = array_values(array_diff($this->databases, [$options['database']]));

                return $known;
            })()],
            'swap' => $this->swap($options['database'], $options['live'], $options['aside']),
            'list' => ['databases' => array_values(array_filter($this->databases, static fn (string $name): bool => str_starts_with($name, $options['prefix'] ?? '')))],
            default => [],
        };

        return InstanceCommandFixture::oneOffJson(['command' => 'instance:restore-db', 'status' => 'ok', 'changed' => true, 'data' => $data]);
    }

    /** @return array<string, string> */
    private function swap(string $incoming, string $live, string $aside): array
    {
        $this->databases = array_values(array_map(static fn (string $name): string => match ($name) {
            $live => $aside, $incoming => $live, default => $name,
        }, $this->databases));

        return ['live' => $live, 'aside' => $aside, 'incoming' => $incoming];
    }

    private function failed(string $code, string $message): ProcessOutcome
    {
        return InstanceCommandFixture::oneOffJson(['command' => 'restore-prepare', 'status' => 'failed', 'errors' => [['code' => $code, 'message' => $message, 'hint' => null]]]);
    }
}
