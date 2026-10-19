<?php

declare(strict_types=1);

use App\Console\Commands\RestoreFinishCommand;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Instance\BackupRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Instance\RestoreDatabases;

/*
 * R64 carried over a restore (plan U17, KTD18, ADR 0085): what changed after the backup in the pre-restore database
 * ("source") is re-applied to the restored one ("target") by `instance:restore-finish --source`, before the swap. The
 * target is seeded as the backup, the source is a copy of it that then changes the way the live instance did.
 */

const CARRY_PASSWORD = '0123456789abcdef0123456789abcdef0123456789abcdef';

beforeEach(function (): void {
    RestoreDatabases::target();
    $this->role = RestoreDatabases::role('fsa_backup_carry');
    putenv(BackupRole::PASSWORD_ENV.'='.CARRY_PASSWORD);
    app()->instance(RestoreFinishCommand::STDIN, static fn (): string => '');
});

afterEach(function (): void {
    putenv(BackupRole::PASSWORD_ENV);
    RestoreDatabases::drop();
});

/** The backup's state: an organization with owner, members, devices, tokens, an operator and stale credentials. */
function carrySeed(object $test): void
{
    $org = makeOrganization();
    $test->org = $org;
    $test->owner = ownerOf($org);
    foreach (['revoked', 'deactivated', 'erased', 'password', 'deleted', 'member'] as $name) {
        $test->{$name} = memberOf($org, SystemRole::FieldWorker);
    }
    foreach (['revoked', 'wiped', 'kept'] as $index => $name) {
        DB::table('devices')->insert(['user_id' => $test->revoked->id, 'device_uuid' => (string) Str::uuid(), 'secret_hash' => 'h'.$index, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('devices')->insert(['user_id' => $test->member->id, 'device_uuid' => (string) Str::uuid(), 'secret_hash' => 'gone', 'name' => 'gone', 'created_at' => now(), 'updated_at' => now()]);
    foreach (['kept-token' => $test->member, 'deleted-token' => $test->password] as $name => $user) {
        DB::table('personal_access_tokens')->insert(['tokenable_type' => new User()->getMorphClass(), 'tokenable_id' => $user->id, 'organization_id' => $org->id, 'name' => $name, 'token' => hash('sha256', $name), 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('platform_admins')->insert([['user_id' => $test->owner->id, 'created_at' => now(), 'updated_at' => now()], ['user_id' => $test->member->id, 'created_at' => now(), 'updated_at' => now()]]);
    DB::table('sessions')->insert(['id' => 'backup-session', 'user_id' => $test->member->id, 'payload' => 'x', 'last_activity' => time()]);
    DB::table('password_reset_tokens')->insert(['email' => $test->member->email, 'token' => 'backup-reset', 'created_at' => now()]);
    DB::table('login_links')->insert(['user_id' => $test->member->id, 'kind' => 'admin', 'token_hash' => hash('sha256', 'backup-link'), 'expires_at' => now()->addMinutes(15), 'created_by' => 'cli', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('users')->where('id', $test->member->id)->update(['remember_token' => 'backup-remember']);
    DB::table('mail_messages')->insert(['organization_id' => $org->id, 'recipient_email' => $test->erased->email, 'subject' => 'Hello', 'mailable_class' => 'x', 'payload' => encrypt('x', false), 'status' => 'sent', 'queued_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}

/** @return array{0: int, 1: array<string, mixed>} */
function carryRun(object $test, array $options = []): array
{
    $code = Artisan::call('instance:restore-finish', ['--source' => RestoreDatabases::name('rs'), '--backup' => 'manual-20261019T120000Z', '--backup-role' => $test->role, '--json' => true, ...$options]);

    return [$code, json_decode(trim(Artisan::output()), true)];
}

it('carries revocations, deactivations, erasures, changed passwords and MFA, deleted tokens and revoked operators', function (): void {
    carrySeed($this);
    $erasedEmail = $this->erased->email;
    RestoreDatabases::sourceFromTarget();
    $source = DB::connection(RestoreDatabases::SOURCE);
    $source->table('devices')->where('name', 'revoked')->update(['revoked_at' => '2026-10-19 13:00:00']);
    $source->table('devices')->where('name', 'wiped')->update(['wipe_requested_at' => '2026-10-19 13:05:00']);
    $source->table('devices')->where('name', 'gone')->delete();
    $source->table('users')->where('id', $this->deactivated->id)->update(['status' => 'deactivated', 'deactivated_at' => '2026-10-19 13:10:00']);
    $source->table('users')->where('id', $this->erased->id)->update([
        'name' => 'Deleted user', 'email' => 'erased-'.$this->erased->id.'-abc@erased.invalid', 'password' => 'scrubbed-hash',
        'status' => 'deactivated', 'deactivated_at' => '2026-10-19 13:20:00', 'erased_at' => '2026-10-19 13:20:00',
    ]);
    $source->table('users')->where('id', $this->password->id)->update(['password' => 'new-hash-after-backup']);
    $source->table('users')->where('id', $this->owner->id)->update(['two_factor_secret' => encrypt('new-secret'), 'two_factor_confirmed_at' => '2026-10-19 13:30:00']);
    $source->table('users')->where('id', $this->deleted->id)->delete();
    $source->table('personal_access_tokens')->where('name', 'deleted-token')->delete();
    $source->table('platform_admins')->where('user_id', $this->member->id)->delete();

    [$code, $json] = carryRun($this);
    $target = DB::connection(RestoreDatabases::TARGET);
    $user = static fn (User $u): object => $target->table('users')->where('id', $u->id)->first();
    $device = static fn (string $name): object => $target->table('devices')->where('name', $name)->first();

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['carried'])->toMatchArray(['devices_revoked' => 2, 'devices_wiped' => 2, 'users_deactivated' => 1, 'users_erased' => 1, 'users_missing' => 1, 'passwords' => 1, 'mfa' => 1, 'tokens_deleted' => 1, 'operators_revoked' => 1])
        ->and($device('revoked')->revoked_at)->toBe('2026-10-19 13:00:00')
        ->and($device('wiped')->wipe_requested_at)->toBe('2026-10-19 13:05:00')
        ->and($device('kept')->revoked_at)->toBeNull()
        ->and($target->table('devices')->where('secret_hash', 'gone')->first()->revoked_at)->not->toBeNull()
        ->and($user($this->deactivated)->status)->toBe('deactivated')
        ->and((array) $user($this->erased))->toMatchArray(['name' => 'Deleted user', 'email' => 'erased-'.$this->erased->id.'-abc@erased.invalid', 'erased_at' => '2026-10-19 13:20:00', 'status' => 'deactivated'])
        ->and($target->table('mail_messages')->whereRaw('lower(recipient_email) = ?', [mb_strtolower($erasedEmail)])->count())->toBe(0)
        ->and($target->table('organization_user')->where('user_id', $this->erased->id)->value('status'))->toBe('deactivated')
        ->and($target->table('model_has_roles')->where('model_id', $this->erased->id)->count())->toBe(0)
        ->and($user($this->password)->password)->toBe('new-hash-after-backup')
        ->and(decrypt($user($this->owner)->two_factor_secret))->toBe('new-secret')
        ->and($user($this->deleted)->status)->toBe('deactivated')
        ->and($target->table('personal_access_tokens')->pluck('name')->all())->toBe(['kept-token'])
        ->and($target->table('platform_admins')->pluck('user_id')->all())->toBe([$this->owner->id]);
});

it('deactivates memberships and removes roles the source no longer grants, and adds nothing granted after the backup', function (): void {
    carrySeed($this);
    RestoreDatabases::sourceFromTarget();
    $source = DB::connection(RestoreDatabases::SOURCE);
    $source->table('organization_user')->where('user_id', $this->member->id)->update(['status' => 'deactivated']);
    $source->table('model_has_roles')->where('model_id', $this->revoked->id)->delete();
    $newcomer = (int) $source->table('users')->insertGetId(['name' => 'After backup', 'email' => 'new@example.test', 'password' => 'x', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    $source->table('platform_admins')->insert(['user_id' => $newcomer, 'created_at' => now(), 'updated_at' => now()]);

    [$code, $json] = carryRun($this);
    $target = DB::connection(RestoreDatabases::TARGET);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['carried'])->toMatchArray(['memberships_deactivated' => 1, 'roles_removed' => 1, 'passwords' => 0, 'users_deactivated' => 0])
        ->and($target->table('organization_user')->where('user_id', $this->member->id)->value('status'))->toBe('deactivated')
        ->and($target->table('model_has_roles')->where('model_id', $this->revoked->id)->count())->toBe(0)
        ->and($target->table('model_has_roles')->where('model_id', $this->member->id)->count())->toBe(1)
        ->and($target->table('users')->where('email', 'new@example.test')->exists())->toBeFalse()
        ->and($target->table('platform_admins')->count())->toBe(2);
});

it('clears sessions, remember tokens, reset tokens and sign-in links from the backup', function (): void {
    carrySeed($this);
    $remembered = DB::table('users')->whereNotNull('remember_token')->count();
    RestoreDatabases::sourceFromTarget();

    [$code, $json] = carryRun($this);
    $target = DB::connection(RestoreDatabases::TARGET);

    expect($code)->toBe(0, json_encode($json))
        ->and($remembered)->toBeGreaterThan(0)
        ->and($json['data']['cleared'])->toBe(['sessions' => 1, 'remember_tokens' => $remembered, 'password_reset_tokens' => 1, 'login_links' => 1])
        ->and($target->table('sessions')->count())->toBe(0)
        ->and($target->table('password_reset_tokens')->count())->toBe(0)
        ->and($target->table('login_links')->count())->toBe(0)
        ->and($target->table('users')->whereNotNull('remember_token')->count())->toBe(0);
});

it('skips the carry with --no-carry and without --source, but always clears stale credentials', function (array $options): void {
    carrySeed($this);
    RestoreDatabases::sourceFromTarget();
    DB::connection(RestoreDatabases::SOURCE)->table('users')->where('id', $this->deactivated->id)->update(['status' => 'deactivated']);

    $code = Artisan::call('instance:restore-finish', ['--backup-role' => $this->role, '--json' => true, ...$options]);
    $json = json_decode(trim(Artisan::output()), true);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['carried'])->toBeNull()
        ->and($json['data']['sequences'])->toBeNull()
        ->and($json['data']['cleared']['sessions'])->toBe(1)
        ->and(DB::connection(RestoreDatabases::TARGET)->table('users')->where('id', $this->deactivated->id)->value('status'))->toBe('active');
})->with([
    'no-carry' => [['--source' => 'placeholder', '--no-carry' => true]],
    'no source (from-backup)' => [[]],
]);
