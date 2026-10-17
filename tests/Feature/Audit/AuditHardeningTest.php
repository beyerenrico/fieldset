<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Chain\AuditChain;
use App\Domain\Audit\Chain\ChainVerifier;
use App\Domain\Audit\Models\Activity;
use App\Domain\Audit\Retention\PruneAuditLog;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/* ADR 0038: append-only trigger, retention pruning through audit_prune(), hash chain + verify. */

/** Runs a statement that must fail inside a savepoint, so the test transaction survives (LEARNINGS #11). */
function expectRefusedByDatabase(Closure $statement): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class, 'append-only');
}

it('refuses UPDATE, DELETE and TRUNCATE on the audit log from any path', function (): void {
    $entry = app(AuditLogger::class)->record('x.test', null, ['a' => 1]);

    expectRefusedByDatabase(fn () => DB::table('activity_log')->where('id', $entry->id)->update(['description' => 'changed']));
    expectRefusedByDatabase(fn () => DB::table('activity_log')->where('id', $entry->id)->update(['hash' => str_repeat('a', 64)]));
    expectRefusedByDatabase(fn () => DB::table('activity_log')->where('id', $entry->id)->delete());
    expectRefusedByDatabase(fn () => DB::statement('TRUNCATE activity_log'));
    expectRefusedByDatabase(fn () => Activity::query()->whereKey($entry->id)->delete());

    expect(DB::table('activity_log')->where('id', $entry->id)->value('description'))->toBe('x.test');
});

it('seals every entry into its organization chain after commit', function (): void {
    $org = makeOrganization();
    app(TenantContext::class)->set($org);
    clearAuditLog();

    $first = app(AuditLogger::class)->record('x.one');
    $second = app(AuditLogger::class)->record('x.two');
    $first->refresh();
    $second->refresh();

    expect($first->chain_seq)->toBe(1)->and($first->prev_hash)->toBe(AuditChain::GENESIS)
        ->and($second->chain_seq)->toBe(2)->and($second->prev_hash)->toBe($first->hash)
        ->and(app(ChainVerifier::class)->verify()->intact())->toBeTrue();
});

it('does not seal an entry whose transaction rolled back', function (): void {
    clearAuditLog();

    try {
        DB::transaction(function (): void {
            app(AuditLogger::class)->record('x.rolled_back');

            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }
    app(AuditLogger::class)->record('x.kept');

    expect(Activity::query()->pluck('event')->all())->toBe(['x.kept'])
        ->and(Activity::query()->sole()->chain_seq)->toBe(1);
});

it('detects a modified, removed or inserted entry', function (): void {
    clearAuditLog();
    $entries = collect(range(1, 4))->map(fn (int $i) => app(AuditLogger::class)->record("x.{$i}", null, ['i' => $i]));
    expect(app(ChainVerifier::class)->verify()->intact())->toBeTrue();

    // Simulate tampering by a database owner who disables the trigger.
    DB::statement('ALTER TABLE activity_log DISABLE TRIGGER activity_log_append_only_trg');
    DB::table('activity_log')->where('id', $entries[1]->id)->update(['properties' => '{"i":99}']);
    DB::table('activity_log')->where('id', $entries[2]->id)->delete();
    DB::statement('ALTER TABLE activity_log ENABLE TRIGGER activity_log_append_only_trg');

    $report = app(ChainVerifier::class)->verify();
    expect($report->intact())->toBeFalse()
        ->and(implode("\n", $report->problems))->toContain("entry #{$entries[1]->id}")->toContain('entries missing between seq 2 and 4');

    $this->artisan('audit:verify')->assertExitCode(1);
});

it('never cuts a chain in the middle: an old entry sealed after newer ones stays', function (): void {
    clearAuditLog();
    // Inserted 400 days ago but committed (sealed) only after a newer entry: its chain position is 2.
    DB::table('activity_log')->insert([
        'log_name' => 'audit', 'description' => 'x.late', 'event' => 'x.late', 'actor_type' => 'system',
        'created_at' => now()->subDays(400), 'updated_at' => now()->subDays(400),
    ]);
    $newer = app(AuditLogger::class)->record('x.newer');
    app(AuditChain::class)->sealPending();

    expect(app(PruneAuditLog::class)->handle(365))->toBe(0)
        ->and(Activity::query()->where('event', 'x.late')->sole()->chain_seq)->toBe(2)
        ->and($newer->refresh()->chain_seq)->toBe(1)
        ->and(app(ChainVerifier::class)->verify()->intact())->toBeTrue();
});

it('prunes a chain prefix through audit:prune and verify accepts the recorded anchor', function (): void {
    clearAuditLog();
    $this->travel(-500)->days();
    $old = collect(range(1, 3))->map(fn (int $i) => app(AuditLogger::class)->record("x.old{$i}"));
    $this->travelBack();
    $kept = app(AuditLogger::class)->record('x.kept');

    $this->artisan('audit:prune', ['--days' => 365])->assertSuccessful();

    $pruned = Activity::query()->where('event', 'audit.pruned')->sole();
    expect(Activity::query()->whereIn('id', $old->pluck('id'))->count())->toBe(0)
        ->and(Activity::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and($pruned->organization_id)->toBeNull()
        ->and($pruned->properties['deleted'])->toBe(3)
        ->and($pruned->properties['anchors'][0]['seq'] ?? $pruned->properties['anchors']['0']['seq'])->toBe(3)
        ->and(app(ChainVerifier::class)->verify()->intact())->toBeTrue();
    $this->artisan('audit:verify')->assertSuccessful();
});

it('reads the retention period from audit.retention_days (default 365)', function (): void {
    expect(PruneAuditLog::retentionDays())->toBe(365);
    config(['audit.retention_days' => 30]);
    expect(PruneAuditLog::retentionDays())->toBe(30);
});

it('seals entries whose after-commit seal did not run', function (): void {
    clearAuditLog();
    DB::table('activity_log')->insert([
        'log_name' => 'audit', 'description' => 'x.raw', 'event' => 'x.raw', 'actor_type' => 'system',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->artisan('audit:seal')->assertSuccessful();

    expect(Activity::query()->where('event', 'x.raw')->sole()->hash)->not->toBeNull()
        ->and(app(ChainVerifier::class)->verify()->intact())->toBeTrue();
});

it('never prunes entries younger than 30 days, whoever calls audit_prune()', function (): void {
    expect(fn () => DB::transaction(fn () => DB::select("SELECT * FROM audit_prune((now() AT TIME ZONE 'UTC')::timestamp)")))
        ->toThrow(QueryException::class, 'younger than 30 days')
        ->and(PruneAuditLog::retentionDays())->toBeGreaterThanOrEqual(PruneAuditLog::MIN_DAYS);

    $this->artisan('audit:prune', ['--days' => 7])->assertFailed();
});

it('fails verification while old entries stay unsealed and prints the chain heads', function (): void {
    clearAuditLog();
    $sealed = app(AuditLogger::class)->record('x.sealed');
    DB::table('activity_log')->insert([
        'log_name' => 'audit', 'description' => 'x.raw', 'event' => 'x.raw', 'actor_type' => 'system',
        'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
    ]);

    $report = app(ChainVerifier::class)->verify();
    expect($report->intact())->toBeFalse()->and($report->unsealed)->toBe(1)
        ->and($report->heads[0])->toBe(['seq' => 1, 'hash' => $sealed->refresh()->hash]);

    app(AuditChain::class)->sealPending();
    $this->artisan('audit:verify', ['--heads' => true])->assertSuccessful()->expectsOutputToContain('"heads"');
});
