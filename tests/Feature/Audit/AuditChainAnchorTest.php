<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Chain\AuditChain;
use App\Domain\Audit\Chain\ChainVerifier;
use App\Domain\Audit\Models\Activity;
use App\Domain\Audit\Retention\PruneAuditLog;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/*
 * ADR 0038: a chain that retention pruned completely continues from its anchor (the last deleted position),
 * never from seq 1 again, and audit:verify requires that start while an anchor exists.
 */

/** Three entries of the chain written 500 days ago, then audit:prune (365 days): the chain is empty afterwards. */
function auditPruneWholeChain(?Organization $org): Activity
{
    test()->travel(-500)->days();
    foreach (range(1, 3) as $i) {
        $write = fn () => app(AuditLogger::class)->record("x.old{$i}");
        $org instanceof Organization ? app(TenantContext::class)->run($org, $write) : $write();
    }
    test()->travelBack();
    $last = Activity::query()->where('event', 'x.old3')->sole();
    app(PruneAuditLog::class)->handle(365);

    return $last;
}

/** Simulates a database owner who switches the append-only trigger off. */
function auditTamper(Closure $statements): void
{
    DB::statement('ALTER TABLE activity_log DISABLE TRIGGER activity_log_append_only_trg');
    $statements();
    DB::statement('ALTER TABLE activity_log ENABLE TRIGGER activity_log_append_only_trg');
}

beforeEach(function (): void {
    $this->org = makeOrganization();
    app(TenantContext::class)->clear();
    clearAuditLog();
});

it('continues an organization chain from its anchor after a full prune', function (): void {
    $last = auditPruneWholeChain($this->org);
    expect(Activity::query()->where('organization_id', $this->org->id)->count())->toBe(0);

    $next = app(TenantContext::class)->run($this->org, fn () => app(AuditLogger::class)->record('x.new'))->refresh();

    expect($next->chain_seq)->toBe(4)->and($next->prev_hash)->toBe($last->hash)
        ->and(app(ChainVerifier::class)->verify()->intact())->toBeTrue();
});

it('continues the platform chain from its anchor when the prune entry is its only entry', function (): void {
    $last = auditPruneWholeChain(null);

    $pruned = Activity::query()->where('event', 'audit.pruned')->sole();
    expect($pruned->chain_seq)->toBe(4)->and($pruned->prev_hash)->toBe($last->hash)
        ->and(app(ChainVerifier::class)->verify()->intact())->toBeTrue();
});

it('reports an empty pruned chain with its anchor as head, so removing its new entries shows in --heads', function (): void {
    $last = auditPruneWholeChain($this->org);
    expect(app(ChainVerifier::class)->verify()->heads[$this->org->id])->toBe(['seq' => 3, 'hash' => $last->hash]);

    $new = collect(['x.a', 'x.b'])->map(fn (string $e) => app(TenantContext::class)->run($this->org, fn () => app(AuditLogger::class)->record($e)));
    expect(app(ChainVerifier::class)->verify()->heads[$this->org->id]['seq'])->toBe(5);

    auditTamper(fn () => DB::table('activity_log')->whereIn('id', $new->pluck('id'))->delete());
    $report = app(ChainVerifier::class)->verify($this->org->id);
    expect($report->heads[$this->org->id])->toBe(['seq' => 3, 'hash' => $last->hash]);
});

it('detects a continued chain whose first entries were removed or restarted at seq 1', function (): void {
    auditPruneWholeChain($this->org);
    $first = app(TenantContext::class)->run($this->org, fn () => app(AuditLogger::class)->record('x.first'))->refresh();
    app(TenantContext::class)->run($this->org, fn () => app(AuditLogger::class)->record('x.second'));

    auditTamper(fn () => DB::table('activity_log')->where('id', $first->id)->delete());
    expect(implode("\n", app(ChainVerifier::class)->verify()->problems))->toContain("chain {$this->org->id}: first entry");

    // A forged fresh chain (seq 1 from genesis, hashes recomputed) no longer passes while an anchor exists.
    auditTamper(function (): void {
        DB::table('activity_log')->where('organization_id', $this->org->id)->delete();
        $id = DB::table('activity_log')->insertGetId([
            'log_name' => 'audit', 'description' => 'x.forged', 'event' => 'x.forged', 'actor_type' => 'system',
            'organization_id' => $this->org->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('activity_log')->where('id', $id)->first();
        DB::table('activity_log')->where('id', $id)->update([
            'chain_seq' => 1, 'prev_hash' => AuditChain::GENESIS, 'hash' => AuditChain::hash(AuditChain::GENESIS, 1, $row),
        ]);
    });
    expect(implode("\n", app(ChainVerifier::class)->verify()->problems))->toContain("chain {$this->org->id}: first entry");
    $this->artisan('audit:verify')->assertExitCode(1);
});

it('prints the chain heads as a JSON object, also for chain numbers 0, 1, 2', function (): void {
    $this->artisan('audit:verify', ['--heads' => true])->assertSuccessful()->expectsOutputToContain('"heads":{}');

    $entry = app(AuditLogger::class)->record('x.platform')->refresh();
    $this->artisan('audit:verify', ['--heads' => true])->assertSuccessful()
        ->expectsOutputToContain('"heads":{"0":{"seq":1,"hash":"'.$entry->hash.'"}}');
});
