<?php

declare(strict_types=1);

use App\Console\Commands\ResetInstanceCommand;
use App\Domain\Audit\Chain\ChainEpoch;
use App\Domain\Audit\Chain\ChainHeads;
use App\Domain\Identity\Actions\CreateOrganization;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\PlatformAdmin;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\Support\Instance\RestoreDatabases;

/*
 * The app side of `fsa reset` (plan U23, R43, R68, KTD26, ADR 0088), in socket-less one-offs: `instance:reset plan` (what the
 * reset must know from the live database before the stop), `instance:reset recreate` (the live database dropped and
 * created again, never another), `instance:reset epoch` (the new epoch seeded from the ledger with `instance.reset` and
 * the old heads as its first entry, once) and `instance:audit-export` (every audit entry with the heads, streamed).
 */

const RESET_HEAD = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function (): void {
    $this->stdin = '';
    app()->bind(ResetInstanceCommand::STDIN, fn () => fn (): string => $this->stdin);
});

/** @return array{0: int, 1: array<string, mixed>} */
function resetApp(string $action, array $options = []): array
{
    $code = Artisan::call('instance:reset', ['action' => $action, '--json' => true, ...$options]);

    return [$code, json_decode(trim(Artisan::output()), true)];
}

function resetOperator(string $email, string $name, UserStatus $status = UserStatus::Active, string $locale = 'de'): User
{
    $user = User::factory()->create(['email' => $email, 'name' => $name, 'locale' => $locale]);
    $user->forceFill(['status' => $status])->saveQuietly();
    PlatformAdmin::query()->create(['user_id' => $user->id]);

    return $user;
}

it('plans from the live database: organizations (deleted ones too), the oldest real one, active operators oldest first', function (): void {
    $owner = resetOperator('owner@example.test', 'Olivia Owner', locale: 'en');
    $first = app(CreateOrganization::class)->handle(['name' => 'Acme Forms', 'locale' => 'en'], $owner);
    app(CreateOrganization::class)->handle(['name' => 'Demo Haustechnik GmbH'], User::factory()->create(), demo: true);
    app(CreateOrganization::class)->handle(['name' => 'Gone GmbH'], User::factory()->create())->delete();
    resetOperator('second@example.test', 'Sam Second');
    resetOperator('off@example.test', 'Off Operator', UserStatus::Deactivated);

    [$code, $json] = resetApp('plan');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['changed'])->toBeFalse()
        ->and($json['data']['organizations'])->toBe(['total' => 3, 'non_demo' => 2])
        ->and($json['data']['organization'])->toBe(['name' => $first->name, 'locale' => 'en'])
        ->and($json['data']['operators'])->toBe([
            ['email' => 'owner@example.test', 'name' => 'Olivia Owner', 'locale' => 'en'],
            ['email' => 'second@example.test', 'name' => 'Sam Second', 'locale' => 'de'],
        ]);
});

it('plans a demo-only instance: no real organization, so the reset needs no audit export', function (): void {
    app(CreateOrganization::class)->handle(['name' => 'Demo Haustechnik GmbH'], User::factory()->create(), demo: true);

    [$code, $json] = resetApp('plan');

    expect($code)->toBe(0)
        ->and($json['data']['organizations'])->toBe(['total' => 1, 'non_demo' => 0])
        ->and($json['data']['organization'])->toBeNull()
        ->and($json['data']['operators'])->toBe([]);
});

it('starts the new epoch from the ledger seed with instance.reset and the old heads as its first entry', function (): void {
    expect(DB::table('activity_log')->count())->toBe(0); // a freshly migrated database
    $this->stdin = json_encode(['previous_heads' => ['0' => ['seq' => 9, 'hash' => RESET_HEAD], '4' => ['seq' => 2, 'hash' => RESET_HEAD]], 'previous_epoch' => 2]);

    [$code, $json] = resetApp('epoch', ['--epoch-seed' => '3', '--actor-uid' => '1000', '--safety-backup' => 'safety-reset-20261020T080000Z', '--demo' => true]);
    $epoch = DB::table('audit_chain_epochs')->orderByDesc('epoch')->first();
    $entries = DB::table('activity_log')->get();
    $properties = json_decode((string) $entries->first()?->properties, true);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and($json['data'])->toMatchArray(['epoch' => 4, 'previous_epoch' => 2, 'began' => true])
        ->and($epoch->epoch)->toBe(4)->and($epoch->reason)->toBe('reset')
        ->and(json_decode((string) $epoch->previous_heads, true))->toBe(['0' => ['seq' => 9, 'hash' => RESET_HEAD], '4' => ['seq' => 2, 'hash' => RESET_HEAD]])
        ->and($entries)->toHaveCount(1)
        ->and($entries->first()->event)->toBe('instance.reset')
        ->and($entries->first()->actor_type)->toBe('cli')
        ->and($entries->first()->organization_id)->toBeNull()
        ->and((int) $json['data']['activity_id'])->toBe((int) $entries->first()->id)
        ->and($properties)->toMatchArray(['epoch' => 4, 'previous_epoch' => 2, 'safety_backup' => 'safety-reset-20261020T080000Z', 'audit_export' => null, 'demo' => true, 'host_uid' => 1000])
        ->and($properties['previous_heads'])->toBe(['0' => ['seq' => 9, 'hash' => RESET_HEAD], '4' => ['seq' => 2, 'hash' => RESET_HEAD]]);
});

it('bumps the epoch only once: a resumed reset gets the epoch it began (no second epoch, no second entry)', function (): void {
    $this->stdin = json_encode(['previous_heads' => ['0' => ['seq' => 9, 'hash' => RESET_HEAD]], 'previous_epoch' => 1]);
    resetApp('epoch', ['--epoch-seed' => '1']);

    [$code, $json] = resetApp('epoch', ['--epoch-seed' => '1']);

    expect($code)->toBe(0)
        ->and($json['changed'])->toBeFalse()
        ->and($json['data'])->toMatchArray(['epoch' => 2, 'began' => false])
        ->and(DB::table('audit_chain_epochs')->count())->toBe(1)
        ->and(DB::table('activity_log')->where('event', 'instance.reset')->count())->toBe(1);
});

it('refuses to begin the epoch in a database that already holds audit entries (it is not the reset\'s fresh one)', function (): void {
    app(CreateOrganization::class)->handle(['name' => 'Acme Forms'], User::factory()->create());

    [$code, $json] = resetApp('epoch', ['--epoch-seed' => '1']);

    expect($code)->toBe(1)
        ->and($json['errors'][0]['code'])->toBe('database_not_fresh')
        ->and(ChainEpoch::current())->toBe(1);
});

it('plans the old database\'s sequence values (review S1)', function (): void {
    makeOrganization(name: 'Acme Forms');
    $users = (int) DB::selectOne("SELECT last_value FROM pg_sequences WHERE schemaname = 'public' AND sequencename = 'users_id_seq'")->last_value;

    [$code, $json] = resetApp('plan');

    expect($code)->toBe(0)
        ->and($json['data']['sequences']['users_id_seq'])->toBe($users)
        ->and($json['data']['sequences'])->toHaveKey('organization_user_id_seq')
        ->and(array_filter($json['data']['sequences'], static fn (mixed $value): bool => ! is_int($value)))->toBe([]);
});

it('raises every sequence above the old database before instance.reset, so an old signed invitation finds no row (review S1)', function (): void {
    // Sequences survive RefreshDatabase's rollback: the floors lie above wherever earlier tests left them.
    $current = static fn (string $sequence): int => (int) (DB::selectOne("SELECT last_value FROM pg_sequences WHERE schemaname = 'public' AND sequencename = ?", [$sequence])->last_value ?? 0);
    $floors = ['organization_user_id_seq' => $current('organization_user_id_seq') + 500, 'users_id_seq' => $current('users_id_seq') + 500, 'activity_log_id_seq' => $current('activity_log_id_seq') + 500];
    $old = URL::temporarySignedRoute('invitations.show', now()->addDays(7), ['membership' => $floors['organization_user_id_seq']]); // X, invited before the reset
    $this->stdin = json_encode(['previous_heads' => ['0' => ['seq' => 9, 'hash' => RESET_HEAD]], 'previous_epoch' => 1, 'sequences' => [...$floors, 'gone_id_seq' => 7]]);

    [$code, $json] = resetApp('epoch', ['--epoch-seed' => '1']);
    makeOrganization(name: 'Fresh GmbH'); // Y, after the reset
    $memberships = DB::table('organization_user')->pluck('id')->all();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['sequences'])->toBe(3)
        ->and((int) $json['data']['activity_id'])->toBeGreaterThan($floors['activity_log_id_seq'])
        ->and(min($memberships))->toBeGreaterThan($floors['organization_user_id_seq'])
        ->and((int) DB::table('users')->min('id'))->toBeGreaterThan($floors['users_id_seq'])
        ->and($this->get($old)->getStatusCode())->toBe(404);
});

it('refuses malformed sequence floors on stdin', function (): void {
    $this->stdin = json_encode(['previous_heads' => [], 'previous_epoch' => 1, 'sequences' => ['users_id_seq; DROP TABLE users' => 5]]);

    [$code, $json] = resetApp('epoch', ['--epoch-seed' => '1']);

    expect($code)->toBe(1)->and($json['errors'][0]['code'])->toBe('invalid_input')
        ->and(DB::table('audit_chain_epochs')->count())->toBe(0);
});

it('refuses malformed heads on stdin, an unknown action and a non-numeric seed', function (): void {
    $this->stdin = json_encode(['previous_heads' => ['0' => ['seq' => 'x', 'hash' => 'nope']]]);
    [$heads, $headsJson] = resetApp('epoch');
    $this->stdin = '';
    [$seed, $seedJson] = resetApp('epoch', ['--epoch-seed' => '-1']);
    [$unknown, $unknownJson] = resetApp('truncate');

    expect($heads)->toBe(1)->and($headsJson['errors'][0]['code'])->toBe('invalid_input')
        ->and($seed)->toBe(1)->and($seedJson['errors'][0]['code'])->toBe('invalid_input')
        ->and($unknown)->toBe(1)->and($unknownJson['errors'][0]['message'])->toContain('plan, recreate or epoch')
        ->and(DB::table('audit_chain_epochs')->count())->toBe(0);
});

it('recreates the live database (DB_DATABASE of the one-off) empty, and refuses the maintenance database', function (): void {
    $live = RestoreDatabases::target('rz'); // migrated: every table of the app
    DB::purge(RestoreDatabases::TARGET);

    try {
        [$code, $json] = resetApp('recreate');
        $tables = RestoreDatabases::admin()->query("SELECT 1 FROM pg_database WHERE datname = '{$live}'")->fetchColumn();
        RestoreDatabases::connect('reset_check', $live);
        $relations = DB::connection('reset_check')->selectOne("SELECT count(*) AS n FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public'");
        DB::purge('reset_check');
        config(['database.connections.'.RestoreDatabases::TARGET.'.database' => 'postgres']);
        DB::purge(RestoreDatabases::TARGET);
        [$refused, $refusedJson] = resetApp('recreate');
    } finally {
        RestoreDatabases::drop(['rz']);
    }

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toBe(['database' => $live, 'dropped' => true, 'created' => true])
        ->and($tables)->toBe(1)
        ->and((int) $relations->n)->toBe(0)
        ->and($refused)->toBe(1)->and($refusedJson['errors'][0]['code'])->toBe('refused_database');
});

it('exports every audit entry with the epoch and the heads, one JSON document per line', function (): void {
    makeOrganization(name: 'Acme Forms');
    $count = DB::table('activity_log')->count();
    $heads = ChainHeads::read();

    $code = Artisan::call('instance:audit-export');
    $lines = array_values(array_filter(explode("\n", Artisan::output())));
    $header = json_decode($lines[0], true);
    $rows = array_map(static fn (string $line): array => json_decode($line, true), array_slice($lines, 1));

    expect($code)->toBe(0)
        ->and($header)->toMatchArray(['format' => 'fsa-audit-export', 'version' => '1.0', 'epoch' => 1, 'entries' => $count])
        ->and($header['heads'])->toEqual(json_decode((string) json_encode((object) $heads), true))
        ->and($header['epochs'])->toBe([])
        ->and($rows)->toHaveCount($count)
        ->and(array_column($rows, 'id'))->toBe(DB::table('activity_log')->orderBy('id')->pluck('id')->all())
        ->and($rows[0])->toHaveKeys(['event', 'organization_id', 'properties', 'created_at']);
});
