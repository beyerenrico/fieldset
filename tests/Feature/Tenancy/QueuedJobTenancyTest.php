<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Group;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Tests\Support\Jobs\InspectGroupJob;
use Tests\Support\Jobs\InspectTenantJob;
use Tests\Support\Jobs\RestoreUserWithGroupsJob;

/** Simulates a fresh worker process: no tenant, no Context, then processes the queued jobs. */
function runWorker(int $maxJobs = 1): void
{
    app(TenantContext::class)->clear();
    Context::flush();
    app()->forgetScopedInstances();

    // --max-jobs (not --once) so the worker loop runs its per-job scope reset between jobs.
    Artisan::call('queue:work', ['connection' => 'database', '--max-jobs' => $maxJobs, '--stop-when-empty' => true, '--tries' => 1]);
}

beforeEach(function (): void {
    config(['queue.default' => 'database']);
    InspectGroupJob::$seen = [];
    InspectTenantJob::$seen = [];
    RestoreUserWithGroupsJob::$seen = [];
    $this->tenant = app(TenantContext::class);
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();
});

/** A user in two organizations, with groups in both; returned with org A's groups loaded. */
function userWithGroups(Organization $a, Organization $b): User
{
    $user = User::factory()->create();
    $tenant = app(TenantContext::class);
    foreach ([$a, $b] as $org) {
        $tenant->run($org, function () use ($user, $org): void {
            Group::query()->create(['name' => 'Crew '.$org->id])->users()->attach($user, ['organization_id' => $org->id]);
        });
    }

    return $tenant->run($a, fn () => $user->load('groups'));
}

it('hydrates the tenant from Context before job models and relations are unserialized', function (): void {
    $user = userWithGroups($this->orgA, $this->orgB);
    $groupA = $this->tenant->run($this->orgA, fn () => Group::query()->value('id'));

    $this->tenant->run($this->orgA, function () use ($user): void {
        RestoreUserWithGroupsJob::dispatch($user);
    });
    runWorker();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(RestoreUserWithGroupsJob::$seen)->toBe([[$groupA]]);
});

it('fails while unserializing when the payload carries no tenant (scope throws before handle)', function (): void {
    $user = userWithGroups($this->orgA, $this->orgB);

    $this->tenant->withoutTenant(function () use ($user): void {
        RestoreUserWithGroupsJob::dispatch($user);
    });
    runWorker();

    expect(RestoreUserWithGroupsJob::$seen)->toBe([])
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('No organization is set');
});

it('scopes queries inside the job to the job tenant', function (): void {
    $groupA = $this->tenant->run($this->orgA, fn () => Group::query()->create(['name' => 'Crew A']));
    $this->tenant->run($this->orgB, fn () => Group::query()->create(['name' => 'Crew B']));

    $this->tenant->run($this->orgA, function () use ($groupA): void {
        InspectGroupJob::dispatch($groupA);
    });
    runWorker();

    expect(InspectGroupJob::$seen[0])->toMatchArray([
        'tenant' => $this->orgA->id,
        'team' => $this->orgA->id,
        'visible_groups' => [$groupA->id],
    ]);
});

it('does not leak the tenant or spatie team between jobs in one worker loop', function (): void {
    $this->tenant->run($this->orgA, function (): void {
        InspectTenantJob::dispatch();
    });
    InspectTenantJob::dispatch();

    runWorker(maxJobs: 2);

    expect(InspectTenantJob::$seen)->toBe([
        ['tenant' => $this->orgA->id, 'team' => $this->orgA->id],
        ['tenant' => null, 'team' => null],
    ]);
});

it('dispatches a PendingDispatch returned from a tenant callback inside that tenant', function (): void {
    $this->tenant->run($this->orgA, fn () => InspectTenantJob::dispatch());
    runWorker();

    expect(InspectTenantJob::$seen)->toBe([['tenant' => $this->orgA->id, 'team' => $this->orgA->id]]);
});

it('dispatches a PendingChain returned from a tenant callback inside that tenant', function (): void {
    $this->tenant->run($this->orgA, fn () => Bus::chain([new InspectTenantJob]));
    runWorker();

    expect(InspectTenantJob::$seen)->toBe([['tenant' => $this->orgA->id, 'team' => $this->orgA->id]]);
});
