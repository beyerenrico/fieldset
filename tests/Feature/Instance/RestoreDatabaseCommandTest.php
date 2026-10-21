<?php

declare(strict_types=1);

use App\Console\Commands\RestoreDatabaseCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\Instance\BackupScratchDatabase;
use Tests\Support\Instance\RestoreDatabases;

/*
 * `instance:restore-db` (plan U17, KTD18, ADR 0085): the database-level steps of a restore on the maintenance database.
 * Every database here is named after the test database (`<test db>_rd…`) and dropped afterwards; roles are unique per
 * run and dropped too.
 */

const RESTORE_DB_PASSWORD = '00ff00ff00ff00ff00ff00ff00ff00ff00ff00ff00ff00ff';

afterEach(function (): void {
    putenv(RestoreDatabaseCommand::PASSWORD_ENV);
    RestoreDatabases::drop(['rdl', 'rdi', 'rda', 'rdn', 'restore_rdr', 'rdo', 'restore_rdx', 'restore_rdy', 'rdd']);
});

/** @return array{0: int, 1: array<string, mixed>} */
function restoreDb(string $action, array $options = []): array
{
    $code = Artisan::call('instance:restore-db', ['action' => $action, '--json' => true, ...$options]);

    return [$code, json_decode(trim(Artisan::output()), true)];
}

function restoreDbQuery(string $database, string $sql): mixed
{
    $config = config('database.connections.pgsql');
    $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$database}", (string) $config['username'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $statements = explode('; ', $sql);
    $last = array_pop($statements);
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    if (! str_starts_with($last, 'SELECT')) {
        $pdo->exec($last);

        return null;
    }

    return $pdo->query($last)->fetchColumn();
}

function restoreDbMarked(string $suffix, string $marker): string
{
    $name = RestoreDatabases::empty($suffix);
    restoreDbQuery($name, "CREATE TABLE marker (v text); INSERT INTO marker VALUES ('{$marker}')");

    return $name;
}

it('creates a scratch database, and refuses one that exists, a system database or a malformed name', function (): void {
    $name = RestoreDatabases::name('rdn');

    [$created, $json] = restoreDb('create', ['--database' => $name]);
    [$again, $exists] = restoreDb('create', ['--database' => $name]);
    [$system, $reserved] = restoreDb('create', ['--database' => 'template1']);
    [$bad, $malformed] = restoreDb('create', ['--database' => 'Bad-Name']);

    expect($created)->toBe(0)->and($json['data'])->toMatchArray(['database' => $name, 'restricted' => false])
        ->and(RestoreDatabases::exists($name))->toBeTrue()
        ->and([$again, $exists['errors'][0]['code']])->toBe([1, 'database_exists'])
        ->and([$system, $reserved['errors'][0]['code']])->toBe([1, 'reserved_database'])
        ->and([$bad, $malformed['errors'][0]['code']])->toBe([1, 'invalid_input']);
});

it('creates a database for a restricted role that only it may connect to, and needs the role password', function (): void {
    $name = RestoreDatabases::name('restore_rdr');
    $role = RestoreDatabases::role('fieldset_restore_test');

    [$missing, $noPassword] = restoreDb('create', ['--database' => $name, '--restricted-role' => $role]);
    putenv(RestoreDatabaseCommand::PASSWORD_ENV.'='.RESTORE_DB_PASSWORD);
    [$code, $json] = restoreDb('create', ['--database' => $name, '--restricted-role' => $role]);
    $attributes = restoreDbQuery('postgres', "SELECT concat_ws(',', rolsuper, rolcreatedb, rolcreaterole, rolcanlogin, left(rolpassword, 14)) FROM pg_authid WHERE rolname = '{$role}'");

    expect([$missing, $noPassword['errors'][0]['code']])->toBe([1, 'password_missing'])
        ->and($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toBe(['database' => $name, 'owner' => $role, 'restricted' => true])
        ->and($attributes)->toBe('f,f,f,t,SCRAM-SHA-256$')
        ->and(restoreDbQuery('postgres', "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '{$name}'"))->toBe($role)
        ->and(restoreDbQuery('postgres', "SELECT has_database_privilege('public', '{$name}', 'CONNECT')"))->toBeFalse()
        ->and(restoreDbQuery('postgres', "SELECT has_database_privilege('{$role}', '{$name}', 'CONNECT')"))->toBeTrue()
        ->and(json_encode($json))->not->toContain(RESTORE_DB_PASSWORD);
});

it('installs pg_trgm as the superuser before a restricted restore, so the role cannot add members to it (review S1)', function (): void {
    $name = RestoreDatabases::name('restore_rdr');
    $role = RestoreDatabases::role('fieldset_restore_test');
    putenv(RestoreDatabaseCommand::PASSWORD_ENV.'='.RESTORE_DB_PASSWORD);

    [$code, $json] = restoreDb('create', ['--database' => $name, '--restricted-role' => $role]);
    $owner = (string) config('database.connections.pgsql.username');
    $added = null;
    try {
        restoreDbQuery($name, "SET ROLE {$role}; CREATE FUNCTION evil() RETURNS text AS \$f\$ SELECT current_user::text \$f\$ LANGUAGE sql; ALTER EXTENSION pg_trgm ADD FUNCTION evil()");
    } catch (PDOException $e) {
        $added = $e->getMessage();
    }

    expect($code)->toBe(0, json_encode($json))
        ->and(restoreDbQuery($name, "SELECT pg_get_userbyid(extowner) FROM pg_extension WHERE extname = 'pg_trgm'"))->toBe($owner)
        ->and($added)->toContain('must be owner of extension pg_trgm');
});

it('adopts a restricted restore: objects and database go to the superuser, the role goes, PUBLIC may connect again', function (): void {
    $name = RestoreDatabases::name('restore_rdr');
    $role = RestoreDatabases::role('fieldset_restore_test');
    putenv(RestoreDatabaseCommand::PASSWORD_ENV.'='.RESTORE_DB_PASSWORD);
    restoreDb('create', ['--database' => $name, '--restricted-role' => $role]);
    restoreDbQuery($name, "SET ROLE {$role}; CREATE TABLE restored (id int); RESET ROLE");
    $owner = (string) config('database.connections.pgsql.username');

    [$code, $json] = restoreDb('adopt', ['--database' => $name, '--role' => $role]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toBe(['database' => $name, 'role' => $role, 'revoked' => 0, 'settings_reset' => 0])
        ->and(restoreDbQuery($name, "SELECT tableowner FROM pg_tables WHERE tablename = 'restored'"))->toBe($owner)
        ->and(restoreDbQuery('postgres', "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '{$name}'"))->toBe($owner)
        ->and(RestoreDatabases::roleExists($role))->toBeFalse()
        ->and(restoreDbQuery('postgres', "SELECT has_database_privilege('public', '{$name}', 'CONNECT')"))->toBeTrue();
});

it('adopts without the grants and database settings the restore left behind (review S2)', function (): void {
    $name = RestoreDatabases::name('restore_rdr');
    $role = RestoreDatabases::role('fieldset_restore_test');
    $grantee = RestoreDatabases::role('fieldset_restore_grantee');
    RestoreDatabases::admin()->exec("CREATE ROLE {$grantee} NOLOGIN");
    putenv(RestoreDatabaseCommand::PASSWORD_ENV.'='.RESTORE_DB_PASSWORD);
    restoreDb('create', ['--database' => $name, '--restricted-role' => $role]);
    restoreDbQuery($name, implode('; ', [
        "SET ROLE {$role}", 'CREATE TABLE restored (id int)', 'CREATE SEQUENCE restored_seq',
        'CREATE FUNCTION peek() RETURNS int AS $f$ SELECT 1 $f$ LANGUAGE sql', 'GRANT SELECT ON restored TO PUBLIC',
        "GRANT USAGE ON SEQUENCE restored_seq TO {$grantee}", "GRANT EXECUTE ON FUNCTION peek() TO {$grantee}",
        "ALTER DATABASE \"{$name}\" SET work_mem = '64MB'", 'RESET ROLE', "ALTER ROLE {$grantee} IN DATABASE \"{$name}\" SET work_mem = '32MB'",
    ]));

    [$code, $json] = restoreDb('adopt', ['--database' => $name, '--role' => $role]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toBe(['database' => $name, 'role' => $role, 'revoked' => 4, 'settings_reset' => 2])
        ->and(restoreDbQuery('postgres', "SELECT count(*) FROM pg_db_role_setting s JOIN pg_database d ON d.oid = s.setdatabase WHERE d.datname = '{$name}'"))->toBe(0)
        ->and(restoreDbQuery($name, "SELECT has_table_privilege('public', 'restored', 'SELECT')"))->toBeFalse()
        ->and(restoreDbQuery($name, "SELECT has_sequence_privilege('{$grantee}', 'restored_seq', 'USAGE')"))->toBeFalse()
        ->and(restoreDbQuery($name, "SELECT has_function_privilege('{$grantee}', 'peek()', 'EXECUTE')"))->toBeFalse()
        ->and(restoreDbQuery('postgres', "SELECT has_database_privilege('public', '{$name}', 'CREATE')"))->toBeFalse();
});

it('swaps the restored database in, keeps the live one aside, and swaps back', function (): void {
    $live = restoreDbMarked('rdl', 'live');
    $incoming = restoreDbMarked('rdi', 'restored');
    $aside = RestoreDatabases::name('rda');

    [$code, $json] = restoreDb('swap', ['--database' => $incoming, '--live' => $live, '--aside' => $aside]);
    $afterSwap = [restoreDbQuery($live, 'SELECT v FROM marker'), restoreDbQuery($aside, 'SELECT v FROM marker'), RestoreDatabases::exists($incoming)];
    [$back, $backJson] = restoreDb('swap', ['--database' => $aside, '--live' => $live, '--aside' => $incoming]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toBe(['live' => $live, 'aside' => $aside, 'incoming' => $incoming])
        ->and($afterSwap)->toBe(['restored', 'live', false])
        ->and($back)->toBe(0, json_encode($backJson))
        ->and(restoreDbQuery($live, 'SELECT v FROM marker'))->toBe('live')
        ->and(restoreDbQuery($incoming, 'SELECT v FROM marker'))->toBe('restored')
        ->and(RestoreDatabases::exists($aside))->toBeFalse();
});

it('refuses a swap with a missing database, a taken aside name or repeated names, and changes nothing', function (string $case, string $code): void {
    $live = restoreDbMarked('rdl', 'live');
    $incoming = restoreDbMarked('rdi', 'restored');
    $aside = $case === 'aside taken' ? restoreDbMarked('rda', 'other') : RestoreDatabases::name('rda');
    $options = match ($case) {
        'incoming missing' => ['--database' => RestoreDatabases::name('rdn'), '--live' => $live, '--aside' => $aside],
        'same names' => ['--database' => $incoming, '--live' => $incoming, '--aside' => $aside],
        default => ['--database' => $incoming, '--live' => $live, '--aside' => $aside],
    };

    [$exit, $json] = restoreDb('swap', $options);

    expect($exit)->toBe(1)->and($json['errors'][0]['code'])->toBe($code)
        ->and(restoreDbQuery($live, 'SELECT v FROM marker'))->toBe('live')
        ->and(restoreDbQuery($incoming, 'SELECT v FROM marker'))->toBe('restored');
})->with([
    ['incoming missing', 'database_missing'],
    ['aside taken', 'database_exists'],
    ['same names', 'invalid_swap'],
]);

it('drops a scratch database and its role, never the live or a system database', function (): void {
    $name = RestoreDatabases::name('restore_rdr');
    $role = RestoreDatabases::role('fieldset_restore_test');
    putenv(RestoreDatabaseCommand::PASSWORD_ENV.'='.RESTORE_DB_PASSWORD);
    restoreDb('create', ['--database' => $name, '--restricted-role' => $role]);

    [$code, $json] = restoreDb('drop', ['--database' => $name, '--role' => $role]);
    [$again, $againJson] = restoreDb('drop', ['--database' => $name]);
    [$live, $liveJson] = restoreDb('drop', ['--database' => (string) config('database.connections.pgsql.database')]);
    [$system, $systemJson] = restoreDb('drop', ['--database' => 'postgres']);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toBe(['database' => $name, 'dropped' => true, 'role_dropped' => true])
        ->and(RestoreDatabases::exists($name))->toBeFalse()->and(RestoreDatabases::roleExists($role))->toBeFalse()
        ->and([$again, $againJson['data']['dropped']])->toBe([0, false])
        ->and([$live, $liveJson['errors'][0]['code']])->toBe([1, 'refused_live_database'])
        ->and([$system, $systemJson['errors'][0]['code']])->toBe([1, 'reserved_database'])
        ->and(RestoreDatabases::exists((string) config('database.connections.pgsql.database')))->toBeTrue();
});

it('drops only databases and roles a restore made, never the app user or another database (review)', function (): void {
    $other = RestoreDatabases::name('rdo');
    restoreDb('create', ['--database' => $other]);
    $appUser = (string) config('database.connections.pgsql.username');

    [$database, $databaseJson] = restoreDb('drop', ['--database' => $other]);
    [$role, $roleJson] = restoreDb('drop', ['--database' => RestoreDatabases::name('restore_rdx'), '--role' => $appUser]);
    putenv(RestoreDatabaseCommand::PASSWORD_ENV.'='.RESTORE_DB_PASSWORD);
    [$demote, $demoteJson] = restoreDb('create', ['--database' => RestoreDatabases::name('restore_rdy'), '--restricted-role' => $appUser]);

    expect([$database, $databaseJson['errors'][0]['code']])->toBe([1, 'refused_database'])->and(RestoreDatabases::exists($other))->toBeTrue()
        ->and([$role, $roleJson['errors'][0]['code']])->toBe([1, 'invalid_role'])
        ->and([$demote, $demoteJson['errors'][0]['code']])->toBe([1, 'invalid_role'])
        ->and(RestoreDatabases::roleExists($appUser))->toBeTrue();
});

it('renames a restore-made database to a free name, and refuses any other database or a taken or system name (review A8)', function (): void {
    $from = restoreDbMarked('restore_rdr', 'aside');
    $to = RestoreDatabases::name('rdn');
    $live = restoreDbMarked('rdl', 'live');
    restoreDbMarked('restore_rdx', 'other');

    [$code, $json] = restoreDb('rename', ['--database' => $from, '--to' => $to]);
    [$foreign, $foreignJson] = restoreDb('rename', ['--database' => $live, '--to' => RestoreDatabases::name('rdo')]);
    [$taken, $takenJson] = restoreDb('rename', ['--database' => RestoreDatabases::name('restore_rdx'), '--to' => $live]);
    [$system, $systemJson] = restoreDb('rename', ['--database' => RestoreDatabases::name('restore_rdx'), '--to' => 'template1']);
    [$missing, $missingJson] = restoreDb('rename', ['--database' => RestoreDatabases::name('restore_rdy'), '--to' => RestoreDatabases::name('rdo')]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toBe(['from' => $from, 'to' => $to])
        ->and(restoreDbQuery($to, 'SELECT v FROM marker'))->toBe('aside')
        ->and(RestoreDatabases::exists($from))->toBeFalse()
        ->and([$foreign, $foreignJson['errors'][0]['code']])->toBe([1, 'refused_database'])
        ->and([$taken, $takenJson['errors'][0]['code']])->toBe([1, 'database_exists'])
        ->and([$system, $systemJson['errors'][0]['code']])->toBe([1, 'reserved_database'])
        ->and([$missing, $missingJson['errors'][0]['code']])->toBe([1, 'database_missing'])
        ->and(restoreDbQuery($live, 'SELECT v FROM marker'))->toBe('live')
        ->and(RestoreDatabases::exists(RestoreDatabases::name('rdo')))->toBeFalse();
});

it('lists the databases with a prefix and refuses an unknown action', function (): void {
    restoreDbMarked('rdl', 'live');
    restoreDbMarked('rdi', 'restored');

    [$code, $json] = restoreDb('list', ['--prefix' => RestoreDatabases::name('rd')]);
    [$all, $allJson] = restoreDb('list', ['--prefix' => '']);
    [$unknown, $unknownJson] = restoreDb('truncate', ['--database' => RestoreDatabases::name('rdl')]);

    expect($code)->toBe(0)
        ->and($json['data']['databases'])->toBe([RestoreDatabases::name('rdi'), RestoreDatabases::name('rdl')])
        ->and($all)->toBe(0)->and($allJson['data']['databases'])->toContain(RestoreDatabases::name('rdl'), 'postgres')
        ->and($json['changed'])->toBeFalse()
        ->and([$unknown, $unknownJson['errors'][0]['code']])->toBe([1, 'invalid_input']);
});

it('audits an unauthenticated restore: identical code passes, changed or extra code is named (review)', function (): void {
    $restored = RestoreDatabases::target();
    $reference = RestoreDatabases::sourceFromTarget();

    [$same, $sameJson] = restoreDb('audit', ['--database' => $restored, '--reference' => $reference]);
    $db = DB::connection(RestoreDatabases::TARGET);
    $db->unprepared(implode("\n", [
        'CREATE OR REPLACE FUNCTION audit_seal(entry bigint, seq bigint, prev char(64), entry_hash char(64)) RETURNS boolean AS $f$ BEGIN RETURN true; END; $f$ LANGUAGE plpgsql SECURITY DEFINER;',
        'CREATE FUNCTION steal() RETURNS text AS $f$ SELECT current_user::text $f$ LANGUAGE sql;',
        "ALTER TABLE organizations ALTER COLUMN locale SET DEFAULT pg_read_file('/etc/hostname');",
        "ALTER TABLE users ADD CONSTRAINT users_peek CHECK (length(pg_read_file('/etc/hostname')) > 0) NOT VALID;",
        'CREATE VIEW peek AS SELECT steal() AS who;',
    ]));
    DB::purge(RestoreDatabases::TARGET);
    [$changed, $changedJson] = restoreDb('audit', ['--database' => $restored, '--reference' => $reference]);
    $message = (string) ($changedJson['errors'][0]['message'] ?? '');

    expect($same)->toBe(0, json_encode($sameJson))->and($sameJson['data']['objects'])->toBeGreaterThan(100)
        ->and($changed)->toBe(1)->and($changedJson['errors'][0]['code'])->toBe('schema_mismatch')
        ->and($message)->toContain('changed function:public.audit_seal(')
        ->and($message)->toContain('unknown function:public.steal()')
        ->and($message)->toContain('default:organizations.locale calls pg_read_file()')
        ->and($message)->toContain('unknown check:users.users_peek')
        ->and($message)->toContain('unknown view:public.peek');
});

it('passes the schema audit after a real dump and restore, whose checks and defaults print differently (J08)', function (): void {
    $reference = RestoreDatabases::target();
    $restored = RestoreDatabases::empty('rdd');
    $file = sys_get_temp_dir().'/fsa-test-audit-'.bin2hex(random_bytes(3)).'.dump';
    new Process(['pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--file='.$file], null, BackupScratchDatabase::libpq($reference))->mustRun();
    new Process(['pg_restore', '--no-owner', '--no-privileges', '--exit-on-error', '--dbname='.$restored, '--', $file], null, BackupScratchDatabase::libpq($restored))->mustRun();
    @unlink($file);

    [$code, $json] = restoreDb('audit', ['--database' => $restored, '--reference' => $reference]);

    expect($code)->toBe(0, json_encode($json));
});

it('refuses an extension the restore owns or whose members differ, and passes the superuser\'s own (review S1)', function (string $case, ?string $problem): void {
    $reference = RestoreDatabases::target();
    $restored = $case === 'owned by the restricted role' ? RestoreDatabases::name('restore_rdr') : RestoreDatabases::empty('rdd');
    $evil = 'CREATE FUNCTION evil() RETURNS text AS $f$ SELECT current_user::text $f$ LANGUAGE sql; ALTER EXTENSION pg_trgm ADD FUNCTION evil()';
    if ($case === 'owned by the restricted role') {
        $role = RestoreDatabases::role('fieldset_restore_test');
        putenv(RestoreDatabaseCommand::PASSWORD_ENV.'='.RESTORE_DB_PASSWORD);
        restoreDb('create', ['--database' => $restored, '--restricted-role' => $role]);
        restoreDbQuery($restored, "DROP EXTENSION pg_trgm; SET ROLE {$role}; CREATE EXTENSION pg_trgm; {$evil}; RESET ROLE");
    } else {
        restoreDbQuery($restored, $case === 'member added' ? "CREATE EXTENSION pg_trgm; {$evil}" : 'CREATE EXTENSION pg_trgm');
    }

    [$code, $json] = restoreDb('audit', ['--database' => $restored, '--reference' => $reference]);

    expect($code)->toBe($problem === null ? 0 : 1, json_encode($json));
    if ($problem !== null) {
        expect($json['errors'][0]['code'])->toBe('schema_mismatch')->and($json['errors'][0]['message'])->toContain($problem);
    }
})->with([
    ['owned by the restricted role', 'changed extension:pg_trgm'],
    ['member added', 'changed extension:pg_trgm'],
    ['installed by the superuser', null],
]);

it('refuses grants to others than the owner, a disabled trigger and database settings the reference lacks (review S2)', function (): void {
    $restored = RestoreDatabases::target();
    $reference = RestoreDatabases::sourceFromTarget();
    $grantee = RestoreDatabases::role('fieldset_restore_grantee');
    RestoreDatabases::admin()->exec("CREATE ROLE {$grantee} NOLOGIN");
    $db = DB::connection(RestoreDatabases::TARGET);
    $db->unprepared(implode("\n", [
        'GRANT SELECT ON users TO PUBLIC;',
        "GRANT INSERT ON activity_log TO {$grantee};",
        'ALTER TABLE activity_log DISABLE TRIGGER activity_log_append_only_trg;',
    ]));
    DB::purge(RestoreDatabases::TARGET);
    restoreDbQuery('postgres', "ALTER DATABASE \"{$restored}\" SET work_mem = '64MB'");

    [$code, $json] = restoreDb('audit', ['--database' => $restored, '--reference' => $reference]);
    $message = (string) ($json['errors'][0]['message'] ?? '');

    expect($code)->toBe(1, json_encode($json))->and($json['errors'][0]['code'])->toBe('schema_mismatch')
        ->and($message)->toContain('unknown acl:table public.users PUBLIC SELECT')
        ->and($message)->toContain("unknown acl:table public.activity_log {$grantee} INSERT")
        ->and($message)->toContain('changed trigger:activity_log.activity_log_append_only_trg')
        ->and($message)->toContain('database setting work_mem for every role')
        ->and($message)->not->toContain('64MB');
});

/*
 * The restricted role cannot comment on the superuser's pg_trgm, so the restore leaves out the dump's EXTENSION and
 * COMMENT ON EXTENSION entries (pg_restore --use-list); `--role` gives the superuser's session the role's rights.
 */
it('restores a real dump as the restricted role into the prepared database, passes the audit and adopts (review S1)', function (): void {
    $reference = RestoreDatabases::target();
    $name = RestoreDatabases::name('restore_rdr');
    $role = RestoreDatabases::role('fieldset_restore_test');
    putenv(RestoreDatabaseCommand::PASSWORD_ENV.'='.RESTORE_DB_PASSWORD);
    restoreDb('create', ['--database' => $name, '--restricted-role' => $role]);
    $file = sys_get_temp_dir().'/fsa-test-audit-'.bin2hex(random_bytes(3)).'.dump';
    new Process(['pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--file='.$file], null, BackupScratchDatabase::libpq($reference))->mustRun();
    $toc = new Process(['pg_restore', '--list', '--', $file]);
    $toc->mustRun();
    $lines = array_filter(explode("\n", $toc->getOutput()), static fn (string $line): bool => preg_match('/ (?:EXTENSION|COMMENT) - (?:EXTENSION )?pg_trgm\b/', $line) !== 1);
    file_put_contents($file.'.list', implode("\n", $lines));
    $restore = new Process(['pg_restore', '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error', '--role='.$role, '--use-list='.$file.'.list', '--dbname='.$name, '--', $file], null, BackupScratchDatabase::libpq($name));
    $restore->run();
    @unlink($file);
    @unlink($file.'.list');

    [$audit, $auditJson] = restoreDb('audit', ['--database' => $name, '--reference' => $reference]);
    [$adopt, $adoptJson] = restoreDb('adopt', ['--database' => $name, '--role' => $role]);

    expect($restore->isSuccessful())->toBeTrue($restore->getErrorOutput())
        ->and($audit)->toBe(0, json_encode($auditJson))
        ->and($adopt)->toBe(0, json_encode($adoptJson));
});

/*
 * Review S3 (U18): an archive may leave code-bearing objects out. Every object the reference has at the archive's migration
 * set (its `migrations` table) must be in the restored database too; an older archive is compared with a database this
 * image migrates to exactly that set, built and dropped by the audit.
 */
it('names the code-bearing objects a restored database lacks: an append-only trigger, a check, an index (review S3)', function (): void {
    $restored = RestoreDatabases::target();
    $reference = RestoreDatabases::sourceFromTarget();
    DB::connection(RestoreDatabases::TARGET)->unprepared(implode("\n", [
        'DROP TRIGGER activity_log_append_only_trg ON activity_log;',
        'ALTER TABLE submissions DROP CONSTRAINT submissions_status_check;',
        'DROP INDEX submission_values_value_text_trgm_idx;',
    ]));
    DB::purge(RestoreDatabases::TARGET);

    [$code, $json] = restoreDb('audit', ['--database' => $restored, '--reference' => $reference]);
    $message = (string) ($json['errors'][0]['message'] ?? '');

    expect($code)->toBe(1, json_encode($json))->and($json['errors'][0]['code'])->toBe('schema_mismatch')
        ->and($message)->toContain('missing trigger:activity_log.activity_log_append_only_trg')
        ->and($message)->toContain('missing check:submissions.submissions_status_check')
        ->and($message)->toContain('missing index:public.submission_values_value_text_trgm_idx');
});

it('audits an older archive at its own migration set: what later migrations add may be missing, an omitted trigger may not (review S3)', function (): void {
    $restored = RestoreDatabases::target();
    $reference = RestoreDatabases::sourceFromTarget();
    RestoreDatabases::connect(RestoreDatabases::TARGET, $restored);
    Artisan::call('migrate:rollback', ['--database' => RestoreDatabases::TARGET, '--step' => 3, '--force' => true]);
    DB::purge(RestoreDatabases::TARGET);

    [$older, $olderJson] = restoreDb('audit', ['--database' => $restored, '--reference' => $reference]);
    restoreDbQuery($restored, 'DROP TRIGGER activity_log_append_only_trg ON activity_log');
    [$omitted, $omittedJson] = restoreDb('audit', ['--database' => $restored, '--reference' => $reference]);
    $message = (string) ($omittedJson['errors'][0]['message'] ?? '');

    expect(restoreDbQuery($restored, "SELECT count(*) FROM migrations WHERE migration LIKE '2026_10_26_%'"))->toBe(0)
        ->and($older)->toBe(0, json_encode($olderJson))
        ->and($omitted)->toBe(1, json_encode($omittedJson))->and($omittedJson['errors'][0]['code'])->toBe('schema_mismatch')
        ->and($message)->toContain('missing trigger:activity_log.activity_log_append_only_trg')
        ->and($message)->not->toContain('audit_chain_epochs')
        ->and(RestoreDatabases::admin()->query("SELECT count(*) FROM pg_database WHERE datname LIKE 'fsa\\_restore\\_ref\\_%'")->fetchColumn())->toBe(0);
});

it('refuses an archive that ran migrations this image does not know (review S3)', function (): void {
    $restored = RestoreDatabases::target();
    $reference = RestoreDatabases::sourceFromTarget();
    DB::connection(RestoreDatabases::TARGET)->unprepared("INSERT INTO migrations (migration, batch) VALUES ('2099_01_01_000000_from_a_newer_release', 9);");
    DB::purge(RestoreDatabases::TARGET);

    [$code, $json] = restoreDb('audit', ['--database' => $restored, '--reference' => $reference]);

    expect($code)->toBe(1, json_encode($json))->and($json['errors'][0]['code'])->toBe('schema_mismatch')
        ->and($json['errors'][0]['message'])->toContain('unknown migration 2099_01_01_000000_from_a_newer_release');
});
