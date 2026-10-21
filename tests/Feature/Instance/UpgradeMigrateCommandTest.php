<?php

declare(strict_types=1);

use App\Domain\Platform\Instance\Upgrade\UpgradeMigrator;
use App\Domain\Platform\Instance\Upgrade\VersionStep;
use App\Domain\Platform\Instance\Upgrade\VersionSteps;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Instance\RestoreDatabases;

/*
 * The app side of the upgrade child and of the supervisor's database checks (KTD19 steps 6 and 8, KTD25, plan U18,
 * ADR 0086), against committed scratch databases (`<test db>_um…`, LEARNINGS 133): `instance:upgrade-migrate` alone on
 * the database, with lock_timeout, the version steps and the index check; `instance:upgrade-db` for the child's session
 * (activity, termination) and invalid indexes. Every database and session is dropped afterwards.
 */

beforeEach(function (): void {
    $this->database = RestoreDatabases::target('um');
    $this->migrations = sys_get_temp_dir().'/fieldset-test-upgrade-migrations-'.bin2hex(random_bytes(4));
    mkdir($this->migrations, 0700);
    app()->instance(UpgradeMigrator::PATH, $this->migrations);
    $this->sessions = [];
});

afterEach(function (): void {
    $this->sessions = [];
    array_map(unlink(...), glob($this->migrations.'/*') ?: []);
    @rmdir($this->migrations);
    RestoreDatabases::drop(['um']);
});

/** @return array{0: int, 1: array<string, mixed>} */
function upgradeArtisan(string $command, array $options = []): array
{
    $code = Artisan::call($command, [...$options, '--json' => true]);
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

    return [$code, json_decode((string) end($lines), true)];
}

/** A raw session on a database with an application_name, as another client would hold one. */
function upgradeSession(string $database, string $application): PDO
{
    $config = config('database.connections.pgsql');

    return new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$database};options='--application_name={$application}'", (string) $config['username'], (string) $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function upgradeMigration(string $directory, string $name, string $up): void
{
    file_put_contents("{$directory}/{$name}.php", "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Support\\Facades\\DB;\nreturn new class extends Migration {\n    public function up(): void { {$up} }\n};\n");
}

it('runs the pending migrations alone on the database with lock_timeout, and changes nothing the second time', function (): void {
    upgradeMigration($this->migrations, '2099_01_01_000000_fieldset_test_upgrade_probe', "DB::statement('CREATE TABLE fieldset_upgrade_probe (lock_timeout text)'); DB::statement(\"INSERT INTO fieldset_upgrade_probe SELECT current_setting('lock_timeout')\");");

    [$code, $json] = upgradeArtisan('instance:upgrade-migrate', ['--wait-foreign' => '0', '--lock-timeout' => '7', '--from' => '2.0.0-rc.1']);
    [$again, $second] = upgradeArtisan('instance:upgrade-migrate', ['--wait-foreign' => '0']);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['migrated' => ['2099_01_01_000000_fieldset_test_upgrade_probe'], 'steps' => [], 'invalid_indexes' => [], 'lock_timeout' => '7s'])
        ->and($json['changed'])->toBeTrue()
        ->and(DB::table('fieldset_upgrade_probe')->value('lock_timeout'))->toBe('7s')
        ->and($again)->toBe(0)->and($second['data']['migrated'])->toBe([])->and($second['changed'])->toBeFalse();
});

it('refuses to migrate beside a foreign session, naming it, and migrates nothing', function (): void {
    upgradeMigration($this->migrations, '2099_01_01_000000_fieldset_test_upgrade_probe', "DB::statement('CREATE TABLE fieldset_upgrade_probe (v int)');");
    $this->sessions[] = upgradeSession($this->database, 'fieldset-test-foreign');

    [$code, $json] = upgradeArtisan('instance:upgrade-migrate', ['--wait-foreign' => '0']);

    expect($code)->toBe(1)
        ->and($json['errors'][0]['code'])->toBe('foreign_connections')
        ->and($json['errors'][0]['message'])->toContain('fieldset-test-foreign')
        ->and(DB::getSchemaBuilder()->hasTable('fieldset_upgrade_probe'))->toBeFalse();
});

it('reports a migration that fails, and an index a migration leaves invalid', function (string $case, string $error): void {
    if ($case === 'failing') {
        upgradeMigration($this->migrations, '2099_01_01_000000_fieldset_test_upgrade_broken', "DB::statement('SELECT * FROM fieldset_no_such_table');");
    } else {
        $session = upgradeSession($this->database, 'fieldset-test-builder');
        $session->exec('CREATE TABLE fieldset_dupes (v int); INSERT INTO fieldset_dupes VALUES (1), (1)');
        try {
            $session->exec('CREATE UNIQUE INDEX CONCURRENTLY fieldset_dupes_unique ON fieldset_dupes (v)');
        } catch (PDOException) {
            // the duplicate leaves the concurrently built index behind, invalid
        }
        unset($session);
    }

    [$code, $json] = upgradeArtisan('instance:upgrade-migrate', ['--wait-foreign' => '5']);

    expect($code)->toBe(1)->and($json['errors'][0]['code'])->toBe($error);
})->with([['failing', 'migration_failed'], ['invalid index', 'invalid_index']]);

it('runs the version steps a release lists since the version the instance comes from', function (?string $from, bool $runs): void {
    $step = new class implements VersionStep
    {
        public function since(): string
        {
            return '2.0.1';
        }

        public function name(): string
        {
            return 'fieldset-test-step';
        }

        public function run(Connection $db): void
        {
            $db->statement('CREATE TABLE IF NOT EXISTS fieldset_upgrade_step (v int)');
        }
    };
    app()->instance(VersionSteps::class, new VersionSteps([$step]));

    [$code, $json] = upgradeArtisan('instance:upgrade-migrate', ['--wait-foreign' => '0', ...($from === null ? [] : ['--from' => $from])]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['steps'])->toBe($runs ? ['fieldset-test-step'] : [])
        ->and(DB::getSchemaBuilder()->hasTable('fieldset_upgrade_step'))->toBe($runs);
})->with([['2.0.0', true], ['2.0.0-rc.1', true], ['2.0.1', false], ['2.1.0', false], [null, true]]);

it('refuses a malformed --from or session name', function (array $options): void {
    [$code, $json] = upgradeArtisan('instance:upgrade-migrate', $options);

    expect($code)->toBe(1)->and($json['errors'][0]['code'])->toBe('invalid_input');
})->with([[['--from' => 'latest']], [['--application-name' => 'psql']], [['--wait-foreign' => 'soon']]]);

it('reports the child\'s session by its name and terminates it', function (): void {
    $child = upgradeSession($this->database, 'fieldset_upgrade_0123456789ab');

    [$code, $activity] = upgradeArtisan('instance:upgrade-db', ['action' => 'activity', '--application-name' => 'fieldset_upgrade_0123456789ab']);
    [, $terminated] = upgradeArtisan('instance:upgrade-db', ['action' => 'terminate', '--application-name' => 'fieldset_upgrade_0123456789ab']);
    usleep(200_000);
    [, $after] = upgradeArtisan('instance:upgrade-db', ['action' => 'activity', '--application-name' => 'fieldset_upgrade_0123456789ab']);

    expect($code)->toBe(0)
        ->and($activity['data']['backends'])->toHaveCount(1)
        ->and($activity['data']['backends'][0]['state'])->toBe('idle')
        ->and($terminated['data']['terminated'])->toBe(1)->and($terminated['changed'])->toBeTrue()
        ->and($after['data']['backends'])->toBe([])
        ->and(fn () => $child->query('SELECT 1'))->toThrow(PDOException::class);
});

it('lists invalid indexes and refuses an unknown action or session name', function (): void {
    [$clean, $none] = upgradeArtisan('instance:upgrade-db', ['action' => 'invalid-indexes']);
    $session = upgradeSession($this->database, 'fieldset-test-builder');
    $session->exec('CREATE TABLE fieldset_dupes (v int); INSERT INTO fieldset_dupes VALUES (1), (1)');
    try {
        $session->exec('CREATE UNIQUE INDEX CONCURRENTLY fieldset_dupes_unique ON fieldset_dupes (v)');
    } catch (PDOException) {
        // left invalid
    }
    [, $listed] = upgradeArtisan('instance:upgrade-db', ['action' => 'invalid-indexes']);
    [$unknown, $refused] = upgradeArtisan('instance:upgrade-db', ['action' => 'vacuum']);
    [$badName] = upgradeArtisan('instance:upgrade-db', ['action' => 'terminate', '--application-name' => 'postgres']);

    expect($clean)->toBe(0)->and($none['data']['indexes'])->toBe([])
        ->and($listed['data']['indexes'])->toBe(['fieldset_dupes_unique'])
        ->and($unknown)->toBe(1)->and($refused['errors'][0]['code'])->toBe('invalid_input')
        ->and($badName)->toBe(1);
});

it('lists the other client sessions of the database for the in-flight wait, without query texts (review U18 A1)', function (): void {
    $session = upgradeSession($this->database, 'fieldset-test-request');
    $session->exec('SELECT 1');

    [$code, $json] = upgradeArtisan('instance:upgrade-db', ['action' => 'foreign']);
    $names = array_column($json['data']['sessions'] ?? [], 'application_name');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($names)->toContain('fieldset-test-request')
        ->and(array_keys($json['data']['sessions'][0]))->toBe(['pid', 'application_name', 'user', 'state']);
});

it('reports the live database and storage sizes for the upgrade\'s space check (review U18 A5)', function (): void {
    [$code, $json] = upgradeArtisan('instance:upgrade-db', ['action' => 'size']);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['database_bytes'])->toBeInt()->toBeGreaterThan(1_000_000)
        ->and($json['data']['storage_bytes'])->toBeInt()->toBeGreaterThanOrEqual(0);
});
