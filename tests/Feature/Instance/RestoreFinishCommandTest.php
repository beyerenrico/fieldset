<?php

declare(strict_types=1);

use App\Console\Commands\RestoreFinishCommand;
use App\Domain\Audit\Chain\ChainHeads;
use App\Domain\Forms\Models\Form;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Platform\Instance\BackupRole;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\Instance\RestoreDatabases;

/*
 * `instance:restore-finish` (plan U17 steps 5, 8, 9; KTD26, R64, R68; ADR 0084 "left for U17"; ADR 0085) on a committed
 * restored database: a new audit epoch seeded from the ledger with `instance.restored` as its first entry, sequences that
 * never go backwards, the audit functions' grants and the backup role again, and a decrypt sample with the APP keys.
 */

const FINISH_PASSWORD = 'fedcba9876543210fedcba9876543210fedcba9876543210';

const FINISH_HEAD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

beforeEach(function (): void {
    RestoreDatabases::target();
    $this->role = RestoreDatabases::role('fieldset_backup_finish');
    putenv(BackupRole::PASSWORD_ENV.'='.FINISH_PASSWORD);
    $this->stdin = '';
    app()->bind(RestoreFinishCommand::STDIN, fn () => fn (): string => $this->stdin);
});

afterEach(function (): void {
    putenv(BackupRole::PASSWORD_ENV);
    RestoreDatabases::drop();
});

/** @return array{0: int, 1: array<string, mixed>} */
function finishRun(object $test, array $options = []): array
{
    $code = Artisan::call('instance:restore-finish', ['--backup-role' => $test->role, '--json' => true, ...$options]);

    return [$code, json_decode(trim(Artisan::output()), true)];
}

it('starts a new epoch from the ledger seed and writes instance.restored as its first entry', function (): void {
    makeOrganization();
    $restoredHeads = ChainHeads::read();
    $before = (int) DB::table('activity_log')->max('id');
    $this->stdin = json_encode(['previous_heads' => ['0' => ['seq' => 7, 'hash' => FINISH_HEAD]], 'previous_epoch' => 3, 'from_backup' => false]);

    [$code, $json] = finishRun($this, ['--backup' => 'manual-20261019T120000Z', '--epoch-seed' => '3', '--actor-uid' => '1000']);
    $epoch = DB::table('audit_chain_epochs')->orderByDesc('epoch')->first();
    $entries = DB::table('activity_log')->where('id', '>', $before)->get();
    $properties = json_decode((string) $entries->first()?->properties, true);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data'])->toMatchArray(['epoch' => 4, 'previous_epoch' => 3])
        ->and($epoch->epoch)->toBe(4)->and($epoch->reason)->toBe('restored')
        ->and(json_decode((string) $epoch->previous_heads, true))->toBe(['0' => ['seq' => 7, 'hash' => FINISH_HEAD]])
        ->and($entries)->toHaveCount(1)
        ->and($entries->first()->event)->toBe('instance.restored')
        ->and($entries->first()->actor_type)->toBe('cli')
        ->and($properties)->toMatchArray(['backup' => 'manual-20261019T120000Z', 'epoch' => 4, 'previous_epoch' => 3, 'from_backup' => false, 'host_uid' => 1000])
        ->and($properties['previous_heads'])->toBe(['0' => ['seq' => 7, 'hash' => FINISH_HEAD]])
        ->and($properties['restored_heads'])->toEqual(json_decode((string) json_encode((object) $restoredHeads), true));
});

it('advances Postgres sequences and submission number counters past the pre-restore database', function (): void {
    $org = makeOrganization();
    [$form, $gone] = app(TenantContext::class)->run($org, fn (): array => [Form::factory()->create(), Form::factory()->create()]);
    DB::table('number_sequences')->insert([
        ['organization_id' => $org->id, 'form_id' => $form->id, 'period' => '2026', 'next_value' => 5, 'created_at' => now(), 'updated_at' => now()],
        ['organization_id' => $org->id, 'form_id' => $gone->id, 'period' => '2026', 'next_value' => 9, 'created_at' => now(), 'updated_at' => now()],
    ]);
    RestoreDatabases::sourceFromTarget();
    $source = DB::connection(RestoreDatabases::SOURCE);
    $source->table('number_sequences')->where('form_id', $form->id)->update(['next_value' => 42]);
    $source->table('number_sequences')->insert(['organization_id' => $org->id, 'form_id' => $form->id, 'period' => '2027', 'next_value' => 3, 'created_at' => now(), 'updated_at' => now()]);
    $source->select("SELECT setval('users_id_seq', 5000)");
    DB::connection(RestoreDatabases::TARGET)->select("SELECT setval('organizations_id_seq', 900)");
    DB::connection(RestoreDatabases::TARGET)->table('forms')->where('id', $gone->id)->delete(); // a form only the source has

    [$code, $json] = finishRun($this, ['--source' => RestoreDatabases::name('rs')]);
    $target = DB::connection(RestoreDatabases::TARGET);
    $next = static fn (string $period): mixed => $target->table('number_sequences')->where('form_id', $form->id)->where('period', $period)->value('next_value');

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['sequences']['number_sequences'])->toBe(2)
        ->and($json['data']['sequences']['sequences'])->toBeGreaterThanOrEqual(1)
        ->and($next('2026'))->toBe(42)->and($next('2027'))->toBe(3)
        ->and($target->table('number_sequences')->where('form_id', $gone->id)->exists())->toBeFalse()
        ->and((int) $target->selectOne("SELECT last_value FROM pg_sequences WHERE sequencename = 'users_id_seq'")->last_value)->toBe(5000)
        ->and((int) $target->selectOne("SELECT last_value FROM pg_sequences WHERE sequencename = 'organizations_id_seq'")->last_value)->toBe(900)
        ->and((int) $target->table('users')->insertGetId(['name' => 'n', 'email' => 'n@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]))->toBe(5001);
});

it('re-applies the audit functions\' REVOKE FROM PUBLIC and sets the backup role up again', function (): void {
    $functions = ['audit_seal(bigint, bigint, character, character)', 'audit_prune(timestamp without time zone)', 'audit_prunable(timestamp without time zone)'];
    foreach ($functions as $function) {
        DB::statement("GRANT EXECUTE ON FUNCTION {$function} TO PUBLIC"); // what a --no-privileges dump restores
    }
    $public = static fn (string $function): bool => (bool) DB::selectOne('SELECT has_function_privilege(?, ?, ?) AS ok', ['public', $function, 'EXECUTE'])->ok;
    $wasExecutable = $public($functions[0]);

    [$code, $json] = finishRun($this);
    $role = static fn (string $role, string $function): bool => (bool) DB::selectOne('SELECT has_function_privilege(?, ?, ?) AS ok', [$role, $function, 'EXECUTE'])->ok;

    expect($code)->toBe(0, json_encode($json))
        ->and($wasExecutable)->toBeTrue()
        ->and($json['data']['grants']['revoked_public'])->toBe($functions)
        ->and($json['data']['grants']['backup_role'])->toMatchArray(['role' => $this->role, 'created' => true])
        ->and(array_map($public, $functions))->toBe([false, false, false])
        ->and(array_map(fn (string $f): bool => $role($this->role, $f), $functions))->toBe([false, false, false])
        ->and((bool) DB::selectOne('SELECT has_table_privilege(?, ?, ?) AS ok', [$this->role, 'backup_runs', 'INSERT'])->ok)->toBeTrue()
        ->and((bool) DB::selectOne('SELECT has_database_privilege(?, ?, ?) AS ok', [$this->role, RestoreDatabases::name('rt'), 'CONNECT'])->ok)->toBeTrue();
});

it('decrypts data encrypted under a previous APP key and refuses keys that open nothing', function (): void {
    $org = makeOrganization();
    $old = 'base64:'.base64_encode(random_bytes(32));
    DB::table('webhook_endpoints')->insert(['organization_id' => $org->id, 'name' => 'hook', 'url' => 'https://hooks.example.com', 'secret' => new Encrypter(base64_decode(substr($old, 7)), 'aes-256-cbc')->encrypt('s', false), 'created_at' => now(), 'updated_at' => now()]);
    $useKeys = static function (string $key, array $previous): void {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    };

    $useKeys('base64:'.base64_encode(random_bytes(32)), [$old]);
    [$opened, $okJson] = finishRun($this);
    $useKeys('base64:'.base64_encode(random_bytes(32)), []);
    [$refused, $failedJson] = finishRun($this);

    expect($opened)->toBe(0, json_encode($okJson))
        ->and($okJson['data']['decrypt'])->toBe(['tested' => 1, 'failed' => []])
        ->and($refused)->toBe(1)
        ->and($failedJson['errors'][0]['code'])->toBe('decrypt_failed')
        ->and($failedJson['errors'][0]['message'])->toContain('webhook_endpoints.secret')
        ->and(json_encode($failedJson))->not->toContain($old);
});

it('refuses without the backup role password, with malformed input or the restored database as source', function (array $options, string $stdin, ?string $password, string $code): void {
    putenv($password === null ? BackupRole::PASSWORD_ENV : BackupRole::PASSWORD_ENV.'='.$password);
    $this->stdin = $stdin;
    $options = array_map(static fn (mixed $value): mixed => $value === '@target' ? RestoreDatabases::name('rt') : $value, $options);

    [$exit, $json] = finishRun($this, $options);

    expect($exit)->toBe(1)->and($json['errors'][0]['code'])->toBe($code)
        ->and(DB::table('audit_chain_epochs')->count())->toBe(0);
})->with([
    'no password' => [[], '', null, 'password_missing'],
    'stdin not JSON' => [[], 'heads', FINISH_PASSWORD, 'invalid_input'],
    'malformed head' => [[], '{"previous_heads": {"0": {"seq": "7", "hash": "x"}}}', FINISH_PASSWORD, 'invalid_input'],
    'restored database as source' => [['--source' => '@target'], '', FINISH_PASSWORD, 'invalid_input'],
    'unknown reason' => [['--reason' => 'reset'], '', FINISH_PASSWORD, 'invalid_input'],
    'source name with a quote' => [['--source' => 'x"; DROP'], '', FINISH_PASSWORD, 'invalid_input'],
]);

it('writes one epoch and one instance.restored per journal, however often the finish runs (review LOW 6)', function (): void {
    makeOrganization();

    [$first, $one] = finishRun($this, ['--backup' => 'manual-20261019T120000Z', '--journal' => 'abc123def456']);
    [$second, $two] = finishRun($this, ['--backup' => 'manual-20261019T120000Z', '--journal' => 'abc123def456']);
    [$other, $three] = finishRun($this, ['--backup' => 'manual-20261019T120000Z', '--journal' => 'fedcba654321']);
    $entries = DB::table('activity_log')->where('event', 'instance.restored')->orderBy('id')->get();

    expect([$first, $second, $other])->toBe([0, 0, 0], json_encode([$one, $two, $three]))
        ->and($one['data']['epoch_repeated'])->toBeFalse()
        ->and($two['data'])->toMatchArray(['epoch' => $one['data']['epoch'], 'activity_id' => $one['data']['activity_id'], 'epoch_repeated' => true])
        ->and($three['data']['epoch'])->toBe($one['data']['epoch'] + 1)
        ->and(DB::table('audit_chain_epochs')->count())->toBe(2)
        ->and($entries)->toHaveCount(2)
        ->and(json_decode((string) $entries[0]->properties, true)['journal'] ?? null)->toBe('abc123def456');
});

it('reports a source database that does not exist as a database failure', function (): void {
    [$exit, $json] = finishRun($this, ['--source' => RestoreDatabases::name('missing')]);

    expect($exit)->toBe(1)->and($json['errors'][0]['code'])->toBe('database_failed');
});

it('writes nothing when a step fails: a decrypt failure leaves no epoch, no audit entry and the credentials, a rerun writes one (review A9)', function (): void {
    $org = makeOrganization();
    $old = 'base64:'.base64_encode(random_bytes(32));
    DB::table('webhook_endpoints')->insert(['organization_id' => $org->id, 'name' => 'hook', 'url' => 'https://hooks.example.com', 'secret' => new Encrypter(base64_decode(substr($old, 7)), 'aes-256-cbc')->encrypt('s', false), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sessions')->insert(['id' => 'backup-session', 'user_id' => null, 'payload' => 'x', 'last_activity' => time()]);
    $useKeys = static function (string $key, array $previous): void {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        app()->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
    };
    $written = static fn (): array => [
        DB::table('audit_chain_epochs')->count(), DB::table('activity_log')->where('event', 'instance.restored')->count(), DB::table('sessions')->count(),
    ];

    $useKeys('base64:'.base64_encode(random_bytes(32)), []);
    [$refused, $failedJson] = finishRun($this);
    $afterFailure = [...$written(), RestoreDatabases::roleExists($this->role)];
    $useKeys('base64:'.base64_encode(random_bytes(32)), [$old]);
    [$opened, $okJson] = finishRun($this);

    expect($refused)->toBe(1)->and($failedJson['errors'][0]['code'])->toBe('decrypt_failed')
        ->and($failedJson['errors'][0]['hint'] ?? null)->toContain('--app-key-file')
        ->and($afterFailure)->toBe([0, 0, 1, false])
        ->and($opened)->toBe(0, json_encode($okJson))
        ->and($written())->toBe([1, 1, 0]);
});
