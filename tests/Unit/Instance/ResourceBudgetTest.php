<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\ResourceBudget;
use App\Domain\Instance\InstanceException;

/*
 * Plan U3 / E16: memory limits per service, whose sum fits the host size minus OS headroom.
 */

it('fits the default size into 2 GB minus OS headroom, the edge or tunnel and the backup service included (E16)', function (): void {
    $budget = ResourceBudget::forSize(ResourceBudget::DEFAULT_SIZE);

    expect(ResourceBudget::DEFAULT_SIZE)->toBe('small')
        ->and($budget->hostMib())->toBe(2048)
        ->and($budget->headroomMib())->toBeGreaterThanOrEqual(256)
        ->and($budget->availableMib())->toBe($budget->hostMib() - $budget->headroomMib())
        ->and($budget->worstCaseMib())->toBeLessThanOrEqual($budget->availableMib())
        ->and(array_keys($budget->limits()))->toEqualCanonicalizing(['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit', 'edge', 'tunnel', 'backup']);
});

it('never lets the worst-case sum of limits exceed the available memory of any size', function (string $size): void {
    $budget = ResourceBudget::forSize($size);
    $limits = $budget->limits();

    expect($budget->worstCaseMib())->toBe(array_sum($limits) - min($limits['edge'], $limits['tunnel']))
        ->and($budget->worstCaseMib())->toBeLessThanOrEqual($budget->availableMib())
        ->and(min($limits))->toBeGreaterThan(0);
})->with(ResourceBudget::SIZES);

it('scales Horizon processes, Gotenberg concurrency and FrankenPHP threads with the instance size', function (): void {
    [$small, $medium, $large] = array_map(ResourceBudget::forSize(...), ResourceBudget::SIZES);

    foreach ([[$small, $medium], [$medium, $large]] as [$lower, $higher]) {
        expect($higher->horizonProcesses())->toBeGreaterThan($lower->horizonProcesses())
            ->and($higher->gotenbergConcurrency())->toBeGreaterThan($lower->gotenbergConcurrency())
            ->and($higher->frankenphpThreads())->toBeGreaterThan($lower->frankenphpThreads())
            ->and($higher->mailpitMaxMessages())->toBeGreaterThanOrEqual($lower->mailpitMaxMessages())
            ->and($higher->hostMib())->toBeGreaterThan($lower->hostMib());
        foreach ($lower->limits() as $service => $mib) {
            expect($higher->limitMib($service))->toBeGreaterThanOrEqual($mib, $service);
        }
    }
    expect($small->horizonProcesses())->toBe(2)
        ->and($small->gotenbergConcurrency())->toBe(1)
        ->and($small->horizonExportsProcesses())->toBe(1);
});

it('bounds the Gotenberg queue and keeps Chromium within its documented maximum of 6', function (string $size): void {
    $budget = ResourceBudget::forSize($size);

    expect($budget->gotenbergConcurrency())->toBeLessThanOrEqual(6)
        ->and($budget->gotenbergQueueSize())->toBe(8 * $budget->gotenbergConcurrency());
})->with(ResourceBudget::SIZES);

it('derives Postgres and Valkey memory settings that fit their container limits', function (string $size): void {
    $budget = ResourceBudget::forSize($size);
    $postgres = $budget->limitMib('postgres');
    $settings = $budget->postgresSettings();
    $mib = static fn (string $value): int => (int) rtrim($value, 'MB');

    expect(array_keys($settings))->toBe(['shared_buffers', 'effective_cache_size', 'maintenance_work_mem', 'work_mem', 'max_connections'])
        ->and($mib($settings['shared_buffers']))->toBe(intdiv($postgres, 4))
        ->and($mib($settings['effective_cache_size']))->toBeLessThan($postgres)
        ->and($mib($settings['maintenance_work_mem']) + $mib($settings['shared_buffers']))->toBeLessThan($postgres)
        ->and((int) $settings['max_connections'])->toBeGreaterThanOrEqual(30)
        ->and($budget->postgresShmSize())->toBe(intdiv($postgres, 2).'m')
        ->and((int) $budget->valkeyMaxMemory())->toBeLessThan($budget->limitMib('redis'))
        ->and($budget->valkeyMaxMemory())->toEndWith('mb');
})->with(ResourceBudget::SIZES);

it('formats limits for Compose and knows every service it limits', function (): void {
    $budget = ResourceBudget::forSize('small');

    expect($budget->size())->toBe('small')
        ->and($budget->memoryLimit('app'))->toBe($budget->limitMib('app').'m')
        ->and($budget->has('app'))->toBeTrue()
        ->and($budget->has('keycloak'))->toBeFalse()
        ->and(fn () => $budget->limitMib('keycloak'))->toThrow(InstanceException::class, 'memory budget')
        ->and(fn () => ResourceBudget::forSize('huge'))->toThrow(InstanceException::class, 'huge');
});
