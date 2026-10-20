<?php

declare(strict_types=1);

use App\Domain\Platform\Instance\Upgrade\QueueDrainer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

/*
 * `instance:queue-drain` (KTD19 step 4, plan U18, ADR 0086) against the dev Valkey, on a queue of its own
 * (`fsa-test-u18-<random>`, which no Horizon of the dev stack works): the sizes by state, and `--drop` exporting every
 * job left over (pending, reserved, delayed) as its raw payload before deleting it.
 */

beforeEach(function (): void {
    $this->queue = 'fsa-test-u18-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    Queue::connection('redis')->clear($this->queue);
});

/** @return array{0: int, 1: array<string, mixed>} */
function queueDrain(array $options = []): array
{
    $code = Artisan::call('instance:queue-drain', [...$options, '--json' => true]);
    $lines = array_values(array_filter(explode("\n", trim(Artisan::output()))));

    return [$code, json_decode((string) end($lines), true)];
}

it('reports the sizes of the queues by state', function (): void {
    $queue = Queue::connection('redis');
    $queue->push('fsa-test-job', [], $this->queue);
    $queue->push('fsa-test-job', [], $this->queue);
    $queue->later(3600, 'fsa-test-delayed', [], $this->queue);

    [$code, $json] = queueDrain(['--queue' => [$this->queue]]);

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data'])->toMatchArray(['pending' => 2, 'reserved' => 0, 'delayed' => 1])
        ->and($json['data']['queues'][$this->queue])->toBe(['pending' => 2, 'reserved' => 0, 'delayed' => 1])
        ->and($json['changed'])->toBeFalse();
});

it('exports the jobs still pending or reserved with --export without deleting, and deletes them with --drop; delayed jobs stay', function (): void {
    $queue = Queue::connection('redis');
    $queue->push('fsa-test-job', [], $this->queue);
    $queue->push('fsa-test-job', [], $this->queue);
    $queue->later(3600, 'fsa-test-delayed', [], $this->queue);

    [$code, $export] = queueDrain(['--queue' => [$this->queue], '--export' => true]);
    [, $kept] = queueDrain(['--queue' => [$this->queue]]);
    [$dropCode, $drop] = queueDrain(['--queue' => [$this->queue], '--drop' => true]);
    [, $after] = queueDrain(['--queue' => [$this->queue]]);

    expect($code)->toBe(0, (string) json_encode($export))
        ->and($export['data']['payloads'])->toHaveCount(2)->and($export['changed'])->toBeFalse()
        ->and(array_unique(array_column($export['data']['payloads'], 'state')))->toBe(['pending'])
        ->and($export['data']['payloads'][0]['payload'])->toContain('fsa-test-job')
        ->and($kept['data'])->toMatchArray(['pending' => 2, 'delayed' => 1])
        ->and($dropCode)->toBe(0)->and($drop['data']['dropped'])->toBe(2)->and($drop['changed'])->toBeTrue()
        ->and($after['data'])->toMatchArray(['pending' => 0, 'reserved' => 0, 'delayed' => 1]);
});

it('works every Horizon queue by default', function (): void {
    expect(QueueDrainer::horizonQueues())->toBe(['default', 'exports']);
});
