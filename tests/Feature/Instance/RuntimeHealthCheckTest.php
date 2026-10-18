<?php

declare(strict_types=1);

use App\Domain\Platform\Health\Checks\RuntimeCheck;
use App\Domain\Platform\Health\HealthStatus;
use App\Domain\Platform\Health\SystemProbes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Tests\Support\Instance\RuntimeFixture;

require_once __DIR__.'/Support.php';
require_once __DIR__.'/../Platform/Support.php';

/* Plan U10 / KTD11: the health warning for the runtime file on /platform/health (ADR 0074). */

afterEach(function (): void {
    RuntimeFixture::cleanup();
});

it('reports a checkout install as ok without details', function (): void {
    config(['instance.runtime_path' => null]);

    $result = app(RuntimeCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->summary)->toBe(__('platform.health.runtime.not_configured'))
        ->and($result->details)->toBe([]);
});

it('shows the public URL, the tunnel with its expiry and the gate', function (): void {
    RuntimeFixture::install([...RuntimeFixture::tunnel(), ...RuntimeFixture::raisedGate()]);

    $details = app(RuntimeCheck::class)->run()->details;

    expect($details[__('platform.health.runtime.url')])->toBe('https://'.RuntimeFixture::TUNNEL)
        ->and($details[__('platform.health.runtime.tunnel')])->toBe(RuntimeFixture::TUNNEL)
        ->and($details[__('platform.health.runtime.tunnel_expires')])->toBeInstanceOf(CarbonImmutable::class)
        ->and($details[__('platform.health.runtime.gate')])->toBe(__('platform.health.runtime.gate_raised', ['operation' => 'upgrade']));
});

it('marks an expired tunnel', function (): void {
    RuntimeFixture::install(RuntimeFixture::tunnel('-5 minutes'));

    $details = app(RuntimeCheck::class)->run()->details;

    expect($details[__('platform.health.runtime.tunnel')])->toBe(__('platform.health.runtime.tunnel_expired', ['host' => RuntimeFixture::TUNNEL]))
        ->and($details[__('platform.health.runtime.url')])->toBe('http://localhost:8080')
        ->and($details[__('platform.health.runtime.gate')])->toBe(__('platform.health.runtime.gate_lowered'));
});

it('fails when nothing good was read, naming the problem', function (): void {
    config(['app.url' => 'http://localhost:8080']);
    RuntimeFixture::write(RuntimeFixture::directory(), '{}');

    $result = app(RuntimeCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Fail)
        ->and($result->summary)->toBe(__('platform.health.runtime.fallback'))
        ->and($result->details[__('platform.health.runtime.problem')])->toBe('schema_version is missing or malformed')
        ->and($result->details[__('platform.health.runtime.gate')])->toBe(__('platform.health.runtime.gate_raised', ['operation' => '—']));
});

it('appears on the health page in both languages', function (string $locale): void {
    Http::fake(['*' => Http::response(['status' => 'up'])]);
    $this->mock(SystemProbes::class, function ($mock): void {
        $mock->shouldReceive('tcpReachable')->andReturn(true);
        $mock->shouldReceive('diskSpace')->andReturn(['free' => 50.0 * 1024 ** 3, 'total' => 100.0 * 1024 ** 3]);
    });
    $this->mock(MasterSupervisorRepository::class, fn ($mock) => $mock->shouldReceive('all')->andReturn([]));
    $this->mock(JobRepository::class, function ($mock): void {
        $mock->shouldReceive('countRecentlyFailed')->andReturn(0);
        $mock->shouldReceive('countPending')->andReturn(0);
    });
    $this->mock(WorkloadRepository::class, fn ($mock) => $mock->shouldReceive('get')->andReturn([]));
    RuntimeFixture::install(RuntimeFixture::domain('forms.example'));
    $operator = platformOperator();
    $operator->forceFill(['locale' => $locale])->save();
    actingAsPlatformOperator($operator->fresh());

    runtimeRequest('https://forms.example/platform/health')->assertOk()
        ->assertSee('data-health-check="runtime"', false)
        ->assertSee(trans('platform.health.checks.runtime', [], $locale))
        ->assertSee('https://forms.example');
})->with(['de', 'en']);
