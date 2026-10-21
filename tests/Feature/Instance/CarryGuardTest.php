<?php

declare(strict_types=1);

use App\Console\Commands\RestoreFinishCommand;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Instance\BackupRole;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\Instance\CarryFixture;
use Tests\Support\Instance\RestoreDatabases;

/*
 * The guards of the R64 carry (third review of plan U17, ADR 0085). The state difference between the pre-restore
 * ("source") and the restored ("target") database is a record of what administrators took away only when the source
 * descends from the backup. When the source's chain shows a restore, rollback or reset after the backup (undoing a
 * mistaken restore with its safety backup), only what the source's audit log records after the backup, or what carries a
 * timestamp after it, is carried; the rest is counted in `carry_skipped`. In both modes a child row is never removed
 * because its parent (a deleted form or group) is gone from the source: restoring brings the parent back with it.
 */

const GUARD_PASSWORD = '0123456789abcdef0123456789abcdef0123456789abcdef';

const GUARD_BACKUP_AT = '2026-10-19T12:00:00Z';

beforeEach(function (): void {
    RestoreDatabases::target();
    $this->role = RestoreDatabases::role('fieldset_backup_guard');
    putenv(BackupRole::PASSWORD_ENV.'='.GUARD_PASSWORD);
    app()->instance(RestoreFinishCommand::STDIN, static fn (): string => '');
});

afterEach(function (): void {
    putenv(BackupRole::PASSWORD_ENV);
    RestoreDatabases::drop();
});

/** The source as the older backup B left it after it was restored: everything created between B and S is missing. */
function olderBackupAsSource(object $test): void
{
    $source = DB::connection(RestoreDatabases::SOURCE);
    $source->table('external_identities')->where('id', $test->identity->id)->delete();
    $source->table('identity_provider_mappings')->where('id', $test->mapping->id)->delete();
    $source->table('identity_providers')->where('id', $test->otherProvider->id)->delete();
    $source->table('sso_domains')->where('id', $test->domain->id)->delete();
    $source->table('webhook_endpoints')->where('id', $test->deletedHook->id)->delete();
    $source->table('form_access')->where('id', $test->access->id)->delete();
    $source->table('group_user')->where('group_id', $test->group->id)->delete();
    $source->table('users')->where('id', $test->deleted->id)->delete();
    $source->table('users')->where('id', $test->member->id)->update(['email' => 'older-address@example.test']);
    $source->table('users')->where('id', $test->password->id)->update(['password' => 'older-hash-from-b']);
    CarryFixture::epoch($source, '2026-10-19 13:00:00'); // B restored after S was taken
}

it('carries only audited or timestamped changes when the source was itself restored after the backup (review HIGH 1a)', function (): void {
    CarryFixture::scope($this);
    olderBackupAsSource($this);
    $source = DB::connection(RestoreDatabases::SOURCE);
    $token = (int) $source->table('personal_access_tokens')->where('name', 'deleted-token')->value('id');
    $source->table('personal_access_tokens')->where('id', $token)->delete();
    CarryFixture::audit($source, 'api_token.revoked', PersonalAccessToken::class, $token, '2026-10-19 13:30:00');
    $device = (int) $source->table('devices')->where('name', 'revoked')->value('id');
    $source->table('devices')->where('id', $device)->update(['revoked_at' => '2026-10-19 13:40:00']);
    $source->table('devices')->where('name', 'wiped')->update(['wipe_requested_at' => '2026-10-19 11:00:00']); // before the backup
    $source->table('users')->where('id', $this->owner->id)->update(['password' => 'changed-after-restore']);
    CarryFixture::audit($source, 'auth.password_changed', User::class, $this->owner->id, '2026-10-19 13:45:00');
    $source->table('users')->where('id', $this->erased->id)->update(['name' => 'Deleted user', 'email' => 'erased-x@erased.invalid', 'status' => 'deactivated', 'deactivated_at' => '2026-10-19 13:50:00', 'erased_at' => '2026-10-19 13:50:00']);
    $source->table('platform_admins')->where('user_id', $this->member->id)->delete();
    CarryFixture::audit($source, 'platform.admin_revoked', User::class, $this->member->id, '2026-10-19 13:55:00');

    [$code, $json] = CarryFixture::run($this, ['--backup-created-at' => GUARD_BACKUP_AT]);
    $target = DB::connection(RestoreDatabases::TARGET);
    $exists = static fn (string $table, int $id): bool => $target->table($table)->where('id', $id)->exists();

    expect($code)->toBe(0, json_encode($json))->and($json['data']['carry_mode'])->toBe('audit')
        ->and($json['data']['carried'])->toMatchArray(['tokens_deleted' => 1, 'devices_revoked' => 1, 'devices_wiped' => 0, 'passwords' => 1, 'users_erased' => 1, 'operators_revoked' => 1, 'users_missing' => 0, 'emails' => 0])
        ->and($json['data']['carry_skipped'])->toMatchArray([
            'external_identities_deleted' => 1, 'identity_provider_mappings_deleted' => 1, 'identity_providers_deleted' => 1, 'sso_domains_deleted' => 1,
            'webhook_endpoints_deleted' => 1, 'form_access_removed' => 1, 'group_memberships_removed' => 2, 'users_missing' => 1, 'emails' => 1, 'passwords' => 1, 'devices_wiped' => 1,
        ])
        ->and($exists('external_identities', $this->identity->id))->toBeTrue()
        ->and($exists('identity_provider_mappings', $this->mapping->id))->toBeTrue()
        ->and($exists('identity_providers', $this->otherProvider->id))->toBeTrue()
        ->and($exists('sso_domains', $this->domain->id))->toBeTrue()
        ->and($exists('webhook_endpoints', $this->deletedHook->id))->toBeTrue()
        ->and($exists('form_access', $this->access->id))->toBeTrue()
        ->and($target->table('group_user')->where('group_id', $this->group->id)->count())->toBe(2)
        ->and($target->table('users')->where('id', $this->deleted->id)->value('status'))->toBe('active')
        ->and($target->table('users')->where('id', $this->member->id)->value('email'))->toBe($this->member->email)
        ->and($target->table('users')->where('id', $this->password->id)->value('password'))->not->toBe('older-hash-from-b')
        ->and($target->table('users')->where('id', $this->owner->id)->value('password'))->toBe('changed-after-restore')
        ->and($target->table('users')->where('id', $this->erased->id)->value('erased_at'))->toBe('2026-10-19 13:50:00')
        ->and($exists('personal_access_tokens', $token))->toBeFalse()
        ->and($target->table('devices')->where('id', $device)->value('revoked_at'))->toBe('2026-10-19 13:40:00')
        ->and($target->table('devices')->where('name', 'wiped')->value('wipe_requested_at'))->toBeNull()
        ->and($target->table('platform_admins')->pluck('user_id')->all())->toBe([$this->owner->id]);
});

it('dates the backup by the restored database\'s newest audit entry when fsa passes no time', function (): void {
    CarryFixture::scope($this); // the backup's own audit log ends with the seed's entries (now)
    CarryFixture::epoch(DB::connection(RestoreDatabases::SOURCE), now()->utc()->addHour()->format('Y-m-d H:i:s'));
    DB::connection(RestoreDatabases::SOURCE)->table('webhook_endpoints')->where('id', $this->deletedHook->id)->delete();

    [$code, $json] = CarryFixture::run($this);

    expect($code)->toBe(0, json_encode($json))->and($json['data']['carry_mode'])->toBe('audit')
        ->and($json['data']['carry_skipped'])->toMatchArray(['webhook_endpoints_deleted' => 1]);
});

it('keeps the grants and members of a deleted form or group the restore brings back (review HIGH 1b)', function (): void {
    CarryFixture::scope($this);
    $source = DB::connection(RestoreDatabases::SOURCE);
    $source->table('groups')->where('id', $this->group->id)->delete(); // cascades its members and grants in the source
    $source->table('forms')->where('id', $this->access->form_id)->delete();

    [$code, $json] = CarryFixture::run($this, ['--backup-created-at' => GUARD_BACKUP_AT]);
    $target = DB::connection(RestoreDatabases::TARGET);

    expect($code)->toBe(0, json_encode($json))->and($json['data']['carry_mode'])->toBe('state')
        ->and($json['data']['carried'])->toMatchArray(['form_access_removed' => 0, 'group_memberships_removed' => 0])
        ->and($json['data']['cascade_skipped'])->toMatchArray(['form_access_removed' => 1, 'group_memberships_removed' => 2])
        ->and($json['data']['carry_skipped'])->toBe([])
        ->and($target->table('form_access')->where('id', $this->access->id)->exists())->toBeTrue()
        ->and($target->table('group_user')->where('group_id', $this->group->id)->count())->toBe(2);
});

it('still carries the core cases by state when the source descends from the backup, guard or not', function (): void {
    CarryFixture::seed($this);
    RestoreDatabases::sourceFromTarget();
    $source = DB::connection(RestoreDatabases::SOURCE);
    CarryFixture::epoch($source, '2026-10-19 10:00:00'); // a restore before the backup: the backup descends from it
    $source->table('devices')->where('name', 'revoked')->update(['revoked_at' => '2026-10-19 13:00:00']);
    $source->table('users')->where('id', $this->erased->id)->update(['name' => 'Deleted user', 'email' => 'erased-y@erased.invalid', 'status' => 'deactivated', 'erased_at' => '2026-10-19 13:20:00']);
    $source->table('users')->where('id', $this->password->id)->update(['password' => 'new-hash-after-backup']); // no audit entry needed
    $source->table('personal_access_tokens')->where('name', 'deleted-token')->delete();
    $source->table('platform_admins')->where('user_id', $this->member->id)->delete();

    [$code, $json] = CarryFixture::run($this, ['--backup-created-at' => GUARD_BACKUP_AT]);
    $target = DB::connection(RestoreDatabases::TARGET);

    expect($code)->toBe(0, json_encode($json))->and($json['data']['carry_mode'])->toBe('state')
        ->and($json['data']['carried'])->toMatchArray(['devices_revoked' => 1, 'users_erased' => 1, 'passwords' => 1, 'tokens_deleted' => 1, 'operators_revoked' => 1])
        ->and($json['data']['carry_skipped'])->toBe([])->and($json['data']['cascade_skipped'])->toBe([])
        ->and($target->table('devices')->where('name', 'revoked')->value('revoked_at'))->toBe('2026-10-19 13:00:00')
        ->and($target->table('users')->where('id', $this->password->id)->value('password'))->toBe('new-hash-after-backup')
        ->and($target->table('personal_access_tokens')->pluck('name')->all())->toBe(['kept-token']);
});

it('refuses a backup time or journal that is no ISO timestamp or hex ID', function (array $options, string $message): void {
    CarryFixture::seed($this);
    RestoreDatabases::sourceFromTarget();

    [$code, $json] = CarryFixture::run($this, $options);

    expect($code)->not->toBe(0)->and($json['errors'][0]['code'])->toBe('invalid_input')->and($json['errors'][0]['message'])->toContain($message);
})->with([
    'garbage time' => [['--backup-created-at' => 'yesterday'], '--backup-created-at'],
    'time without zone' => [['--backup-created-at' => '2026-10-19 12:00:00'], '--backup-created-at'],
    'journal with a quote' => [['--journal' => "abc'def"], '--journal'],
]);

it('keeps a device the source does not know and a backup-era revocation out of the audit-mode carry', function (): void {
    CarryFixture::seed($this);
    RestoreDatabases::sourceFromTarget();
    $source = DB::connection(RestoreDatabases::SOURCE);
    CarryFixture::epoch($source, '2026-10-19 13:00:00');
    $source->table('devices')->where('name', 'gone')->delete(); // registered between B and S
    $source->table('organization_user')->where('user_id', $this->member->id)->update(['status' => 'deactivated']);
    CarryFixture::audit($source, 'device.revoked', Device::class, 999_999, '2026-10-19 14:00:00'); // another device

    [$code, $json] = CarryFixture::run($this, ['--backup-created-at' => GUARD_BACKUP_AT]);
    $target = DB::connection(RestoreDatabases::TARGET);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['carry_skipped'])->toMatchArray(['devices_revoked' => 1, 'devices_wiped' => 1, 'memberships_deactivated' => 1])
        ->and($target->table('devices')->where('name', 'gone')->value('revoked_at'))->toBeNull()
        ->and($target->table('organization_user')->where('user_id', $this->member->id)->value('status'))->not->toBe('deactivated');
});

it('carries nothing from a source that was reset after the backup: its rows are other people under other IDs (U23 review A5)', function (): void {
    CarryFixture::seed($this);
    RestoreDatabases::sourceFromTarget();
    $source = DB::connection(RestoreDatabases::SOURCE);
    $source->table('users')->where('id', $this->owner->id)->update(['password' => 'fresh-owner-hash', 'updated_at' => '2026-10-19 14:00:00']);
    CarryFixture::audit($source, 'auth.password_changed', User::class, $this->owner->id, '2026-10-19 14:00:00');
    $source->table('users')->where('id', $this->deactivated->id)->update(['status' => 'deactivated', 'deactivated_at' => '2026-10-19 14:05:00']);
    CarryFixture::epoch($source, '2026-10-19 13:00:00', 'reset');

    [$code, $json] = CarryFixture::run($this, ['--backup-created-at' => GUARD_BACKUP_AT]);
    $target = DB::connection(RestoreDatabases::TARGET);

    expect($code)->toBe(0, json_encode($json))
        ->and($json['data']['carry_mode'])->toBe('reset')
        ->and($json['data']['carried'])->toBe([])
        ->and($target->table('users')->where('id', $this->owner->id)->value('password'))->not->toBe('fresh-owner-hash')
        ->and($target->table('users')->where('id', $this->deactivated->id)->value('status'))->toBe('active');
});

it('carries a change audited or stamped in the very second the backup was made: its time is whole seconds (M12 gate D8)', function (): void {
    CarryFixture::scope($this);
    $source = DB::connection(RestoreDatabases::SOURCE);
    CarryFixture::epoch($source, '2026-10-19 13:00:00'); // the source was restored after the backup: audit mode
    $token = (int) $source->table('personal_access_tokens')->where('name', 'deleted-token')->value('id');
    $source->table('personal_access_tokens')->where('id', $token)->delete();
    CarryFixture::audit($source, 'api_token.revoked', PersonalAccessToken::class, $token, '2026-10-19 12:00:00');
    $device = (int) $source->table('devices')->where('name', 'revoked')->value('id');
    $source->table('devices')->where('id', $device)->update(['revoked_at' => '2026-10-19 12:00:00']);

    [$code, $json] = CarryFixture::run($this, ['--backup-created-at' => GUARD_BACKUP_AT]);

    expect($code)->toBe(0, json_encode($json))->and($json['data']['carry_mode'])->toBe('audit')
        ->and($json['data']['carried'])->toMatchArray(['tokens_deleted' => 1, 'devices_revoked' => 1]);
});
