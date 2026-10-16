<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Tests\Support\Jobs\InspectGroupJob;
use Tests\Support\Jobs\InspectTenantJob;

/** Simulates a fresh worker process: no tenant, no Context, then processes one job. */
function runWorkerOnce(): void
{
    app(TenantContext::class)->clear();
    Context::flush();
    app()->forgetScopedInstances();

    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);
}

beforeEach(function (): void {
    config(['queue.default' => 'database']);
    InspectGroupJob::$seen = [];
    InspectTenantJob::$seen = [];
    $this->tenant = app(TenantContext::class);
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();
});

it('restores the tenant from Context before unserializing job models with relations', function (): void {
    $groupA = $this->tenant->run($this->orgA, function () {
        $group = Group::query()->create(['name' => 'Crew A']);
        $group->users()->attach(User::factory()->create(), ['organization_id' => $this->orgA->id]);

        return $group->load('users');
    });
    $this->tenant->run($this->orgB, fn () => Group::query()->create(['name' => 'Crew B']));

    // PendingDispatch dispatches on destruct: use a block closure so it happens inside run().
    $this->tenant->run($this->orgA, function () use ($groupA): void {
        InspectGroupJob::dispatch($groupA);
    });
    runWorkerOnce();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(InspectGroupJob::$seen)->toHaveCount(1)
        ->and(InspectGroupJob::$seen[0])->toMatchArray([
            'group_id' => $groupA->id,
            'users' => 1,
            'tenant' => $this->orgA->id,
            'team' => $this->orgA->id,
            'visible_groups' => [$groupA->id],
        ]);
});

it('fails a job that carries tenant models but no tenant context', function (): void {
    $group = $this->tenant->run($this->orgA, fn () => Group::query()->create(['name' => 'Crew A']));

    // Dispatch outside any tenant: the payload carries no organization in Context.
    $this->tenant->withoutTenant(function () use ($group): void {
        InspectGroupJob::dispatch($group);
    });
    runWorkerOnce();

    expect(InspectGroupJob::$seen)->toBe([])
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('No organization is set');
});

it('does not leak the tenant or spatie team between jobs in one worker', function (): void {
    $this->tenant->run($this->orgA, function (): void {
        InspectTenantJob::dispatch();
    });
    InspectTenantJob::dispatch();

    runWorkerOnce();
    // Second job: same process, scoped instances are reset by the worker between jobs.
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1]);

    expect(InspectTenantJob::$seen)->toBe([
        ['tenant' => $this->orgA->id, 'team' => $this->orgA->id],
        ['tenant' => null, 'team' => null],
    ]);
});
