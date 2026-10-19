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
use Tests\Support\Docker\RestoreJourney;
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
 * The restore half (plan U17, ADR 0085): the manual backup restored in place (data written later is gone, the security
 * state carried, numbering advanced, the audit grants re-applied, a fresh storage volume, the instance healthy), a drill
 * that leaves the live database, storage and mail log alone, the encrypted backup restored with its identity and a
 * passphrase file, hostile archives refused (a changed file with recomputed sums: HMAC; a `../` member: before anything
 * is written), a backup with a newer migration set refused (exit 7, AE8), `fsa install --from-backup` into a second
 * directory with a new `--domain` (certificate pending, capture mail, paused webhooks, the new URL, the old data, the
 * recipients kept with `--keep-recipients`), whose `fsa uninstall --keep-backups` leaves an encrypted 2.0 final backup
 * that a real `fsa install --from-backup` takes into a fourth directory (M12 gate), and a 1.0 `scripts/backup.sh` archive
 * with `--accept-unauthenticated` into a third (the unsafe 1.0 values never applied).
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
        InstanceJourney::cleanup($GLOBALS['fsaJ08']->work.'/fsa-test-j08b');
        InstanceJourney::cleanup($GLOBALS['fsaJ08']->work.'/fsa-test-j08c');
        InstanceJourney::cleanup($GLOBALS['fsaJ08']->work.'/fsa-test-j08d');
    }
    $GLOBALS['fsaJ08Reader']?->stop();
    unset($GLOBALS['fsaJ08'], $GLOBALS['fsaJ08Error'], $GLOBALS['fsaJ08Uuid'], $GLOBALS['fsaJ08Reader'], $GLOBALS['fsaJ08Manual'], $GLOBALS['fsaJ08Encrypted'], $GLOBALS['fsaJ08Final']);
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
    $GLOBALS['fsaJ08Encrypted'] = $json['data']['backup'] ?? null; // the encrypted restore below uses it (U17)
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

it('restores the manual backup in place: later data gone, security state carried, numbering advanced, grants back, healthy', function (): void {
    $uuid = (string) $GLOBALS['fsaJ08Uuid'];
    $hash = '$2y$12$'.str_repeat('j', 53); // a password changed after the backup
    RestoreJourney::sql($uuid, "INSERT INTO organizations (name, slug, created_at, updated_at) VALUES ('After Backup', 'fsa-test-after-backup', now(), now())");
    RestoreJourney::sql($uuid, "UPDATE users SET password = '{$hash}' WHERE email = 'owner@example.test'");
    RestoreJourney::sql($uuid, "INSERT INTO sessions (id, user_id, payload, last_activity) SELECT 'fsa-test-session', id, 'x', 0 FROM users WHERE email = 'owner@example.test'");
    RestoreJourney::sql($uuid, "INSERT INTO number_sequences (organization_id, form_id, period, next_value, created_at, updated_at) SELECT organization_id, id, 'fsa-test', 77, now(), now() FROM forms ORDER BY id LIMIT 1");
    $volume = RestoreJourney::storageVolume($uuid);
    $started = time() - 1; // sessions the restored instance creates itself (the smoke test's GET /admin/login) are newer
    $process = $this->journey->fsa($this->dir, ['restore', (string) $GLOBALS['fsaJ08Manual'], '--yes', '--json']);
    $json = InstanceJourney::json($process);
    $status = InstanceJourney::json($this->journey->fsa($this->dir, ['status', '--json']));
    $checks = [
        'after_backup_org' => RestoreJourney::sql($uuid, "SELECT count(*) FROM organizations WHERE slug = 'fsa-test-after-backup'"),
        'password_carried' => RestoreJourney::sql($uuid, "SELECT password = '{$hash}' FROM users WHERE email = 'owner@example.test'"),
        'sessions' => RestoreJourney::sql($uuid, "SELECT count(*) FROM sessions WHERE last_activity < {$started}"),
        'login_links' => RestoreJourney::sql($uuid, 'SELECT count(*) FROM login_links'),
        'numbering' => RestoreJourney::sql($uuid, "SELECT next_value FROM number_sequences WHERE period = 'fsa-test'"),
        'epoch' => RestoreJourney::sql($uuid, 'SELECT max(epoch) FROM audit_chain_epochs'),
        'restored_entries' => RestoreJourney::sql($uuid, "SELECT count(*) FROM activity_log WHERE event = 'instance.restored'"),
        'backup_role_may_seal' => RestoreJourney::sql($uuid, "SELECT has_function_privilege('fsa_backup', 'audit_seal(bigint,bigint,character,character)', 'EXECUTE')"),
        'public_acl' => RestoreJourney::sql($uuid, "SELECT coalesce(proacl::text, 'default') FROM pg_proc WHERE proname = 'audit_seal'"),
        'file_from_backup' => trim(RestoreJourney::docker(['exec', RestoreJourney::container($uuid, 'app'), 'cat', 'storage/app/private/fsa-test-j08/file.txt'])->getOutput()),
        'volume_after' => RestoreJourney::storageVolume($uuid),
        'old_volume_exists' => RestoreJourney::docker(['volume', 'inspect', $volume])->isSuccessful(),
        'runtime_webhooks' => json_decode((string) file_get_contents($this->dir.'/runtime/runtime.json'), true)['webhooks'] ?? null, // paused until confirmed (review A15)
    ];
    $this->journey->evidence('J08-restore-in-place.json', ['command' => 'fsa restore <manual backup> --yes --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'checks' => $checks, 'status' => $status['data']['restore'] ?? null]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data'])->toMatchArray(['backup' => $GLOBALS['fsaJ08Manual'], 'authenticated' => true, 'epoch' => 2])
        ->and($checks)->toMatchArray(['after_backup_org' => '0', 'password_carried' => 't', 'sessions' => '0', 'login_links' => '0', 'numbering' => '77', 'epoch' => '2', 'restored_entries' => '1', 'backup_role_may_seal' => 'f', 'file_from_backup' => 'secret', 'old_volume_exists' => false])
        ->and($checks['public_acl'])->not->toMatch('/(^\{|,)=X\//')->not->toBe('default') // no PUBLIC entry (`=X/owner`)
        ->and($checks['volume_after'])->not->toBe($volume)->toContain('_app-storage-r')
        ->and($checks['runtime_webhooks'])->toBe(['paused' => true])->and($json['data']['webhooks'] ?? null)->toBe('paused')
        ->and($json['data']['valkey_flushed'] ?? null)->toBeTrue() // VALKEY_EXTRA_FLAGS emptied for the one-off
        ->and($json['data']['carry_mode'] ?? null)->toBe('state') // the live database descends from the backup (carry window)
        ->and($status['data']['health'] ?? null)->toBe('ok')
        ->and($status['data']['last_restore']['backup'] ?? null)->toBe($GLOBALS['fsaJ08Manual']);
});

it('drills a backup without touching the live database, storage or mail log', function (): void {
    $uuid = (string) $GLOBALS['fsaJ08Uuid'];
    $live = static fn (): array => [
        'organizations' => RestoreJourney::sql($uuid, 'SELECT count(*) FROM organizations'), 'mail_messages' => RestoreJourney::sql($uuid, 'SELECT count(*) FROM mail_messages'),
        'files' => trim(RestoreJourney::docker(['exec', RestoreJourney::container($uuid, 'app'), 'sh', '-c', 'find storage/app -type f | wc -l'])->getOutput()),
    ];
    // A submitted submission to render (the demo data has none), in a backup of its own.
    RestoreJourney::sql($uuid, "INSERT INTO submissions (uuid, organization_id, form_id, form_version_id, status, data, revision, number, created_by, submitted_by, submitted_at, created_at, updated_at) SELECT gen_random_uuid(), f.organization_id, f.id, v.id, 'submitted', '{}'::jsonb, 2, 'FSA-TEST-1', u.id, u.id, now(), now(), now() FROM forms f JOIN form_versions v ON v.form_id = f.id JOIN users u ON u.email = 'owner@example.test' ORDER BY v.id DESC LIMIT 1");
    $backup = InstanceJourney::json($this->journey->fsa($this->dir, ['backup', '--json']));
    $before = $live();
    $process = $this->journey->fsa($this->dir, ['restore', (string) ($backup['data']['backup'] ?? ''), '--drill', '--identity-file', $this->journey->work.'/j08-identity.age', '--passphrase-file', $this->journey->work.'/j08-passphrase.txt', '--json']);
    $json = InstanceJourney::json($process);
    $after = $live();
    $left = RestoreJourney::sql($uuid, "SELECT count(*) FROM pg_database WHERE datname LIKE 'restore_drill_%'");
    $this->journey->evidence('J08-restore-drill.json', ['command' => 'fsa restore <encrypted backup> --drill --identity-file <work>/j08-identity.age --passphrase-file <work>/j08-passphrase.txt --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'live_before' => $before, 'live_after' => $after, 'drill_databases_left' => $left]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['changed'])->toBeFalse()
        ->and((int) ($json['data']['drill']['counts']['organizations'] ?? 0))->toBeGreaterThan(0)
        ->and($json['data']['drill']['render']['status'] ?? null)->toBe('rendered', json_encode($json['data']['drill']['render'] ?? null))
        ->and((int) ($json['data']['drill']['render']['bytes'] ?? 0))->toBeGreaterThan(0)
        ->and($json['data']['drill']['audit']['heads'] ?? null)->not->toBeNull()
        ->and($after)->toBe($before)->and($left)->toBe('0');
});

it('restores the encrypted backup with its identity file and a passphrase file', function (): void {
    $process = $this->journey->fsa($this->dir, ['restore', (string) $GLOBALS['fsaJ08Encrypted'], '--identity-file', $this->journey->work.'/j08-identity.age', '--passphrase-file', $this->journey->work.'/j08-passphrase.txt', '--yes', '--json']);
    $json = InstanceJourney::json($process);
    $this->journey->evidence('J08-restore-encrypted.json', ['command' => 'fsa restore <encrypted backup> --identity-file <work>/j08-identity.age --passphrase-file <work>/j08-passphrase.txt --yes --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data'])->toMatchArray(['encrypted' => true, 'authenticated' => true])
        ->and($json['data']['carry_mode'] ?? null)->toBe('audit') // the live database was restored after this backup was made (carry window)
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain(J08_PASSPHRASE);
});

it('refuses hostile archives: a changed file with recomputed sums (HMAC), a ../ member (before anything is written)', function (): void {
    $manual = $this->dir.'/backups/'.$GLOBALS['fsaJ08Manual'];
    RestoreJourney::tampered($manual, $this->dir.'/backups/manual-20261019T000001Z');
    RestoreJourney::escaping($manual, $this->dir.'/backups/manual-20261019T000002Z', j08HmacKey($this->dir));
    $tampered = $this->journey->fsa($this->dir, ['restore', 'manual-20261019T000001Z', '--yes', '--json']);
    $escaping = $this->journey->fsa($this->dir, ['restore', 'manual-20261019T000002Z', '--yes', '--json']);
    $escaped = array_filter([dirname($this->dir).'/fsa-test-j08-escaped', $this->dir.'/fsa-test-j08-escaped', $this->dir.'/backups/fsa-test-j08-escaped'], file_exists(...));
    $this->journey->evidence('J08-restore-hostile.json', ['tampered' => ['exit_code' => $tampered->getExitCode(), 'stdout' => InstanceJourney::json($tampered)], 'escaping' => ['exit_code' => $escaping->getExitCode(), 'stdout' => InstanceJourney::json($escaping)], 'escaped_files' => array_values($escaped)]);
    exec('rm -rf '.escapeshellarg($this->dir.'/backups/manual-20261019T000001Z').' '.escapeshellarg($this->dir.'/backups/manual-20261019T000002Z'));

    expect($tampered->getExitCode())->toBe(2)->and(InstanceJourney::json($tampered)['errors'][0]['code'] ?? null)->toBe('backup_unauthenticated')
        ->and($escaping->getExitCode())->toBe(2)->and(InstanceJourney::json($escaping)['errors'][0]['code'] ?? null)->toBe('backup_unsafe')
        ->and($escaped)->toBe([])->and(glob($this->dir.'/backups/.restore-*'))->toBe([])->and(glob($this->dir.'/staging/restore-*'))->toBe([]);
});

it('refuses a backup with a newer migration set: exit 7 and nothing changed (AE8)', function (): void {
    $uuid = (string) $GLOBALS['fsaJ08Uuid'];
    RestoreJourney::sql($uuid, "INSERT INTO migrations (migration, batch) VALUES ('2099_01_01_000000_fsa_test_from_the_future', 99)");
    $backup = InstanceJourney::json($this->journey->fsa($this->dir, ['backup', '--json']));
    RestoreJourney::sql($uuid, "DELETE FROM migrations WHERE migration = '2099_01_01_000000_fsa_test_from_the_future'");
    $state = (string) file_get_contents($this->dir.'/state.json');
    $starts = InstanceJourney::startTimes($uuid);
    $process = $this->journey->fsa($this->dir, ['restore', (string) ($backup['data']['backup'] ?? ''), '--identity-file', $this->journey->work.'/j08-identity.age', '--passphrase-file', $this->journey->work.'/j08-passphrase.txt', '--yes', '--json']);
    $json = InstanceJourney::json($process);
    $this->journey->evidence('J08-restore-newer.json', ['command' => 'fsa restore <backup with a newer migration set> --yes --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json]);

    expect($process->getExitCode())->toBe(7, $process->getOutput())->and($json['errors'][0]['code'] ?? null)->toBe('backup_newer')
        ->and($json['changed'])->toBeFalse()
        ->and((string) file_get_contents($this->dir.'/state.json'))->toBe($state)
        ->and(InstanceJourney::startTimes($uuid))->toBe($starts);
});

it('moves the instance with --from-backup into a second directory with a new --domain', function (): void {
    $backup = InstanceJourney::json($this->journey->fsa($this->dir, ['backup', '--include-secrets', '--json']));
    $target = $this->journey->work.'/fsa-test-j08b';
    $process = $this->journey->install($target, [
        '--from-backup', ($backup['data']['path'] ?? '').'/backup.tar.age', '--identity-file', $this->journey->work.'/j08-identity.age',
        '--passphrase-file', $this->journey->work.'/j08-passphrase.txt', '--app-key-file', $this->dir.'/env/app.env', '--domain', 'fsa-test-j08b.test', '--keep-recipients', '--json',
    ], ['FSA_TEST_DNS' => 'fsa-test-j08b.test=203.0.113.7']);
    $json = InstanceJourney::json($process);
    $uuid = (string) InstanceJourney::uuid($target);
    $state = json_decode((string) @file_get_contents($target.'/state.json'), true) ?: [];
    $runtime = json_decode((string) @file_get_contents($target.'/runtime/runtime.json'), true) ?: [];
    $orgs = RestoreJourney::sql($uuid, "SELECT count(*) FROM organizations WHERE name = 'Backup Org'");
    $sameKey = j08HmacKey($target) === j08HmacKey($this->dir);
    $env = (string) @file_get_contents($target.'/env/app.env'); // read before the uninstall removes the directory
    $this->journey->evidence('J08-from-backup-domain.json', ['command' => 'install.sh --dir <work>/fsa-test-j08b --from-backup <backup>/backup.tar.age --identity-file … --passphrase-file … --app-key-file <old>/env/app.env --domain fsa-test-j08b.test --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'url' => $state['url'] ?? null, 'runtime_webhooks' => $runtime['webhooks'] ?? null, 'organizations' => $orgs]);
    $uninstall = $this->journey->fsa($target, ['uninstall', '--yes', '--keep-backups', '--json']);
    $final = InstanceJourney::json($uninstall)['data']['final_backup'] ?? [];
    $this->journey->evidence('J08-uninstall-final.json', ['command' => 'fsa uninstall --yes --keep-backups --json', 'exit_code' => $uninstall->getExitCode(), 'stdout' => InstanceJourney::json($uninstall)]);
    $GLOBALS['fsaJ08Final'] = is_string($final['dir'] ?? null) ? $target.'/'.$final['dir'] : null;

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and(collect($json['warnings'] ?? [])->pluck('code')->all())->toContain('certificate_pending', 'webhooks_paused')
        ->and($state['url'] ?? null)->toBe('https://fsa-test-j08b.test')
        ->and($env)->toContain("FSA_MAIL_CAPTURE='true'")
        ->and($runtime['webhooks'] ?? null)->toBe(['paused' => true])
        ->and($orgs)->toBe('1')->and($sameKey)->toBeTrue()
        ->and($json['data']['from_backup']['recipients']['kept'] ?? [])->not->toBeEmpty() // --keep-recipients (M12 gate S2)
        ->and($uninstall->getExitCode())->toBe(0, $uninstall->getErrorOutput()."\n".$uninstall->getOutput())
        ->and($final)->toMatchArray(['encrypted' => true, 'includes_secrets' => true, 'key_file' => null])
        ->and(is_file($target.'/'.($final['dir'] ?? '-').'/backup.tar.age'))->toBeTrue()
        ->and(glob($target.'/staging') ?: [])->toBe([]); // no plaintext left beside backups/
});

it('installs a fourth instance from uninstall\'s encrypted final backup with a real fsa install --from-backup (M12 gate)', function (): void {
    $final = $GLOBALS['fsaJ08Final'] ?? null;
    expect($final)->toBeString('the previous step left no final backup');
    $target = $this->journey->work.'/fsa-test-j08d';
    $process = $this->journey->install($target, [
        '--from-backup', $final.'/backup.tar.age', '--identity-file', $this->journey->work.'/j08-identity.age', '--passphrase-file', $this->journey->work.'/j08-passphrase.txt',
        '--accept-unauthenticated', '--domain', 'fsa-test-j08d.test', '--json',
    ], ['FSA_TEST_DNS' => 'fsa-test-j08d.test=203.0.113.7']);
    $json = InstanceJourney::json($process);
    $uuid = (string) InstanceJourney::uuid($target);
    $orgs = RestoreJourney::sql($uuid, "SELECT count(*) FROM organizations WHERE name = 'Backup Org'");
    $this->journey->evidence('J08-from-final-backup.json', ['command' => 'install.sh --dir <work>/fsa-test-j08d --from-backup <j08b>/backups/final-…/backup.tar.age --identity-file … --passphrase-file … --accept-unauthenticated --domain fsa-test-j08d.test --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'organizations' => $orgs]);
    $uninstall = $this->journey->fsa($target, ['uninstall', '--yes', '--json']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data']['from_backup']['backup'] ?? null)->toStartWith('final-')
        ->and($json['data']['from_backup']['format'] ?? null)->toBe('fsa')
        ->and($orgs)->toBe('1')
        ->and($uninstall->getExitCode())->toBe(0);
});

it('installs from a 1.0 scripts/backup.sh archive only with --accept-unauthenticated and never applies its unsafe values', function (): void {
    $uuid = (string) $GLOBALS['fsaJ08Uuid'];
    preg_match("/^APP_KEY='?(base64:[A-Za-z0-9+\\/=]+)'?$/m", (string) file_get_contents($this->dir.'/env/app.env'), $key);
    $legacy = RestoreJourney::legacy($uuid, $this->journey->work.'/j08-legacy/20261019T020000Z', $key[1] ?? '');
    $target = $this->journey->work.'/fsa-test-j08c';
    $refused = $this->journey->install($target, ['--from-backup', $legacy, '--json']);
    InstanceJourney::cleanup($target);
    $process = $this->journey->install($target, ['--from-backup', $legacy, '--accept-unauthenticated', '--json']);
    $json = InstanceJourney::json($process);
    $env = (string) @file_get_contents($target.'/env/app.env');
    $runtime = json_decode((string) @file_get_contents($target.'/runtime/runtime.json'), true) ?: [];
    $orgs = RestoreJourney::sql((string) InstanceJourney::uuid($target), "SELECT count(*) FROM organizations WHERE name = 'Backup Org'");
    $this->journey->evidence('J08-from-backup-legacy.json', ['commands' => ['install.sh --dir <work>/fsa-test-j08c --from-backup <1.0 archive> --json', 'install.sh --dir <work>/fsa-test-j08c --from-backup <1.0 archive> --accept-unauthenticated --json'],
        'refused' => ['exit_code' => $refused->getExitCode(), 'stdout' => InstanceJourney::json($refused)], 'exit_code' => $process->getExitCode(), 'stdout' => $json,
        'forced' => ['APP_DEBUG' => preg_match("/^APP_DEBUG='false'$/m", $env) === 1, 'SSO_ALLOW_INSECURE_IDP' => preg_match("/^SSO_ALLOW_INSECURE_IDP='false'$/m", $env) === 1, 'TRUSTED_PROXIES' => str_contains($env, 'TRUSTED_PROXIES')], 'runtime_webhooks' => $runtime['webhooks'] ?? null, 'organizations' => $orgs]);
    $uninstall = $this->journey->fsa($target, ['uninstall', '--yes', '--json']);
    exec('rm -rf '.escapeshellarg($this->journey->work.'/j08-legacy'));

    expect($refused->getExitCode())->toBe(2)->and(InstanceJourney::json($refused)['errors'][0]['code'] ?? null)->toBe('backup_unauthenticated')
        ->and($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($env)->toMatch("/^APP_DEBUG='false'$/m")->toMatch("/^SSO_ALLOW_INSECURE_IDP='false'$/m")->not->toContain('TRUSTED_PROXIES')->not->toContain('never-read-by-fsa')
        ->and($env)->toContain($key[1] ?? 'no key')
        ->and($runtime['webhooks'] ?? null)->toBe(['paused' => true])
        ->and($orgs)->toBe('1')
        ->and($uninstall->getExitCode())->toBe(0);
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
