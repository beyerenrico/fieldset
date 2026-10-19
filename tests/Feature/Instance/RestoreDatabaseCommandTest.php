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
    $role = RestoreDatabases::role('fsa_restore_test');

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

it('adopts a restricted restore: objects and database go to the superuser, the role goes, PUBLIC may connect again', function (): void {
    $name = RestoreDatabases::name('restore_rdr');
    $role = RestoreDatabases::role('fsa_restore_test');
    putenv(RestoreDatabaseCommand::PASSWORD_ENV.'='.RESTORE_DB_PASSWORD);
    restoreDb('create', ['--database' => $name, '--restricted-role' => $role]);
    restoreDbQuery($name, "SET ROLE {$role}; CREATE TABLE restored (id int); RESET ROLE");
    $owner = (string) config('database.connections.pgsql.username');

    [$code, $json] = restoreDb('adopt', ['--database' => $name, '--role' => $role]);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toBe(['database' => $name, 'role' => $role])
        ->and(restoreDbQuery($name, "SELECT tableowner FROM pg_tables WHERE tablename = 'restored'"))->toBe($owner)
        ->and(restoreDbQuery('postgres', "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '{$name}'"))->toBe($owner)
        ->and(RestoreDatabases::roleExists($role))->toBeFalse()
        ->and(restoreDbQuery('postgres', "SELECT has_database_privilege('public', '{$name}', 'CONNECT')"))->toBeTrue();
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
    $role = RestoreDatabases::role('fsa_restore_test');
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

it('lists the databases with a prefix and refuses an unknown action', function (): void {
    restoreDbMarked('rdl', 'live');
    restoreDbMarked('rdi', 'restored');

    [$code, $json] = restoreDb('list', ['--prefix' => RestoreDatabases::name('rd')]);
    [$all, $allJson] = restoreDb('list', ['--prefix' => '']);
    [$unknown, $unknownJson] = restoreDb('rename', ['--database' => RestoreDatabases::name('rdl')]);

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
