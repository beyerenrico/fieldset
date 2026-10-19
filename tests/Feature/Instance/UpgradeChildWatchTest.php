<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ProcessOutcome;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;

/*
 * How the supervisor watches the upgrade child (plan U18, KTD19 step 6, ADR 0086, review U18 A6): a `docker inspect`
 * that fails for a moment is retried before the child counts as gone, and a liveness probe that fails is "unknown",
 * which neither keeps the child alive nor ages it towards the idle limit.
 */

beforeEach(function (): void {
    $this->upgrade = new UpgradeFixture;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

it('retries a docker inspect that fails for a moment instead of removing the running child', function (): void {
    $this->upgrade->childRunningPolls = 3;
    $failures = 2;
    $docker = $this->upgrade->instance->docker;
    $poll = $docker->onCall['inspectContainer'];
    $docker->onCall['inspectContainer'] = static function (array $args) use (&$failures, $poll): void {
        if ($args['id'] === UpgradeFixture::CHILD_ID && $failures-- > 0) {
            throw new DockerCommandFailed('inspect', new ProcessOutcome(1, '', 'Error response from daemon: i/o timeout'));
        }
        $poll($args);
    };

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($failures)->toBeLessThan(0)
        ->and(array_filter($docker->called('removeContainer'), static fn (array $call): bool => $call['id'] === UpgradeFixture::CHILD_ID))->toHaveCount(1); // once, after it exited
});

it('treats a failing liveness probe as unknown: the child is not judged hung because the probe failed', function (): void {
    $this->upgrade->childRunningPolls = 80; // 400 s of a running child
    $this->upgrade->probeFails = true;

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(array_map(static fn (array $call): string => $call['command'][3], $this->upgrade->oneOffs('instance:upgrade-db')))->toContain('activity')->not->toContain('terminate');
});
