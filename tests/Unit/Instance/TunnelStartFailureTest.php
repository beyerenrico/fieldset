<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ProcessOutcome;
use Carbon\CarbonImmutable;
use Tests\Support\Instance\DemoFixtures;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\TunnelFixture;

/*
 * M11 gate (A2, A3; ADR 0078, ADR 0082): `fieldset tunnel start` changes nothing before its checks passed — the exposure is
 * checked again under the lock, the target and cloudflared's image are settled — and only then rotates demo passwords;
 * every failure after the rotation still lists them. Failure results follow `config set`'s agent contract: exit 5 with
 * `retry_after` or `fix_first`, exit 8 `revert_failed` with recovery steps, `internal_error` not retryable.
 */

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-18T12:00:00Z');
    $this->fixture = new TunnelFixture;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    TemporaryInstance::cleanup();
});

/** Changes what `app:demo-passwords --check` answers from its second call on: the check under the lock. */
function tunnelSecondCheck(TunnelFixture $fixture, Closure $change): void
{
    $checks = 0;
    $fixture->instance->docker->onCall['composeRun'] = function (array $args) use ($fixture, $change, &$checks): void {
        if (in_array('--check', $args['command'], true) && ++$checks === 2) {
            $change($fixture);
        }
    };
}

it('rotates no password when the image pull or the subnet search fails, and says truthfully that nothing changed (A2)', function (string $failure): void {
    $this->fixture->demoDefaults = DemoFixtures::EMAILS;
    $docker = $this->fixture->instance->docker;
    $docker->images = [];
    if ($failure === 'image_unavailable') {
        $docker->failOn('pullImage', new DockerCommandFailed('pull', new ProcessOutcome(1, '', 'toomanyrequests')));
    } else {
        $docker->subnets = [...$docker->subnets, '10.215.0.0/16'];
    }
    $mark = $this->fixture->config->mark();

    [$code, $json] = $this->fixture->tunnel('start');

    expect($code)->toBe(3, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe($failure)
        ->and($json['changed'])->toBeFalse()
        ->and($json['secrets'])->toBe([])
        ->and($this->fixture->accountCalls)->not->toContain('app:demo-passwords --rotate --actor-uid='.posix_getuid().' --json')
        ->and($this->fixture->config->methodsSince($mark))->not->toContain('composeUp', 'composeStop')
        ->and($this->fixture->config->openJournal())->toBeNull();
})->with(['image_unavailable', 'edge_subnet_unavailable']);

it('lists the rotated passwords and says changed when the tunnel fails after the rotation (A2)', function (bool $revertFails): void {
    $this->fixture->demoDefaults = DemoFixtures::EMAILS;
    $this->fixture->hostname = null;
    if ($revertFails) {
        $this->fixture->failComposeUps(2);
    }

    [$code, $json] = $this->fixture->tunnel('start');

    expect($code)->toBe($revertFails ? 8 : 5, json_encode($json))
        ->and($json['changed'])->toBeTrue()
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('demo_passwords_rotated')
        ->and(collect($json['secrets'])->pluck('name')->all())->toContain('demo_password:'.DemoFixtures::EMAILS[0])
        ->and($json['data']['demo'])->toMatchArray(['rotated' => true, 'passwords' => 'random', 'delivered' => 'secrets']);
})->with(['the revert works' => false, 'the revert fails too' => true]);

it('checks the accounts again under the lock and decides refusal, confirmation and rotation from that (A2)', function (string $case): void {
    tunnelSecondCheck($this->fixture, function (TunnelFixture $fixture) use ($case): void {
        match ($case) {
            'another account on password' => $fixture->otherDefaults = ['real@example.com'],
            'an organization appeared' => $fixture->nonDemoOrganizations = 1,
            default => $fixture->demoDefaults = DemoFixtures::EMAILS,
        };
    });
    $mark = $this->fixture->config->mark();

    [$code, $json] = $this->fixture->tunnel('start');

    $checks = array_values(array_filter($this->fixture->accountCalls, static fn (string $call): bool => str_contains($call, '--check')));
    $expected = match ($case) {
        'another account on password' => [3, 'default_password_in_use'],
        'an organization appeared' => [6, 'confirmation_required'],
        default => [0, null],
    };
    expect($checks)->toHaveCount(2)
        ->and($code)->toBe($expected[0], json_encode($json))
        ->and($json['errors'][0]['code'] ?? null)->toBe($expected[1]);
    if ($expected[0] === 0) {
        expect(collect($json['secrets'])->pluck('name')->all())->toContain('demo_password:'.DemoFixtures::EMAILS[0]);
    } else {
        expect($this->fixture->config->methodsSince($mark))->not->toContain('composeUp')
            ->and($this->fixture->config->openJournal())->toBeNull();
    }
})->with(['another account on password', 'an organization appeared', 'demo accounts on password appeared']);

it('reports a reverted start like config set: fix_first, the command to repeat, the next commands (A3)', function (): void {
    $this->fixture->hostname = null;

    [$code, $json] = $this->fixture->tunnel('start', ['--tunnel-admin' => true]);

    expect($code)->toBe(5, json_encode($json))
        ->and($json['status'])->toBe('rolled_back')
        ->and($json['errors'][0])->toMatchArray(['code' => 'tunnel_unavailable', 'retryable' => false])
        ->and($json['data'])->toMatchArray([
            'url' => 'http://localhost:8080', 'mode' => 'local', 'reverted' => true,
            'command' => 'fieldset tunnel start --tunnel-admin', 'options' => ['--tunnel-admin' => true],
            'next_commands' => [$this->fixture->config->instance->directory->root.'/fieldset status'],
        ])
        ->and($json['data']['fix_first'])->toContain('fieldset logs tunnel')
        ->and($json['data'])->not->toHaveKey('retry_after')
        ->and($json['data']['failure'])->toHaveKey('answer');
});

it('reports an unexpected failure as internal_error that is not retried (A3)', function (): void {
    $this->fixture->instance->docker->failOn('runContainer', new RuntimeException('The engine closed the connection.'));

    [$code, $json] = $this->fixture->tunnel('start');

    expect($code)->toBe(5, json_encode($json))
        ->and($json['errors'][0])->toMatchArray(['code' => 'internal_error', 'retryable' => false])
        ->and($json['data']['fix_first'])->toBeString()
        ->and($json['data']['failure'])->toBe(['exception' => RuntimeException::class]);
});

it('reports a failed revert as exit 8 with recovery steps that name `fieldset tunnel stop` (A3)', function (): void {
    $this->fixture->hostname = null;
    $this->fixture->failComposeUps(2);

    [$code, $json] = $this->fixture->tunnel('start');

    expect($code)->toBe(8, json_encode($json))
        ->and($json['status'])->toBe('rollback_failed')
        ->and($json['errors'][0])->toMatchArray(['code' => 'revert_failed', 'retryable' => false])
        ->and($json['data'])->toMatchArray([
            'reverted' => false, 'revert_steps' => ['files'], 'stopped' => true,
            'command' => 'fieldset tunnel stop', 'url' => 'http://localhost:8080',
        ])
        ->and($json['data']['stopped_services'])->toContain('app', 'queue', 'scheduler')
        ->and($json['data']['stopped_services'])->not->toContain('postgres', 'redis')
        ->and($json['data']['failure']['code'])->toBe('tunnel_unavailable')
        ->and($json['data']['revert_failure']['code'])->toBe('services_unhealthy')
        ->and($json['data']['recovery'])->toHaveCount(3)
        ->and($json['data']['recovery'][2])->toContain('`fieldset tunnel stop`')
        ->and($this->fixture->config->openJournal()?->operation())->toBe('tunnel');
});

it('keeps the rotation in the state history whatever became of the tunnel (review)', function (bool $fails): void {
    $this->fixture->demoDefaults = DemoFixtures::EMAILS;
    $this->fixture->hostname = $fails ? null : $this->fixture->hostname;

    [$code] = $this->fixture->tunnel('start');

    expect($code)->toBe($fails ? 5 : 0)
        ->and(collect($this->fixture->config->state()->history())->pluck('event')->all())->toContain('demo_passwords_rotated');
})->with(['the tunnel starts' => false, 'the start is reverted' => true]);

it('leaves the stack alone when the demo rotation fails before it changed (review)', function (): void {
    $this->fixture->demoDefaults = DemoFixtures::EMAILS;
    $previous = $this->fixture->instance->docker->composeRunResponder;
    $this->fixture->instance->docker->respondToComposeRun(fn (array $command, ?string $input = null): ProcessOutcome => in_array('--rotate', $command, true)
        ? new ProcessOutcome(1, '', 'SQLSTATE[08006] connection refused')
        : $previous($command, $input));
    $mark = $this->fixture->config->mark();

    [$code, $json] = $this->fixture->tunnel('start');

    expect($code)->toBe(5, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('one_off_failed')
        ->and($json['data']['reverted'])->toBeTrue()
        ->and($this->fixture->config->methodsSince($mark))->not->toContain('composeUp', 'composeStop')
        ->and($this->fixture->config->openJournal())->toBeNull();
});
