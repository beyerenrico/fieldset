<?php

declare(strict_types=1);

use App\Domain\Audit\AuditLogger;
use App\Domain\Audit\Chain\ChainEpoch;
use App\Domain\Audit\Chain\ChainHeads;
use App\Domain\Audit\Chain\ChainVerifier;
use App\Domain\Audit\Models\Activity;
use App\Domain\Audit\Retention\PruneAuditLog;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Chain epochs (plan KTD26, R68, ADR 0084): `audit:verify --heads` reports the epoch with the heads; restore, rollback
 * and reset start the next one (seeded from the host-side ledger) and record the heads they replaced. ChainHeads reads
 * the same heads as the full verification, cheaply, for backups.
 */

beforeEach(function (): void {
    $this->org = makeOrganization();
    app(TenantContext::class)->clear();
    clearAuditLog();
});

it('reports epoch 1 with the heads while no epoch was started', function (): void {
    $entry = app(AuditLogger::class)->record('x.platform')->refresh();

    Artisan::call('audit:verify', ['--heads' => true]);
    $line = collect(explode("\n", Artisan::output()))->first(fn (string $line): bool => str_starts_with(trim($line), '{'));
    $document = json_decode((string) $line, true, 512, JSON_THROW_ON_ERROR);

    expect(ChainEpoch::current())->toBe(1)
        ->and($document['epoch'])->toBe(1)
        ->and($document['heads'])->toBe(['0' => ['seq' => 1, 'hash' => $entry->hash]]);
});

it('starts the next epoch above the current one and above the ledger seed, with the replaced heads', function (): void {
    app(AuditLogger::class)->record('x.platform');
    $heads = ChainHeads::read();

    $second = ChainEpoch::begin('restored', 0, $heads);
    $seeded = ChainEpoch::begin('reset', 7);
    $stored = DB::table('audit_chain_epochs')->orderBy('epoch')->get();

    expect($second)->toBe(2)->and($seeded)->toBe(8)->and(ChainEpoch::current())->toBe(8)
        ->and($stored->pluck('reason')->all())->toBe(['restored', 'reset'])
        ->and(json_decode((string) $stored[0]->previous_heads, true))->toBe(['0' => $heads[0]]);
    $this->artisan('audit:verify', ['--heads' => true])->assertSuccessful()->expectsOutputToContain('"epoch":8,"heads"');
});

it('refuses an unknown reason and keeps the epochs append-only', function (): void {
    ChainEpoch::begin('rolled_back');

    expect(fn () => ChainEpoch::begin('tampered'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('audit_chain_epochs')->update(['reason' => 'reset'])))->toThrow(QueryException::class, 'append-only')
        ->and(fn () => DB::transaction(fn () => DB::table('audit_chain_epochs')->delete()))->toThrow(QueryException::class, 'append-only')
        ->and(ChainEpoch::current())->toBe(2);
});

it('reads the same heads as the full verification, anchors of emptied chains included', function (): void {
    $other = makeOrganization();
    app(TenantContext::class)->clear();
    clearAuditLog(); // the new organization's own entries would keep its chain from being emptied
    $this->travel(-500)->days();
    app(TenantContext::class)->run($other, fn () => app(AuditLogger::class)->record('x.old'));
    $this->travelBack();
    app(PruneAuditLog::class)->handle(365); // empties the other organization's chain: its head is the anchor
    foreach (range(1, 3) as $i) {
        app(TenantContext::class)->run($this->org, fn () => app(AuditLogger::class)->record("x.org{$i}"));
    }
    app(AuditLogger::class)->record('x.platform');

    $report = app(ChainVerifier::class)->verify();

    expect($report->intact())->toBeTrue()
        ->and(ChainHeads::read())->toBe($report->heads)
        ->and(array_keys(ChainHeads::read()))->toBe([0, $this->org->id, $other->id])
        ->and(ChainHeads::read()[$this->org->id]['seq'])->toBe(3)
        ->and(Activity::query()->where('organization_id', $other->id)->count())->toBe(0);
});

it('has no heads without sealed entries', function (): void {
    expect(ChainHeads::read())->toBe([]);
});
