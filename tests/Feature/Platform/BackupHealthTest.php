<?php

declare(strict_types=1);

use App\Domain\Platform\Health\Checks\BackupCheck;
use App\Domain\Platform\Health\HealthStatus;
use App\Domain\Platform\Instance\BackupRole;
use App\Domain\Platform\Instance\BackupRuns;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * The app side of backups (R39, plan U16, ADR 0084): the backup service's database role may read everything and insert
 * only into backup_runs; /platform reads the runs and raises the alerts. The role is created inside the test's
 * transaction under a name of its own (roles are cluster-wide), so it never outlives the test.
 */

const BACKUP_HEALTH_PASSWORD = '00112233445566778899aabbccddeeff0011223344556677';

/** @param  array<string, mixed>  $row */
function backupRun(array $row): void
{
    DB::table('backup_runs')->insert([
        'kind' => 'scheduled', 'status' => 'succeeded', 'started_at' => now(), 'finished_at' => now(), 'backup' => 'scheduled-20261019T033000Z',
        'bytes' => 2048, 'encrypted' => false, ...$row,
    ]);
}

/** Runs a statement as the role in a savepoint; true when Postgres allowed it. */
function asBackupRole(string $role, string $sql): bool
{
    try {
        DB::transaction(function () use ($role, $sql): void {
            DB::statement("SET LOCAL ROLE {$role}");
            DB::statement($sql);
            DB::statement('RESET ROLE'); // a released savepoint keeps SET LOCAL until the test's transaction ends
        });

        return true;
    } catch (QueryException) {
        return false;
    }
}

it('creates the backup role with a SCRAM verifier, read-all and insert-only rights', function (): void {
    $role = 'fieldset_backup_test_'.bin2hex(random_bytes(3));
    makeOrganization();

    $first = new BackupRole()->ensure(BACKUP_HEALTH_PASSWORD, $role);
    $again = new BackupRole()->ensure(BACKUP_HEALTH_PASSWORD, $role);
    $stored = (string) DB::scalar('SELECT rolpassword FROM pg_authid WHERE rolname = ?', [$role]);
    $attributes = DB::selectOne('SELECT rolcanlogin, rolsuper, rolcreatedb, rolcreaterole FROM pg_roles WHERE rolname = ?', [$role]);

    expect($first)->toBe(['role' => $role, 'created' => true, 'granted' => ['pg_read_all_data', 'connect', 'insert:backup_runs']])
        ->and($again['created'])->toBeFalse()
        ->and($stored)->toStartWith('SCRAM-SHA-256$4096:')->not->toContain(BACKUP_HEALTH_PASSWORD)
        ->and([$attributes->rolcanlogin, $attributes->rolsuper, $attributes->rolcreatedb, $attributes->rolcreaterole])->toBe([true, false, false, false])
        ->and(asBackupRole($role, 'SELECT count(*) FROM organizations'))->toBeTrue()
        ->and(asBackupRole($role, 'SELECT count(*) FROM activity_log'))->toBeTrue()
        ->and(asBackupRole($role, "INSERT INTO backup_runs (kind, status, started_at) VALUES ('scheduled', 'succeeded', now())"))->toBeTrue()
        ->and(asBackupRole($role, "UPDATE backup_runs SET status = 'failed'"))->toBeFalse()
        ->and(asBackupRole($role, 'DELETE FROM backup_runs'))->toBeFalse()
        ->and(asBackupRole($role, "UPDATE organizations SET name = 'x'"))->toBeFalse()
        ->and(asBackupRole($role, 'CREATE TABLE fieldset_backup_probe (id int)'))->toBeFalse();
});

it('takes back rights granted to an existing backup role by hand', function (): void {
    $role = 'fieldset_backup_test_'.bin2hex(random_bytes(3));
    makeOrganization();
    new BackupRole()->ensure(BACKUP_HEALTH_PASSWORD, $role);
    DB::statement("GRANT UPDATE ON organizations TO {$role}");
    DB::statement("GRANT DELETE ON backup_runs TO {$role}");
    DB::statement("GRANT pg_write_all_data TO {$role}");
    DB::statement("GRANT CREATE ON SCHEMA public TO {$role}");
    $granted = asBackupRole($role, "UPDATE organizations SET name = 'x'");

    new BackupRole()->ensure(BACKUP_HEALTH_PASSWORD, $role);

    expect($granted)->toBeTrue()
        ->and(asBackupRole($role, "UPDATE organizations SET name = 'x'"))->toBeFalse()
        ->and(asBackupRole($role, 'DELETE FROM backup_runs'))->toBeFalse()
        ->and(asBackupRole($role, 'CREATE TABLE fieldset_backup_probe (id int)'))->toBeFalse()
        ->and(asBackupRole($role, 'SELECT count(*) FROM organizations'))->toBeTrue()
        ->and(asBackupRole($role, "INSERT INTO backup_runs (kind, status, started_at) VALUES ('scheduled', 'succeeded', now())"))->toBeTrue()
        ->and(DB::scalar('SELECT count(*) FROM pg_auth_members m JOIN pg_roles r ON r.oid = m.roleid JOIN pg_roles u ON u.oid = m.member WHERE u.rolname = ?', [$role]))->toBe(1);
});

it('computes the SCRAM-SHA-256 verifier Postgres stores (RFC 7677 test vector)', function (): void {
    // RFC 7677 section 3: user "user", password "pencil", salt W22ZaJ0SNY7soEsUEjb6gQ== and 4096 iterations give
    // the server signature of the example exchange; StoredKey and ServerKey follow from the same SaltedPassword.
    $verifier = BackupRole::scramVerifier('pencil', base64_decode('W22ZaJ0SNY7soEsUEjb6gQ=='));
    [, $keys] = explode('$', substr($verifier, strlen('SCRAM-SHA-256$')), 2);
    [$storedKey, $serverKey] = explode(':', $keys);
    $salted = hash_pbkdf2('sha256', 'pencil', base64_decode('W22ZaJ0SNY7soEsUEjb6gQ=='), 4096, 32, true);
    $authMessage = 'n=user,r=rOprNGfwEbeRWgbNEkqO,r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096,c=biws,r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0';

    expect($verifier)->toStartWith('SCRAM-SHA-256$4096:W22ZaJ0SNY7soEsUEjb6gQ==$')
        ->and(base64_encode(hash_hmac('sha256', $authMessage, base64_decode($serverKey), true)))->toBe('6rriTRBi23WpRR/wtup+mMhUZUn/dB5nLTJRsjl95G4=')
        ->and(base64_decode($storedKey))->toBe(hash('sha256', hash_hmac('sha256', 'Client Key', $salted, true), true));
});

it('sets the role up from the one-off without printing the password', function (): void {
    putenv(BackupRole::PASSWORD_ENV.'='.BACKUP_HEALTH_PASSWORD); // the role is created inside the test's transaction

    try {
        $code = Artisan::call('instance:backup-role', ['--json' => true]);
        $output = Artisan::output();
    } finally {
        putenv(BackupRole::PASSWORD_ENV);
    }
    $document = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(0)->and($document['status'])->toBe('ok')->and($document['data']['role'])->toBe('fieldset_backup')
        ->and((string) DB::scalar('SELECT rolpassword FROM pg_authid WHERE rolname = ?', ['fieldset_backup']))->toStartWith('SCRAM-SHA-256$')
        ->and($output)->not->toContain(BACKUP_HEALTH_PASSWORD);
});

it('refuses to run the one-off without a password', function (): void {
    putenv(BackupRole::PASSWORD_ENV);

    $code = Artisan::call('instance:backup-role', ['--json' => true]);
    $document = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($code)->toBe(1)->and($document['errors'][0]['code'])->toBe('password_missing');
});

it('reports a failed role set-up without the statement', function (): void {
    expect(fn () => new BackupRole()->ensure('', 'fieldset_backup'))->toThrow(RuntimeException::class, 'needs a lowercase name and a password');
    expect(fn () => DB::transaction(fn () => new BackupRole()->ensure(BACKUP_HEALTH_PASSWORD, 'pg_signal_backend')))->toThrow(RuntimeException::class, 'Could not set up the backup role pg_signal_backend (SQLSTATE');
});

it('is ok without runs and after a success, and names where backups stay', function (): void {
    expect(app(BackupCheck::class)->run()->status)->toBe(HealthStatus::Ok)
        ->and(app(BackupCheck::class)->run()->summary)->toBe(__('platform.health.backup.none'));

    backupRun(['bytes' => 5 * 1024 * 1024, 'encrypted' => true]);
    $result = app(BackupCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->summary)->toContain(__('platform.health.backup.on_host'))
        ->and($result->details[__('platform.health.backup.encrypted')])->toBe(__('platform.health.backup.yes'))
        ->and($result->details)->not->toHaveKey(__('platform.health.backup.missing_files'))
        ->and($result->details)->toHaveKey(__('platform.health.backup.size'));
});

it('warns when the last successful backup misses files the database references (M12 gate D5)', function (): void {
    backupRun(['started_at' => now()->subDay()]);
    backupRun(['missing_files' => 2, 'started_at' => now()]);
    $result = app(BackupCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Warn)
        ->and($result->summary)->toBe(__('platform.health.backup.files_missing', ['count' => 2]))->toContain('2')
        ->and($result->details[__('platform.health.backup.missing_files')])->toBe(2);
});

it('fails after a failed run until a later run succeeds, and warns about skips older than a day', function (): void {
    backupRun(['started_at' => now()->subHours(30)]);
    backupRun(['status' => 'failed', 'reason' => 'pg_dump_failed', 'backup' => null, 'started_at' => now()->subHour()]);

    $failed = app(BackupCheck::class)->run();
    expect($failed->status)->toBe(HealthStatus::Fail)->and($failed->summary)->toContain('pg_dump_failed');

    backupRun(['started_at' => now()]);
    expect(app(BackupCheck::class)->run()->status)->toBe(HealthStatus::Ok);

    DB::table('backup_runs')->delete();
    backupRun(['started_at' => now()->subDays(3)]);
    backupRun(['status' => 'skipped', 'reason' => 'lock_held', 'backup' => null, 'started_at' => now()->subHours(25)]);
    expect(app(BackupCheck::class)->run()->status)->toBe(HealthStatus::Warn);
});

it('says what the last run was when it was a skip, and warns at once about a skip for lack of disk space', function (): void {
    backupRun(['status' => 'skipped', 'reason' => 'lock_held', 'backup' => null, 'bytes' => null, 'started_at' => now()->subMinutes(5)]);
    $retrying = app(BackupCheck::class)->run();

    backupRun(['started_at' => now()->subDay()]);
    backupRun(['status' => 'skipped', 'reason' => 'low_disk_space', 'backup' => null, 'bytes' => null, 'started_at' => now()]);
    $disk = app(BackupCheck::class)->run();

    expect($retrying->status)->toBe(HealthStatus::Ok)
        ->and($retrying->summary)->toBe(__('platform.health.backup.retrying'))->not->toContain(__('platform.health.backup.ok'))
        ->and($disk->status)->toBe(HealthStatus::Warn)
        ->and($disk->summary)->toBe(__('platform.health.backup.disk_space'))->not->toContain('lock');
});

it('notices a backup service that is not running, cannot start, or never reported', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    $start = static fn (string $at, string $status = 'started', ?string $reason = null): array => [
        'kind' => 'service', 'status' => $status, 'reason' => $reason, 'started_at' => CarbonImmutable::parse($at), 'finished_at' => CarbonImmutable::parse($at),
        'backup' => null, 'bytes' => null, 'schedule' => $status === 'started' ? '03:30' : null, 'time_zone' => 'UTC',
    ];

    backupRun($start('2026-10-19T09:00:00Z'));
    $waiting = app(BackupCheck::class)->run();
    backupRun($start('2026-10-18T09:00:00Z'));
    foreach (range(1, BackupRuns::RECENT + 10) as $minute) { // an hour of lock skips each minute never hides the starts
        backupRun(['status' => 'skipped', 'reason' => 'lock_held', 'backup' => null, 'bytes' => null, 'started_at' => CarbonImmutable::parse('2026-10-19T10:00:00Z')->addMinutes($minute)]);
    }
    $overdue = app(BackupCheck::class)->run();
    backupRun($start('2026-10-19T11:59:00Z', 'failed', 'invalid_schedule'));
    $failed = app(BackupCheck::class)->run();
    DB::table('backup_runs')->delete();
    config(['instance.runtime_path' => '/run/fsa']);
    $silent = app(BackupCheck::class)->run();

    expect([$waiting->status, $waiting->summary])->toBe([HealthStatus::Ok, __('platform.health.backup.waiting')])
        ->and($waiting->details[__('platform.health.backup.next_run')]->toIso8601ZuluString())->toBe('2026-10-20T03:30:00Z')
        ->and([$overdue->status, $overdue->summary])->toBe([HealthStatus::Fail, __('platform.health.backup.overdue')])
        ->and($failed->status)->toBe(HealthStatus::Fail)->and($failed->summary)->toContain('invalid_schedule')
        ->and([$silent->status, $silent->summary])->toBe([HealthStatus::Warn, __('platform.health.backup.silent')]);
});

it('summarizes the newest runs for /platform', function (): void {
    backupRun(['started_at' => CarbonImmutable::parse('2026-10-18T03:30:00Z')]);
    backupRun(['kind' => 'manual', 'status' => 'failed', 'reason' => 'storage_failed', 'backup' => null, 'bytes' => null, 'started_at' => CarbonImmutable::parse('2026-10-19T09:00:00Z')]);

    $summary = app(BackupRuns::class)->summary(CarbonImmutable::parse('2026-10-19T10:00:00Z'));

    expect($summary['last'])->toMatchArray(['kind' => 'manual', 'status' => 'failed', 'reason' => 'storage_failed', 'started_at' => '2026-10-19T09:00:00Z'])
        ->and($summary['last_success'])->toMatchArray(['kind' => 'scheduled', 'backup' => 'scheduled-20261019T033000Z', 'bytes' => 2048, 'encrypted' => false])
        ->and(array_column($summary['alerts'], 'code'))->toBe(['backup_failed'])
        ->and($summary['service'])->toBeNull()->and($summary['next_run'])->toBeNull();
});
