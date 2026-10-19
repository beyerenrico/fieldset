<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Forms\Models\FormAccess;
use App\Domain\Identity\Enums\SystemRole;
use App\Domain\Identity\Models\ExternalIdentity;
use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\IdentityProvider;
use App\Domain\Identity\Models\IdentityProviderMapping;
use App\Domain\Identity\Models\SsoDomain;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use App\Domain\Workflows\Models\WebhookEndpoint;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The backup's state for the R64 carry tests (plan U17, ADR 0085): the target is seeded as the backup, the source starts
 * as a copy of it and then changes the way the live instance did. `epoch()` and `audit()` write what a live database's
 * chain and audit log record after the backup (a restore epoch, an audited action).
 */
final class CarryFixture
{
    /** An organization with owner, members, devices, tokens, an operator and stale credentials. */
    public static function seed(object $test): void
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

    /** Review S3: SSO, groups, form access, a second organization and webhooks in the backup; the source starts as a copy. */
    public static function scope(object $test): void
    {
        self::seed($test);
        $tenant = app(TenantContext::class);
        $test->other = makeOrganization(name: 'Other GmbH');
        $tenant->run($test->org, function () use ($test): void {
            $test->provider = IdentityProvider::factory()->active()->create(['jit_enabled' => true]);
            $test->identity = ExternalIdentity::factory()->create(['user_id' => $test->member->id]);
            $test->mapping = IdentityProviderMapping::factory()->create();
            $test->domain = SsoDomain::factory()->create(['domain' => 'gone.example']);
            $test->verified = SsoDomain::factory()->verified()->create(['domain' => 'kept.example']);
            $test->group = Group::factory()->create();
            $test->access = FormAccess::factory()->create(['group_id' => $test->group->id, 'role_name' => null]);
            $test->deletedHook = WebhookEndpoint::factory()->create(['name' => 'deleted hook']);
            $test->pausedHook = WebhookEndpoint::factory()->create(['name' => 'paused hook']);
        });
        $test->otherProvider = $tenant->run($test->other, fn (): IdentityProvider => IdentityProvider::factory()->create());
        foreach ([$test->member, $test->password] as $user) {
            DB::table('group_user')->insert(['organization_id' => $test->org->id, 'group_id' => $test->group->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        RestoreDatabases::sourceFromTarget();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function run(object $test, array $options = []): array
    {
        $code = Artisan::call('instance:restore-finish', ['--source' => RestoreDatabases::name('rs'), '--backup' => 'manual-20261019T120000Z', '--backup-role' => $test->role, '--json' => true, ...$options]);

        return [$code, json_decode(trim(Artisan::output()), true)];
    }

    /** A restore, rollback or reset epoch in a database's chain, started at `$startedAt` (UTC). */
    public static function epoch(ConnectionInterface $db, string $startedAt, string $reason = 'restored'): void
    {
        $db->table('audit_chain_epochs')->insert(['epoch' => ((int) $db->table('audit_chain_epochs')->max('epoch')) + 1, 'started_at' => $startedAt.'+00', 'reason' => $reason, 'previous_heads' => '{}']);
    }

    /**
     * An audit entry the live instance wrote (`log_name` audit), as AuditLogger::record leaves it.
     *
     * @param  class-string  $subject
     * @param  array<string, mixed>  $properties
     */
    public static function audit(ConnectionInterface $db, string $event, string $subject, int $id, string $at, array $properties = []): void
    {
        $db->table('activity_log')->insert([
            'log_name' => 'audit', 'description' => $event, 'event' => $event, 'subject_type' => new $subject()->getMorphClass(), 'subject_id' => $id,
            'properties' => json_encode((object) $properties), 'created_at' => $at, 'updated_at' => $at,
        ]);
    }
}
