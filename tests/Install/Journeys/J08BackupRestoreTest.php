<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\BackupChecksums;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHolder;
use App\Domain\Instance\State\InstanceDirectory;
use Carbon\CarbonImmutable;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\BudgetJourney;
use Tests\Support\Docker\CgroupReader;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;
use Tests\Support\Instance\RecordingDockerGateway;

/*
 * J8, backup half (BUILD_PROMPT_V2 §11, R38–R40, R57, R67, E13; plan U16; ADR 0084), with real Docker against the
 * harness release (U17 adds the restore half):
 *
 * - a local `--demo` install runs the hardened backup service (container root, four capabilities, read-only root,
 *   storage read-only, no socket);
 * - `fsa backup`: every artifact, SHA256SUMS and an HMAC keyed from APP_KEY (recomputed here), the dump readable by
 *   pg_restore and written by the image's PGDG 16 client, a 0700 www-data directory complete in storage.tar.gz,
 *   everything 0600/0700 for the owner;
 * - `fsa config set backup-encryption on`, then an encrypted backup that decrypts with the identity file and its
 *   passphrase file (batchpass on a file descriptor, no terminal), and not with another passphrase;
 * - the daemon on a schedule a few minutes ahead: a run while an operation holds the instance lock is skipped and
 *   recorded once, `fsa status` shows it; the retry after the lock is released succeeds, retention (1) prunes an old
 *   scheduled backup and keeps the manual ones, `backup_runs` holds every run; the backup service's memory peak is
 *   measured (cgroup) against its 128 MiB slot (ADR 0083).
 *
 * Everything is named `fsa-test-j08…` and removed afterwards. Evidence goes to the milestone of FSA_EVIDENCE_MILESTONE.
 */

const J08_PASSPHRASE = 'j08 backup identity passphrase';

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fsaJ08'] = new InstanceJourney($harness, $release, $harness->directory.'/j08');
    } catch (Throwable $exception) {
        $GLOBALS['fsaJ08Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fsaJ08'])) {
        InstanceJourney::cleanup($GLOBALS['fsaJ08']->work.'/fsa-test-j08', $GLOBALS['fsaJ08Uuid'] ?? null);
    }
    $GLOBALS['fsaJ08Reader']?->stop();
    unset($GLOBALS['fsaJ08'], $GLOBALS['fsaJ08Error'], $GLOBALS['fsaJ08Uuid'], $GLOBALS['fsaJ08Reader'], $GLOBALS['fsaJ08Manual']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FSA_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fsaJ08Error'])) {
        throw $GLOBALS['fsaJ08Error'];
    }
    $this->journey = $GLOBALS['fsaJ08'];
    $this->dir = $this->journey->work.'/fsa-test-j08';
});

/** @param  list<string>  $command */
function j08Docker(array $command, ?string $input = null, int $timeout = 300): Process
{
    $process = new Process(['docker', ...$command]);
    $process->setTimeout($timeout);
    $process->setInput($input);
    $process->run();

    return $process;
}

/** The ID of the instance's container of a role (app, postgres, backup …). */
function j08Container(string $uuid, string $role): string
{
    return trim(j08Docker(['ps', '-q', '--filter', "label=app.form-schema.instance={$uuid}", '--filter', "label=app.form-schema.role={$role}", '--filter', 'label=com.docker.compose.oneoff=False'])->getOutput());
}

/** BACKUP_HMAC_KEY recomputed from APP_KEY in env/app.env: HKDF-SHA256, info `form-schema-app backup hmac v1` (R67). */
function j08HmacKey(string $dir): string
{
    preg_match("/^APP_KEY='?base64:([A-Za-z0-9+\\/=]+)'?$/m", (string) file_get_contents($dir.'/env/app.env'), $match);

    return hash_hkdf('sha256', (string) base64_decode($match[1] ?? '', true), 32, 'form-schema-app backup hmac v1');
}

/** @return list<string> names inside a directory */
function j08Files(string $path): array
{
    return array_values(array_diff(scandir($path) ?: [], ['.', '..']));
}

/** @return array<string, array{0: string, 1: int}> file => [mode, owner] for a backup directory and its files */
function j08Modes(string $path): array
{
    $modes = ['.' => [substr(sprintf('%o', fileperms($path)), -3), (int) fileowner($path)]];
    foreach (j08Files($path) as $file) {
        $modes[$file] = [substr(sprintf('%o', fileperms($path.'/'.$file)), -3), (int) fileowner($path.'/'.$file)];
    }

    return $modes;
}

/** @return list<array<string, mixed>> the run ledger, oldest first */
function j08Ledger(string $dir): array
{
    $lines = is_file($dir.'/backups/runs.log') ? file($dir.'/backups/runs.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

    return array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), $lines ?: []), is_array(...)));
}

/** Decrypts an encrypted backup with the identity and a passphrase file in a throwaway container of the app image. */
function j08Decrypt(InstanceJourney $journey, string $identity, string $passphraseFile, string $backup): Process
{
    $script = 'set -e; AGE_PASSPHRASE_FD=3 age --decrypt -j batchpass "$1" 3<"$2" | age --decrypt --identity - "$3" | tar -tf -';

    return j08Docker(['run', '--rm', '--network', 'none', '--user', '0:0', '-v', $journey->work.':'.$journey->work.':ro', '--entrypoint', 'sh', $journey->release->appReference, '-c', $script, 'sh', $identity, $passphraseFile, $backup]);
}

it('installs a local demo instance with the hardened backup service', function (): void {
    $process = $this->journey->install($this->dir, ['--org', 'Backup Org', '--owner-email', 'owner@example.test', '--locale', 'en', '--demo', '--secrets-file', $this->journey->work.'/j08-secrets.json', '--json']);
    $json = InstanceJourney::json($process);
    $GLOBALS['fsaJ08Uuid'] = InstanceJourney::uuid($this->dir);
    $backup = json_decode(j08Docker(['inspect', j08Container((string) $GLOBALS['fsaJ08Uuid'], 'backup')])->getOutput(), true)[0] ?? [];
    $caps = array_map(static fn (string $cap): string => (string) preg_replace('/^CAP_/', '', $cap), $backup['HostConfig']['CapAdd'] ?? []); // Docker reports CAP_CHOWN …
    sort($caps);
    $mounts = array_map(static fn (array $mount): string => $mount['Destination'].($mount['RW'] ? ':rw' : ':ro'), $backup['Mounts'] ?? []);
    sort($mounts);
    $this->journey->evidence('J08-install.json', ['command' => 'install.sh --dir <work>/fsa-test-j08 --org "Backup Org" --owner-email owner@example.test --locale en --demo --secrets-file <work>/j08-secrets.json --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json,
        'backup_service' => ['user' => $backup['Config']['User'] ?? null, 'cap_add' => $caps, 'cap_drop' => $backup['HostConfig']['CapDrop'] ?? null, 'read_only_root' => $backup['HostConfig']['ReadonlyRootfs'] ?? null, 'security_opt' => $backup['HostConfig']['SecurityOpt'] ?? null, 'mounts' => $mounts, 'memory_limit_mib' => intdiv((int) ($backup['HostConfig']['Memory'] ?? 0), 1048576)]]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($backup['State']['Status'] ?? null)->toBe('running')
        ->and($backup['Config']['User'] ?? null)->toBe('0:0')
        ->and($caps)->toBe(['CHOWN', 'DAC_OVERRIDE', 'DAC_READ_SEARCH', 'FOWNER'])
        ->and($backup['HostConfig']['CapDrop'] ?? null)->toBe(['ALL'])
        ->and($backup['HostConfig']['ReadonlyRootfs'] ?? null)->toBeTrue()
        ->and($mounts)->toBe(['/fsa/instance/.fsa-instance:ro', '/fsa/instance/backups:rw', '/fsa/instance/locks:rw', '/var/www/html/storage/app:ro'])
        ->and(json_encode($backup))->not->toContain('docker.sock')
        ->and(intdiv((int) ($backup['HostConfig']['Memory'] ?? 0), 1048576))->toBe(128)
        ->and($backup['HostConfig']['Tmpfs'] ?? null)->toHaveKey('/tmp');
});

it('writes a manual backup that verifies: artifacts, SHA256SUMS, the HMAC, pg_restore --list, storage and ownership', function (): void {
    $uuid = (string) $GLOBALS['fsaJ08Uuid'];
    // A private directory as the app creates them: www-data, 0700 (the backup service reads it as root with DAC_READ_SEARCH).
    j08Docker(['exec', '-u', 'www-data', j08Container($uuid, 'app'), 'sh', '-c', 'mkdir -p storage/app/private/fsa-test-j08 && chmod 0700 storage/app/private/fsa-test-j08 && printf secret > storage/app/private/fsa-test-j08/file.txt && chmod 0600 storage/app/private/fsa-test-j08/file.txt']);
    $process = $this->journey->fsa($this->dir, ['backup', '--json']);
    $json = InstanceJourney::json($process);
    $path = (string) ($json['data']['path'] ?? '');
    $manifest = json_decode((string) @file_get_contents($path.'/manifest.json'), true);
    $listed = new Process(['tar', '-tzf', $path.'/storage.tar.gz']);
    $listed->run();
    $restoreList = j08Docker(['run', '--rm', '--network', 'none', '--user', '0:0', '-v', $path.':/backup:ro', '--entrypoint', 'pg_restore', $this->journey->release->appReference, '--list', '/backup/database.dump']);
    $GLOBALS['fsaJ08Manual'] = $json['data']['backup'] ?? null;
    $this->journey->evidence('J08-backup-manual.json', ['command' => 'fsa backup --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'files' => j08Files($path), 'modes' => j08Modes($path),
        'checksums' => BackupChecksums::verify($path, j08HmacKey($this->dir)), 'manifest' => $manifest, 'pg_restore_list_exit' => $restoreList->getExitCode()]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data'])->toMatchArray(['kind' => 'manual', 'encrypted' => false, 'includes_secrets' => false])
        ->and($json['data']['app_key_fingerprint'])->toMatch('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/')
        ->and(j08Files($path))->toBe(['SHA256SUMS', 'SHA256SUMS.hmac', 'audit-heads.json', 'database.dump', 'manifest.json', 'settings.json', 'storage.tar.gz'])
        ->and(BackupChecksums::verify($path, j08HmacKey($this->dir)))->toBe([])
        ->and(BackupChecksums::verify($path, str_repeat('0', 32)))->not->toBe([])
        ->and($restoreList->getExitCode())->toBe(0, $restoreList->getErrorOutput())
        ->and($restoreList->getOutput())->toContain('TABLE DATA public submissions')
        ->and($manifest['versions']['pg_dump'] ?? '')->toContain('16.')->toContain('pgdg')
        ->and($manifest['audit']['epoch'] ?? null)->toBe(1)
        ->and($listed->getOutput())->toContain('./private/fsa-test-j08/file.txt')
        ->and(array_values(array_unique(array_column(j08Modes($path), 1))))->toBe([posix_getuid()])
        ->and(array_values(array_unique(array_map(static fn (array $mode): string => $mode[0], array_slice(j08Modes($path), 1)))))->toBe(['600'])
        ->and(j08Modes($path)['.'][0])->toBe('700');
});

it('turns encryption on and writes an encrypted backup that decrypts with the identity and its passphrase file', function (): void {
    $identity = $this->journey->work.'/j08-identity.age';
    $passphrase = $this->journey->work.'/j08-passphrase.txt';
    $wrong = $this->journey->work.'/j08-wrong.txt';
    file_put_contents($passphrase, J08_PASSPHRASE."\n");
    file_put_contents($wrong, 'not the passphrase at all');
    chmod($passphrase, 0600);
    $inside = $this->journey->fsa($this->dir, ['config', 'set', 'backup-encryption', 'on', '--identity-out', $this->dir.'/identity.age', '--passphrase-file', $passphrase, '--json']);
    $enable = $this->journey->fsa($this->dir, ['config', 'set', 'backup-encryption', 'on', '--identity-out', $identity, '--passphrase-file', $passphrase, '--json']);
    $backup = $this->journey->fsa($this->dir, ['backup', '--json']);
    $json = InstanceJourney::json($backup);
    $file = ($json['data']['path'] ?? '').'/backup.tar.age';
    $decrypted = j08Decrypt($this->journey, $identity, $passphrase, $file);
    $refused = j08Decrypt($this->journey, $identity, $wrong, $file);
    $this->journey->evidence('J08-backup-encrypted.json', ['commands' => ['fsa config set backup-encryption on --identity-out <work>/j08-identity.age --passphrase-file <work>/j08-passphrase.txt --json', 'fsa backup --json'],
        'identity_inside_instance' => InstanceJourney::json($inside), 'enable' => InstanceJourney::json($enable), 'backup' => $json, 'decrypted_members' => array_values(array_filter(explode("\n", trim($decrypted->getOutput())))), 'wrong_passphrase_exit' => $refused->getExitCode()]);

    expect($inside->getExitCode())->toBe(2)->and(InstanceJourney::json($inside)['errors'][0]['code'] ?? null)->toBe('identity_inside_instance')
        ->and($enable->getExitCode())->toBe(0, $enable->getErrorOutput()."\n".$enable->getOutput())
        ->and(InstanceJourney::json($enable)['data']['services']['recreated'] ?? null)->toBe(['backup'])
        ->and(substr(sprintf('%o', fileperms($identity)), -3))->toBe('600')
        ->and((string) file_get_contents($identity))->not->toContain('AGE-SECRET-KEY')
        ->and($backup->getExitCode())->toBe(0, $backup->getErrorOutput()."\n".$backup->getOutput())
        ->and($json['data']['encrypted'])->toBeTrue()
        ->and(j08Files(dirname($file)))->toBe(['backup.tar.age'])
        ->and($decrypted->getExitCode())->toBe(0, $decrypted->getErrorOutput())
        ->and(array_map(static fn (string $line): string => preg_replace('#^\./#', '', $line) ?? $line, array_values(array_filter(explode("\n", trim($decrypted->getOutput()))))))
        ->toBe(['SHA256SUMS', 'SHA256SUMS.hmac', 'audit-heads.json', 'database.dump', 'manifest.json', 'settings.json', 'storage.tar.gz'])
        ->and($refused->getExitCode())->not->toBe(0)
        ->and($enable->getOutput().$enable->getErrorOutput().$backup->getOutput())->not->toContain(J08_PASSPHRASE);
});

it('skips a scheduled run while an operation holds the lock, retries it, prunes by retention and records every run', function (): void {
    $uuid = (string) $GLOBALS['fsaJ08Uuid'];
    $retention = $this->journey->fsa($this->dir, ['config', 'set', 'backup-retention', '1', '--json']);
    $old = $this->dir.'/backups/scheduled-20261001T033000Z';
    mkdir($old, 0700);
    file_put_contents($old.'/database.dump', 'an old scheduled backup');
    [$schedule, $slot] = j08ScheduleSoon($this);
    $lock = new InstanceLock(InstanceDirectory::open($this->dir), new RecordingDockerGateway);
    $postgres = json_decode(j08Docker(['inspect', j08Container($uuid, 'postgres')])->getOutput(), true)[0];
    $held = $lock->acquire(new LockHolder('fsa', $postgres['Id'], $postgres['State']['StartedAt'], 1, 'fsa-test-j08', posix_getuid()), 'fsa-test-hold');
    $skip = j08WaitFor($this->dir, static fn (array $run): bool => $run['kind'] === 'scheduled' && $run['status'] === 'skipped', $slot->addMinutes(3));
    $status = InstanceJourney::json($this->journey->fsa($this->dir, ['status', '--json']));
    $lock->release($held);
    $GLOBALS['fsaJ08Reader'] = CgroupReader::start($this->journey->release->appReference);
    [$success, $samples] = j08WaitAndSample($this->dir, j08Container($uuid, 'backup'), CarbonImmutable::now('UTC')->addMinutes(4));
    $cgroup = BudgetJourney::cgroup(j08Container($uuid, 'backup'));
    $runs = trim(j08Docker(['exec', j08Container($uuid, 'postgres'), 'psql', '-U', 'form_schema', '-d', 'form_schema', '-tAc', "SELECT kind || ':' || status || ':' || coalesce(reason, '') FROM backup_runs WHERE kind <> 'service' ORDER BY started_at"])->getOutput());
    $starts = trim(j08Docker(['exec', j08Container($uuid, 'postgres'), 'psql', '-U', 'form_schema', '-d', 'form_schema', '-tAc', "SELECT status || ':' || coalesce(schedule, '') FROM backup_runs WHERE kind = 'service' ORDER BY started_at DESC LIMIT 1"])->getOutput());
    $after = InstanceJourney::json($this->journey->fsa($this->dir, ['status', '--json']));
    j08RecordScheduled($this, ['retention' => $retention, 'schedule' => $schedule, 'skip' => $skip, 'status' => $status, 'success' => $success, 'runs' => $runs, 'after' => $after], $samples, $cgroup);
    $newest = $this->dir.'/backups/'.($success['backup'] ?? 'none');

    expect($retention->getExitCode())->toBe(0)->and($schedule->getExitCode())->toBe(0, $schedule->getOutput())
        ->and($skip)->toMatchArray(['status' => 'skipped', 'reason' => 'lock_held', 'recorded' => true])
        ->and($status['data']['backup']['last']['status'] ?? null)->toBe('skipped')
        ->and($status['data']['backup']['alerts'] ?? null)->toBe([])
        ->and($success)->toMatchArray(['kind' => 'scheduled', 'status' => 'succeeded', 'encrypted' => true, 'pruned' => ['scheduled-20261001T033000Z']])
        ->and(count(array_filter(j08Ledger($this->dir), static fn (array $run): bool => $run['status'] === 'skipped')))->toBe(1)
        ->and(is_dir($old))->toBeFalse()
        ->and(is_dir($this->dir.'/backups/'.$GLOBALS['fsaJ08Manual']))->toBeTrue()
        ->and(j08Files($newest))->toBe(['backup.tar.age'])
        ->and(array_values(array_unique(array_column(j08Modes($newest), 1))))->toBe([posix_getuid()])
        ->and(explode("\n", $runs))->toBe(['manual:succeeded:', 'manual:succeeded:', 'scheduled:skipped:lock_held', 'scheduled:succeeded:'])
        ->and($starts)->toBe('started:'.(json_decode((string) file_get_contents($this->dir.'/state.json'), true)['settings']['backup_schedule'] ?? '?')) // its start, with the schedule it runs
        ->and($after['data']['last_backup']['backup'] ?? null)->toBe($success['backup'])
        ->and($after['data']['backup']['alerts'] ?? null)->toBe([])
        ->and($cgroup['memory_events']['oom_kill'] ?? null)->toBe(0);
});

it('uninstalls without leaving a labelled container, volume or network behind', function (): void {
    $uuid = (string) $GLOBALS['fsaJ08Uuid'];
    $process = $this->journey->fsa($this->dir, ['uninstall', '--yes', '--json']);
    $this->journey->evidence('J08-uninstall.json', ['command' => 'fsa uninstall --yes --json', 'exit_code' => $process->getExitCode(), 'stdout' => InstanceJourney::json($process), 'labelled_after' => InstanceJourney::labelled($uuid)]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(InstanceJourney::labelled($uuid))->toBe(['containers' => [], 'volumes' => [], 'networks' => []]);
});

/**
 * Sets the daily schedule to a minute a few minutes ahead in the instance's time zone (`config set backup-schedule`
 * recreates the backup service, whose daemon then waits for that slot).
 *
 * @return array{0: Process, 1: CarbonImmutable} the command and the slot (UTC)
 */
function j08ScheduleSoon(object $test): array
{
    $state = json_decode((string) file_get_contents($test->dir.'/state.json'), true);
    $zone = is_string($state['settings']['time_zone'] ?? null) ? $state['settings']['time_zone'] : 'UTC';
    $slot = CarbonImmutable::now($zone)->addMinutes(4)->startOfMinute();
    $process = $test->journey->fsa($test->dir, ['config', 'set', 'backup-schedule', $slot->format('H:i'), '--json']);

    return [$process, $slot->utc()];
}

/**
 * Waits for a ledger line that matches.
 *
 * @param  Closure(array<string, mixed>): bool  $matches
 * @return array<string, mixed>
 */
function j08WaitFor(string $dir, Closure $matches, CarbonImmutable $until): array
{
    while (CarbonImmutable::now('UTC')->lessThan($until)) {
        $run = array_find(array_reverse(j08Ledger($dir)), $matches);
        if ($run !== null) {
            return $run;
        }
        usleep(500_000);
    }

    return [];
}

/**
 * Waits for the scheduled success while sampling the backup service's unreclaimable memory every second.
 *
 * @return array{0: array<string, mixed>, 1: list<array{at: string, anon: float, shmem: float, file: float}>}
 */
function j08WaitAndSample(string $dir, string $container, CarbonImmutable $until): array
{
    $samples = [];
    $full = trim(j08Docker(['inspect', '-f', '{{.Id}}', $container])->getOutput());
    while (CarbonImmutable::now('UTC')->lessThan($until)) {
        $read = $GLOBALS['fsaJ08Reader']?->read([$full])[$full] ?? null;
        if ($read !== null) {
            $samples[] = ['at' => CarbonImmutable::now('UTC')->format('H:i:s'), ...$read];
        }
        $run = array_find(array_reverse(j08Ledger($dir)), static fn (array $run): bool => $run['kind'] === 'scheduled' && $run['status'] === 'succeeded');
        if ($run !== null) {
            return [$run, $samples];
        }
        usleep(1_000_000);
    }

    return [[], $samples];
}

/**
 * @param  array<string, mixed>  $steps
 * @param  list<array<string, mixed>>  $samples
 * @param  array<string, mixed>  $cgroup
 */
function j08RecordScheduled(object $test, array $steps, array $samples, array $cgroup): void
{
    $unreclaimable = $samples === [] ? null : max(array_map(static fn (array $sample): float => round($sample['anon'] + $sample['shmem'], 1), $samples));
    $peak = (float) ($cgroup['memory_peak_mib'] ?? 0);
    $test->journey->evidence('J08-backup-scheduled.json', [
        'retention' => InstanceJourney::json($steps['retention']), 'schedule' => InstanceJourney::json($steps['schedule']), 'skipped_run' => $steps['skip'],
        'status_while_locked' => $steps['status']['data']['backup'] ?? null, 'scheduled_run' => $steps['success'], 'backup_runs' => explode("\n", $steps['runs']),
        'status_after' => $steps['after']['data']['backup'] ?? null, 'ledger' => j08Ledger($test->dir),
    ]);
    $test->journey->evidence('J08-backup-memory.json', [
        'service' => 'backup', 'limit_mib' => 128, 'memory_peak_mib' => $peak, 'unreclaimable_peak_mib' => $unreclaimable,
        'margin_rule' => 'peak + 30 MiB <= 128 MiB (ADR 0083)', 'fits' => $peak + 30 <= 128, 'fits_unreclaimable' => $unreclaimable !== null && $unreclaimable + 30 <= 128,
        'cgroup' => $cgroup, 'samples' => $samples, 'note' => 'memory.peak covers the container since its start (the daemon idle and the scheduled, encrypted run: PHP, pg_dump, tar/gzip, age); unreclaimable = anon + shmem, sampled every second during the run',
    ]);
}
