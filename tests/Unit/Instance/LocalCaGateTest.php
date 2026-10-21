<?php

declare(strict_types=1);

use App\Domain\Instance\Config\Appliers\ConfigValues;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\LocalCa\LocalCaStore;
use App\Domain\Instance\Output\InvalidInput;
use Tests\Support\Instance\LocalCaFixture;
use Tests\Support\Instance\PhoneFixtures;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\TunnelFixture;

/*
 * M11 gate (ADR 0082 amendment): a CA deletion that failed on `phone-https off` is recorded and retried by the next
 * `phone-https off` (or before `local-ca` creates a new CA); `config set lan-address` checks 80/443 on the new address
 * even while the edge serves the old one; the R61 hint names the command that turns phone HTTPS off.
 */

afterEach(fn () => TemporaryInstance::cleanup());

/**
 * Scripted local-CA edge (as LocalCaCommandTest's localCaEdge) whose CA deletion answers `$rm`, logging `rm` calls.
 *
 * @param  Closure(): ProcessOutcome  $rm
 */
function gateEdge(TunnelFixture $fixture, Closure $rm): object
{
    $log = new class
    {
        /** @var list<string> */
        public array $calls = [];
    };
    $previous = $fixture->config->instance->docker->runResponder;
    $fixture->config->instance->docker->respondToRun(function (ContainerSpec $spec) use ($previous, $rm, $log): ?ProcessOutcome {
        $last = $spec->command[array_key_last($spec->command)] ?? '';

        return match (true) {
            $spec->entrypoint === 'cat' && $last === LocalCaStore::ROOT_FILE => new ProcessOutcome(0, LocalCaFixture::root()),
            $spec->entrypoint === 'rm' => (function () use ($rm, $log): ProcessOutcome {
                $log->calls[] = 'rm';

                return $rm();
            })(),
            $spec->entrypoint === 'curl' && in_array('--cacert', $spec->command, true) => new ProcessOutcome(0, '200'),
            $spec->entrypoint === 'curl' && str_contains(implode(' ', $spec->command), ':80:') => new ProcessOutcome(0, '308 https://'.PhoneFixtures::LAN."/up\n200 \n"),
            default => $previous instanceof Closure ? $previous($spec) : null,
        };
    });

    return $log;
}

function gateFixture(array $lanAddresses = [PhoneFixtures::LAN]): TunnelFixture
{
    $fixture = new TunnelFixture;
    $fixture->config->instance->facts(['lanAddresses' => $lanAddresses]);

    return $fixture;
}

it('records a CA deletion that failed on phone-https off and retries it with the next off', function (): void {
    $fixture = gateFixture();
    $fails = true;
    $log = gateEdge($fixture, function () use (&$fails): ProcessOutcome {
        return $fails ? new ProcessOutcome(1, '', 'rm: cannot remove: Read-only file system') : new ProcessOutcome(0);
    });
    $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);

    [$off, $offJson] = $fixture->config->set('phone-https', 'off', ['--yes' => true]);
    $pending = $fixture->config->state()->setting('local_ca_delete');
    [, $status] = $fixture->config->instance->run('fieldset:status');
    $fails = false;
    [$retry, $retryJson] = $fixture->config->set('phone-https', 'off', ['--yes' => true]);
    $mark = $fixture->config->mark();
    [$again, $againJson] = $fixture->config->set('phone-https', 'off', ['--yes' => true]);

    $failed = collect($offJson['warnings'])->firstWhere('code', 'local_ca_delete_failed');
    expect($off)->toBe(0, json_encode($offJson))
        ->and($offJson['data']['phone_https'])->toBe(['mode' => null, 'ca_deleted' => false])
        ->and($failed['hint'] ?? '')->toContain('fieldset config set phone-https off')
        ->and($pending)->toBe('pending')
        ->and(collect($status['warnings'])->pluck('code')->all())->toContain('local_ca_delete_pending')
        ->and($retry)->toBe(0, json_encode($retryJson))
        ->and($retryJson['changed'])->toBeTrue()
        ->and($retryJson['data']['phone_https'])->toBe(['mode' => null, 'ca_deleted' => true])
        ->and(collect($retryJson['warnings'])->pluck('code')->all())->toContain('local_ca_deleted')
        ->and($fixture->config->state()->setting('local_ca_delete'))->toBeNull()
        ->and($log->calls)->toBe(['rm', 'rm'])
        ->and($again)->toBe(0)->and($againJson['changed'])->toBeFalse()
        ->and($fixture->config->methodsSince($mark))->toBe([]);
});

it('deletes a CA a failed deletion left behind before local-ca starts the edge with a new one', function (): void {
    $fixture = gateFixture();
    $fails = true;
    $log = gateEdge($fixture, function () use (&$fails): ProcessOutcome {
        return $fails ? new ProcessOutcome(1, '', 'rm: cannot remove') : new ProcessOutcome(0);
    });
    $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);
    $fixture->config->set('phone-https', 'off', ['--yes' => true]);
    $fails = false;
    $mark = $fixture->config->mark();

    [$code, $json] = $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);

    $calls = array_slice($fixture->config->instance->docker->calls, $mark);
    $rm = array_key_first(array_filter($calls, static fn (array $call): bool => $call['method'] === 'runContainer' && $call['args']['spec']->entrypoint === 'rm'));
    $up = array_key_first(array_filter($calls, static fn (array $call): bool => $call['method'] === 'composeUp'));
    expect($code)->toBe(0, json_encode($json))
        ->and($log->calls)->toBe(['rm', 'rm'])
        ->and($rm)->not->toBeNull()
        ->and($rm)->toBeLessThan($up)
        ->and($fixture->config->state()->setting('local_ca_delete'))->toBeNull();
});

it('checks 80 and 443 on the new LAN address although the edge serves the old one', function (): void {
    $fixture = gateFixture([PhoneFixtures::LAN, '192.168.1.30']);
    gateEdge($fixture, fn (): ProcessOutcome => new ProcessOutcome(0));
    $fixture->config->set('phone-https', 'local-ca', ['--yes' => true]);
    $state = $fixture->config->state();
    $fixture->config->instance->docker->withRunningContainer('ed9e', '2026-10-19T10:00:00Z', [Labels::COMPOSE_PROJECT => $state->project(), Labels::COMPOSE_SERVICE => 'edge']);
    $fixture->config->takenLoopback = [80]; // another program holds port 80 on the new address

    [$code, $json] = $fixture->config->set('lan-address', '192.168.1.30', ['--yes' => true]);

    $ports = collect($json['data']['checks'] ?? [])->firstWhere('key', 'ports');
    expect($code)->toBe(3, json_encode($json))
        ->and($ports['details']['lan'] ?? null)->toMatchArray(['address' => '192.168.1.30', 'taken' => [80], 'served' => false])
        ->and($fixture->config->state()->setting('lan_address'))->toBe(PhoneFixtures::LAN);
});

it('names the command that turns phone HTTPS off when it blocks another mode (R61)', function (string $phone, string $command): void {
    $fixture = gateFixture();
    $state = $fixture->config->state()->withSetting('phone_https', $phone);

    try {
        ConfigValues::assertNoPhoneHttps($state, 'domain mode');
        $hint = null;
    } catch (InvalidInput $e) {
        $hint = $e->hint;
    }

    expect($hint)->toContain($command);
})->with([
    'local CA' => ['local-ca', 'fieldset config set phone-https off --yes'],
    'tunnel' => ['tunnel', 'fieldset tunnel stop'],
]);
