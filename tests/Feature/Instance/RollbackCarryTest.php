<?php

declare(strict_types=1);

use App\Console\Commands\RestoreFinishCommand;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Instance\BackupRole;
use App\Domain\Platform\Instance\Restore\SecurityExport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Instance\CarryFixture;
use Tests\Support\Instance\RestoreDatabases;

/*
 * R64 over a manual `fsa rollback` (plan U18, ADR 0086): the newer release exports its security state as a versioned
 * document (`instance:security-export`), and the older release's `instance:restore-finish --reason=rolled_back` reads it
 * from stdin instead of a second database, materialised in a schema of the restored database for the unchanged carry and
 * sequence floors. The target is seeded as the pre-upgrade safety backup, the source changes the way the upgraded
 * instance did.
 */

const ROLLBACK_CARRY_PASSWORD = 'fedcba9876543210fedcba9876543210fedcba9876543210';

beforeEach(function (): void {
    RestoreDatabases::target();
    $this->role = RestoreDatabases::role('fsa_backup_rollback');
    putenv(BackupRole::PASSWORD_ENV.'='.ROLLBACK_CARRY_PASSWORD);
});

afterEach(function (): void {
    putenv(BackupRole::PASSWORD_ENV);
    RestoreDatabases::drop();
});

/** The security state export of the source database, as the current image's one-off prints it. */
function rollbackExport(?string $since = null): array
{
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection(RestoreDatabases::SOURCE);
    try {
        Artisan::call('instance:security-export', ['--json' => true, ...($since === null ? [] : ['--since' => $since])]);
    } finally {
        DB::setDefaultConnection($default);
    }

    return json_decode(trim(Artisan::output()), true);
}

/** @return array{0: int, 1: array<string, mixed>} */
function rollbackFinish(object $test, array $document): array
{
    app()->instance(RestoreFinishCommand::STDIN, static fn (): string => (string) json_encode(['previous_heads' => (object) [], 'previous_epoch' => 1, 'from_backup' => false, 'carry' => $document]));
    $code = Artisan::call('instance:restore-finish', ['--reason' => 'rolled_back', '--backup' => 'safety-upgrade-20261019T120000Z', '--backup-role' => $test->role, '--journal' => '0123456789abcdef', '--backup-created-at' => '2026-10-19T12:00:00Z', '--json' => true]);

    return [$code, json_decode(trim(Artisan::output()), true)];
}

it('carries the security state from the newer release\'s export: revocations, erasures, passwords, tokens, operators, sequences', function (): void {
    CarryFixture::seed($this);
    RestoreDatabases::sourceFromTarget();
    $source = DB::connection(RestoreDatabases::SOURCE);
    $source->table('devices')->where('name', 'revoked')->update(['revoked_at' => '2026-10-19 13:00:00']);
    $source->table('users')->where('id', $this->erased->id)->update([
        'name' => 'Deleted user', 'email' => 'erased-'.$this->erased->id.'-abc@erased.invalid', 'password' => 'scrubbed-hash',
        'status' => 'deactivated', 'deactivated_at' => '2026-10-19 13:20:00', 'erased_at' => '2026-10-19 13:20:00',
    ]);
    $source->table('users')->where('id', $this->password->id)->update(['password' => 'new-hash-after-upgrade']);
    $source->table('personal_access_tokens')->where('name', 'deleted-token')->delete();
    $source->table('platform_admins')->where('user_id', $this->member->id)->delete();
    $source->statement("SELECT setval('users_id_seq', 9000, true)");
    $export = rollbackExport('2026-10-19T12:00:00Z');

    [$code, $json] = rollbackFinish($this, $export['data']);
    $target = DB::connection(RestoreDatabases::TARGET);
    $user = static fn (User $u): object => $target->table('users')->where('id', $u->id)->first();

    expect($export['status'])->toBe('ok')
        ->and($export['data'])->toMatchArray(['format' => SecurityExport::FORMAT, 'version' => SecurityExport::VERSION, 'since' => '2026-10-19T12:00:00Z'])
        ->and(array_keys($export['data']['tables']))->toContain('users', 'devices', 'activity_log', 'number_sequences')
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['carry_mode'])->toBe('state')
        ->and($json['data']['carried'])->toMatchArray(['devices_revoked' => 1, 'users_erased' => 1, 'passwords' => 1, 'tokens_deleted' => 1, 'operators_revoked' => 1])
        ->and($json['data']['sequences']['sequences'])->toBeGreaterThanOrEqual(1)
        ->and($target->table('devices')->where('name', 'revoked')->value('revoked_at'))->toBe('2026-10-19 13:00:00')
        ->and($user($this->erased)->erased_at)->toBe('2026-10-19 13:20:00')
        ->and($user($this->password)->password)->toBe('new-hash-after-upgrade')
        ->and($target->table('personal_access_tokens')->pluck('name')->all())->toBe(['kept-token'])
        ->and($target->table('platform_admins')->pluck('user_id')->all())->toBe([$this->owner->id])
        ->and((int) $target->selectOne("SELECT last_value FROM pg_sequences WHERE sequencename = 'users_id_seq'")->last_value)->toBe(9000)
        ->and($target->table('activity_log')->where('event', 'instance.rolled_back')->count())->toBe(1)
        ->and($target->select("SELECT nspname FROM pg_namespace WHERE nspname LIKE 'fsa_carry_%'"))->toBe([]);
});

it('carries only audited changes when the upgraded database was itself restored after the safety backup', function (): void {
    CarryFixture::seed($this);
    RestoreDatabases::sourceFromTarget();
    $source = DB::connection(RestoreDatabases::SOURCE);
    CarryFixture::epoch($source, '2026-10-19 14:00:00', 'rolled_back');
    $source->table('users')->where('id', $this->password->id)->update(['password' => 'unaudited-hash']);

    [$code, $json] = rollbackFinish($this, rollbackExport('2026-10-19T12:00:00Z')['data']);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['carry_mode'])->toBe('audit')
        ->and($json['data']['carried']['passwords'])->toBe(0)
        ->and(DB::connection(RestoreDatabases::TARGET)->table('users')->where('id', $this->password->id)->value('password'))->not->toBe('unaudited-hash');
});

it('refuses an export of another format or a newer version, writing nothing', function (array $document): void {
    CarryFixture::seed($this);

    [$code, $json] = rollbackFinish($this, $document);

    expect($code)->toBe(1)->and($json['errors'][0]['code'])->toBe('carry_format')
        ->and(DB::connection(RestoreDatabases::TARGET)->table('activity_log')->where('event', 'instance.rolled_back')->count())->toBe(0);
})->with([
    [['format' => SecurityExport::FORMAT, 'version' => SecurityExport::VERSION + 1, 'tables' => []]],
    [['format' => 'something-else', 'version' => 1, 'tables' => []]],
]);

it('counts what a rollback would lose since its safety backup', function (): void {
    CarryFixture::seed($this);

    Artisan::call('instance:rollback-loss', ['--since' => '2020-01-01T00:00:00Z', '--json' => true]);
    $json = json_decode(trim(Artisan::output()), true);
    Artisan::call('instance:rollback-loss', ['--json' => true]);
    $missing = json_decode(trim(Artisan::output()), true);

    expect($json['status'])->toBe('ok')->and($json['data']['counts']['users'])->toBeGreaterThanOrEqual(7)
        ->and($json['data']['counts'])->toHaveKeys(['submissions', 'activity_log', 'users', 'forms'])
        ->and($missing['errors'][0]['code'])->toBe('invalid_input');
});
