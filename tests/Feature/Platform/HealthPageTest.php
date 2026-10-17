<?php

declare(strict_types=1);

use App\Domain\Platform\Health\HealthStatus;
use App\Domain\Platform\Health\PlatformHealth;
use App\Domain\Platform\Health\SchedulerHeartbeat;
use App\Domain\Platform\Health\SystemProbes;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;

require_once __DIR__.'/Support.php';

/** @param  list<object>  $masters */
function platformFakeHorizon(array $masters, int $failed = 0, int $pending = 0): void
{
    test()->mock(MasterSupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andReturn($masters));
    test()->mock(JobRepository::class, function ($mock) use ($failed, $pending): void {
        $mock->shouldReceive('countRecentlyFailed')->andReturn($failed);
        $mock->shouldReceive('countPending')->andReturn($pending);
    });
    test()->mock(WorkloadRepository::class, fn ($mock) => $mock->shouldReceive('get')->andReturn([
        ['name' => 'default', 'length' => 7, 'wait' => 1, 'processes' => 2, 'split_queues' => null],
    ]));
}

/** @return array<string, HealthStatus> */
function platformHealthStatuses(): array
{
    $statuses = [];
    foreach (app(PlatformHealth::class)->run() as $result) {
        $statuses[$result->key] = $result->status;
    }

    return $statuses;
}

beforeEach(function (): void {
    config(['laravel-pdf.gotenberg.url' => 'http://gotenberg.test:3000', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test']);
    $this->mock(SystemProbes::class, function ($mock): void {
        $mock->shouldReceive('tcpReachable')->andReturn(true);
        $mock->shouldReceive('diskSpace')->andReturn(['free' => 50.0 * 1024 ** 3, 'total' => 100.0 * 1024 ** 3]);
    });
});

it('shows every check on the health page', function (): void {
    platformFakeHorizon([(object) ['status' => 'running']], pending: 3);
    Http::fake(['gotenberg.test:3000/health' => Http::response(['status' => 'up'])]);
    app(SchedulerHeartbeat::class)->record();
    actingAsPlatformOperator(platformOperator());

    $this->get('/platform/health')->assertOk()
        ->assertSee('data-health-check="queue"', false)->assertSee('data-health-status="ok"', false)
        ->assertSee(__('platform.health.checks.gotenberg'))->assertSee(__('platform.health.checks.mail'))
        ->assertSee(__('platform.health.checks.scheduler'))->assertSee(__('platform.health.checks.disk'))
        ->assertSee(__('platform.health.queue.length', ['queue' => 'default']));

    expect(platformHealthStatuses())->toBe([
        'queue' => HealthStatus::Ok, 'gotenberg' => HealthStatus::Ok, 'mail' => HealthStatus::Ok,
        'scheduler' => HealthStatus::Ok, 'disk' => HealthStatus::Ok,
    ]);
    Http::assertSent(fn ($request): bool => $request->url() === 'http://gotenberg.test:3000/health');
});

it('reports failures without breaking the page', function (): void {
    platformFakeHorizon([]);
    Http::fake(['gotenberg.test:3000/health' => Http::response('down', 503)]);
    $this->mock(SystemProbes::class, function ($mock): void {
        $mock->shouldReceive('tcpReachable')->andReturn(false);
        $mock->shouldReceive('diskSpace')->andReturn(['free' => 3.0, 'total' => 100.0]);
    });
    actingAsPlatformOperator(platformOperator());

    $this->get('/platform/health')->assertOk()->assertSee(__('platform.health.queue.inactive'));
    expect(platformHealthStatuses())->toBe([
        'queue' => HealthStatus::Fail, 'gotenberg' => HealthStatus::Fail, 'mail' => HealthStatus::Fail,
        'scheduler' => HealthStatus::Warn, 'disk' => HealthStatus::Fail,
    ]);
});

it('turns a throwing check into a failed result', function (): void {
    $this->mock(MasterSupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andThrow(new RuntimeException('Connection refused [tcp://secret-host:6379]')));
    Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

    $results = collect(app(PlatformHealth::class)->run())->keyBy('key');

    expect($results['queue']->status)->toBe(HealthStatus::Fail)
        ->and($results['queue']->summary)->toContain('RuntimeException')->not->toContain('secret-host')
        ->and($results['gotenberg']->status)->toBe(HealthStatus::Fail);
});

it('warns about paused Horizon, failed jobs, log mail and a stale scheduler', function (): void {
    platformFakeHorizon([(object) ['status' => 'paused']]);
    Http::fake(['*' => Http::response(['status' => 'up'])]);
    config(['mail.default' => 'log']);
    app(SchedulerHeartbeat::class)->record();
    $this->travel(30)->minutes();

    expect(platformHealthStatuses())->toMatchArray(['queue' => HealthStatus::Warn, 'mail' => HealthStatus::Warn, 'scheduler' => HealthStatus::Warn]);

    platformFakeHorizon([(object) ['status' => 'running']], failed: 2);
    $this->travel(2)->hours();
    expect(platformHealthStatuses())->toMatchArray(['queue' => HealthStatus::Warn, 'scheduler' => HealthStatus::Fail]);
});

it('records the scheduler heartbeat from scheduled task events', function (): void {
    expect(app(SchedulerHeartbeat::class)->lastRun())->toBeNull();

    event(new ScheduledTaskStarting(new ScheduledEvent(app(CacheEventMutex::class), 'php artisan documents:sweep')));

    expect(app(SchedulerHeartbeat::class)->lastRun())->not->toBeNull();
});
