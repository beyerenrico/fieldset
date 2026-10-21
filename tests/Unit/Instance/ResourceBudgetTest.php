<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\ResourceBudget;
use App\Domain\Instance\InstanceException;
use Tests\Support\Instance\ComposeFixtures;

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
    // One default worker on the E16 floor: two did not fit its queue slot (J10, ADR 0083).
    expect($small->horizonProcesses())->toBe(1)
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

    expect(array_slice(array_keys($settings), 0, 5))->toBe(['shared_buffers', 'effective_cache_size', 'maintenance_work_mem', 'work_mem', 'max_connections'])
        ->and($mib($settings['shared_buffers']))->toBe(intdiv($postgres, 4))
        ->and($mib($settings['effective_cache_size']))->toBeLessThan($postgres)
        ->and($mib($settings['maintenance_work_mem']) + $mib($settings['shared_buffers']))->toBeLessThan($postgres)
        ->and((int) $settings['max_connections'])->toBeGreaterThanOrEqual(30)
        ->and($mib($settings['work_mem']))->toBeGreaterThanOrEqual(4)
        ->and($budget->postgresShmSize())->toBe(intdiv($postgres, 2).'m')
        ->and((int) $budget->valkeyMaxMemory())->toBeLessThan($budget->limitMib('redis'))
        ->and($budget->valkeyMaxMemory())->toEndWith('mb');
})->with(ResourceBudget::SIZES);

it('keeps small Postgres from multiplying its memory per query: no JIT, no parallel workers (M11 gate, ADR 0083)', function (): void {
    $small = ResourceBudget::forSize('small');
    $command = ComposeFixtures::render(['size' => 'small'])->service('postgres')['command'];

    expect($small->postgresSettings())->toMatchArray(['jit' => 'off', 'max_parallel_workers_per_gather' => '0', 'max_connections' => '30'])
        ->and($command)->toContain('jit=off', 'max_parallel_workers_per_gather=0', 'max_connections=30')
        ->and(ResourceBudget::forSize('medium')->postgresSettings())->not->toHaveKey('jit')->not->toHaveKey('max_parallel_workers_per_gather');
});

it('allows more Postgres connections than every client of the stack can hold at once, with room to spare', function (string $size): void {
    $budget = ResourceBudget::forSize($size);
    // FrankenPHP threads, Horizon (master, two supervisors, default and exports workers), scheduler, fieldset one-offs, backup.
    $clients = $budget->frankenphpThreads() + 3 + $budget->horizonProcesses() + $budget->horizonExportsProcesses() + 4 + 2 + 2;

    expect($budget->postgresClients())->toBe($clients)
        ->and((int) $budget->postgresSettings()['max_connections'])->toBeGreaterThanOrEqual($clients + 10);
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
