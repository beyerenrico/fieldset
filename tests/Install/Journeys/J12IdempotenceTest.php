<?php

declare(strict_types=1);

use App\Domain\Instance\Release\ManifestVerifier;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * J12 (BUILD_PROMPT_V2 §11, plan U5): idempotence and errors with real Docker against the harness release.
 * AE3 a second install with the same flags changes nothing and touches no container; AE4 with 8080 taken the
 * next free port is used, reported in JSON and text, and kept on a re-run; AE5 a domain that does not resolve
 * here fails the preflight with exit 3 before any service starts or another image is pulled; AE9 a secret as a
 * flag value exits 2.
 */

const J12_FLAGS = ['--org', 'Idempotence Org', '--owner-email', 'owner@example.test', '--locale', 'en'];

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fsaJ12'] = new InstanceJourney($harness, $release, $harness->directory.'/j12');
    } catch (Throwable $exception) {
        $GLOBALS['fsaJ12Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fsaJ12'])) {
        foreach (['fsa-test-j12-ports', 'fsa-test-j12-ae5', 'fsa-test-j12-ae9'] as $name) {
            InstanceJourney::cleanup($GLOBALS['fsaJ12']->work.'/'.$name);
        }
    }
    new Process(['docker', 'rm', '-f', 'fsa-test-j12-port-blocker'])->run();
    unset($GLOBALS['fsaJ12'], $GLOBALS['fsaJ12Error']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FIELDSET_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fsaJ12Error'])) {
        throw $GLOBALS['fsaJ12Error'];
    }
    $this->journey = $GLOBALS['fsaJ12'];
});

it('AE4 + AE3: takes the next free port when 8080 is taken, keeps it, and changes nothing on a re-run', function (): void {
    $dir = $this->journey->work.'/fsa-test-j12-ports';
    // 8080 is taken by this blocker, or already by something else (the blocker then fails to start): either way taken.
    new Process(['docker', 'run', '-d', '--rm', '--name', 'fsa-test-j12-port-blocker', '-p', '127.0.0.1:8080:8080', '--entrypoint', 'sleep', $this->journey->release->appReference, '900'], null, $this->journey->release->platformEnvironment())->run();

    $first = $this->journey->install($dir, [...J12_FLAGS, '--json']);
    $json = InstanceJourney::json($first);
    $uuid = (string) InstanceJourney::uuid($dir);
    $startTimes = InstanceJourney::startTimes($uuid);
    // `docker events --since` has whole-second resolution: start in a fresh second, so the first install's last
    // throwaway containers (smoke, --rm) are not counted against the second run.
    $since = time() + 1;
    time_sleep_until($since);
    $second = $this->journey->install($dir, [...J12_FLAGS, '--json']);
    $until = time() + 1;
    $again = InstanceJourney::json($second);
    $text = $this->journey->install($dir, J12_FLAGS);
    // Healthchecks run `exec` in the running containers on their own schedule (exec_create/exec_start/exec_die,
    // health_status): they are not changes. Every other instance event (create, start, stop, die, destroy,
    // network or volume events) is.
    $events = array_values(array_filter(
        InstanceJourney::events($since, $until, ['label=app.form-schema.instance='.$uuid]),
        fn (string $event): bool => preg_match('/^container (exec_(create|start|die)|health_status)\b/', $event) !== 1,
    ));
    $this->journey->evidence('J12-ae3-ae4.json', ['first' => ['exit_code' => $first->getExitCode(), 'stdout' => $json], 'second' => ['exit_code' => $second->getExitCode(), 'stdout' => $again, 'instance_events' => $events], 'third_text' => $text->getOutput()]);

    expect($first->getExitCode())->toBe(0, $first->getErrorOutput()."\n".$first->getOutput())
        ->and($json['data']['port'])->not->toBe(8080)
        ->and($json['data']['url'])->toBe('http://localhost:'.$json['data']['port'])
        ->and($second->getExitCode())->toBe(0, $second->getErrorOutput())
        ->and($again['changed'])->toBeFalse()
        ->and($again['secrets'])->toBe([])
        ->and($again['data']['port'])->toBe($json['data']['port'])
        ->and($events)->toBe([])
        ->and(InstanceJourney::startTimes($uuid))->toBe($startTimes)
        ->and($text->getExitCode())->toBe(0)
        ->and($text->getOutput())->toContain('http://localhost:'.$json['data']['port'])->toContain('nothing changed');
});

it('AE9: refuses a secret passed as a flag value with exit 2 and a stdin/file hint', function (): void {
    $dir = $this->journey->work.'/fsa-test-j12-ae9';
    $process = $this->journey->install($dir, [...J12_FLAGS, '--smtp-password=hunter2-j12', '--json']);
    $json = InstanceJourney::json($process);
    $shim = $this->journey->fsa($this->journey->work.'/fsa-test-j12-ports', ['doctor', '--api-token=hunter2-j12', '--json']);

    expect($process->getExitCode())->toBe(2, $process->getErrorOutput().$process->getOutput())
        ->and($json['errors'][0]['code'])->toBe('secret_in_argument')
        ->and($json['errors'][0]['hint'])->toContain('stdin')->toContain('-file')
        ->and($process->getOutput())->not->toContain('hunter2-j12')
        ->and(file_exists($dir.'/state.json'))->toBeFalse()
        ->and($shim->getExitCode())->toBe(2)
        ->and(InstanceJourney::json($shim)['errors'][0]['code'])->toBe('secret_in_argument');
});

it('AE5: exits 3 for a domain that does not resolve here, before any service starts or another image is pulled', function (): void {
    $dir = $this->journey->work.'/fsa-test-j12-ae5';
    $since = time();
    $process = $this->journey->install($dir, [...J12_FLAGS, '--domain', 'fsa-test-ae5.invalid', '--json']);
    $until = time() + 1;
    $json = InstanceJourney::json($process);
    $dns = collect($json['data']['checks'] ?? [])->firstWhere('key', 'dns');
    $pulls = InstanceJourney::events($since, $until, ['type=image', 'event=pull'], '{{.Actor.ID}}');
    $started = InstanceJourney::events($since, $until, ['type=container', 'event=start', 'label=com.docker.compose.project.working_dir='.$dir]);
    $this->journey->evidence('J12-ae5.json', ['exit_code' => $process->getExitCode(), 'stdout' => $json, 'image_pulls' => $pulls, 'service_starts' => $started]);

    expect($process->getExitCode())->toBe(3, $process->getErrorOutput().$process->getOutput())
        ->and($json['errors'][0]['code'])->toBe('doctor_failed')
        ->and($dns['status'] ?? null)->toBe('fail')
        ->and($dns['hint'] ?? '')->toContain('A record')
        ->and(array_diff($pulls, [$this->journey->release->appReference, ManifestVerifier::COSIGN_IMAGE]))->toBe([])
        ->and($started)->toBe([])
        ->and(file_exists($dir.'/state.json'))->toBeFalse()
        ->and(is_dir($dir.'/env'))->toBeFalse()
        ->and(file_exists($dir.'/operations/current.json'))->toBeFalse();
});
