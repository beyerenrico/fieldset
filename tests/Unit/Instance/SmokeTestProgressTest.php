<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Install\SmokeTest;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\OperationInterrupted;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\SecretRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Instance\DomainFixtures;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M10 gate (ADR 0078): long steps report progress. The certificate wait is a PHP loop, one curl container per
 * attempt with an NDJSON event each ("attempt 7/40", elapsed and maximum seconds), stopping at 40 attempts or 420 s;
 * phases announce their maximum duration and report when they completed. The interruption guard turns a signal into
 * an exception in the running step while armed, and only records it while disarmed.
 */

beforeEach(function (): void {
    $this->docker = new RecordingDockerGateway;
    $this->output = new BufferedOutput;
    $this->progress = new ProgressReporter($this->output, true, new SecretRedactor);
    $this->state = DomainFixtures::state();
    $this->project = new ComposeProject($this->state->project(), '/srv/fsa/compose.yaml', '/srv/fsa');
    Sleep::fake();
});

afterEach(fn () => TemporaryInstance::cleanup());

function lastArgument(ContainerSpec $spec): string
{
    return (string) ($spec->command[array_key_last($spec->command)] ?? '');
}

/** @return list<array<string, mixed>> */
function progressEvents(BufferedOutput $output): array
{
    return array_values(array_map(fn (string $line): array => json_decode($line, true), array_filter(explode("\n", $output->fetch()))));
}

it('waits for the certificate in a loop with one progress event per attempt and a pause between attempts', function (): void {
    $https = 0;
    $this->docker->respondToRun(function (ContainerSpec $spec) use (&$https): ProcessOutcome {
        $url = lastArgument($spec);

        return match (true) {
            str_starts_with($url, 'https://') => ++$https < 3 ? new ProcessOutcome(35, '000', 'curl: (35) TLS connect error') : new ProcessOutcome(0, '200'),
            str_starts_with($url, 'http://forms') => new ProcessOutcome(0, '308 https://forms.example.com/up'),
            default => new ProcessOutcome(0, "200 http://app:8080/up\n"),
        };
    });

    $result = new SmokeTest($this->docker)->run($this->state, $this->project, $this->progress, 'verify');

    $events = array_values(array_filter(progressEvents($this->output), fn (array $event): bool => isset($event['attempt'])));
    $attempts = array_values(array_filter($this->docker->called('runContainer'), fn (array $call): bool => str_starts_with(lastArgument($call['spec']), 'https://')));
    expect($result['checked'])->toContain('https://forms.example.com/up')
        ->and($attempts)->toHaveCount(3)
        ->and($attempts[0]['spec']->command)->not->toContain('--retry')->toContain('--max-time', '15')
        ->and($events)->toHaveCount(3)
        ->and($events[1])->toMatchArray(['phase' => 'verify', 'level' => 'info', 'attempt' => 2, 'attempts' => 40, 'max_seconds' => 420])
        ->and($events[1]['message'])->toContain('attempt 2/40')->toContain('of at most 420 s')
        ->and($events[1])->toHaveKey('elapsed_seconds');
    Sleep::assertSleptTimes(2);
    Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds()]);
});

it('gives up after 40 attempts with certificate_failed and the edge log', function (): void {
    $this->docker->logs = 'edge | acme: order failed';
    $this->docker->respondToRun(fn (ContainerSpec $spec): ProcessOutcome => str_starts_with(lastArgument($spec), 'https://') ? new ProcessOutcome(35, '000', 'TLS') : new ProcessOutcome(0, "200 http://app:8080/up\n"));

    try {
        new SmokeTest($this->docker)->run($this->state, $this->project, $this->progress);
        $this->fail('The certificate wait must fail.');
    } catch (InstanceException $e) {
        expect($e->errorCode)->toBe('certificate_failed')->and($e->data['logs'])->toContain('order failed');
    }

    $https = array_filter($this->docker->called('runContainer'), fn (array $call): bool => str_starts_with(lastArgument($call['spec']), 'https://'));
    expect($https)->toHaveCount(SmokeTest::CERTIFICATE_ATTEMPTS);
    Sleep::assertSleptTimes(SmokeTest::CERTIFICATE_ATTEMPTS - 1);
});

it('stops waiting at the time limit even before the 40th attempt', function (): void {
    CarbonImmutable::setTestNow('2026-10-18 12:00:00');
    $this->docker->respondToRun(function (ContainerSpec $spec): ProcessOutcome {
        if (str_starts_with(lastArgument($spec), 'https://')) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(35)); // a slow attempt plus the pause

            return new ProcessOutcome(28, '000', 'timeout');
        }

        return new ProcessOutcome(0, "200 http://app:8080/up\n");
    });

    expect(fn () => new SmokeTest($this->docker)->run($this->state, $this->project))->toThrow(InstanceException::class);

    $https = array_filter($this->docker->called('runContainer'), fn (array $call): bool => str_starts_with(lastArgument($call['spec']), 'https://'));
    expect(count($https))->toBe(12) // 12 × 35 s = 420 s
        ->and(SmokeTest::maxSeconds($this->state))->toBeGreaterThanOrEqual(2 * 240 + SmokeTest::CERTIFICATE_SECONDS);
    CarbonImmutable::setTestNow();
});

it('announces a phase with its maximum duration and reports its completion and its failure with the time taken', function (): void {
    $directory = TemporaryInstance::withState();
    $journal = new OperationJournal($directory);
    $run = new OperationRun($journal, $journal->begin('config', 501), $this->progress);

    $run->phase('apply', fn (): array => [], maxSeconds: 400);
    expect(fn () => $run->phase('verify', fn (): array => throw new RuntimeException('smoke failed'), changes: false))->toThrow(RuntimeException::class);

    $events = progressEvents($this->output);
    expect($events[0])->toMatchArray(['phase' => 'apply', 'message' => 'Starting apply (at most 6 min)', 'max_seconds' => 400])
        ->and($events[1])->toMatchArray(['phase' => 'apply', 'level' => 'info', 'elapsed_seconds' => 0])
        ->and($events[1]['message'])->toStartWith('Completed apply in ')
        ->and($events[2])->toMatchArray(['phase' => 'verify', 'message' => 'Starting verify'])->not->toHaveKey('max_seconds')
        ->and($events[3])->toMatchArray(['phase' => 'verify', 'level' => 'error'])
        ->and($events[3]['message'])->toContain('verify failed after')->toContain('smoke failed')
        ->and(OperationRun::duration(45))->toBe('45 s')
        ->and(OperationRun::duration(420))->toBe('7 min');
});

it('throws into the running step while armed and only records a signal while disarmed', function (): void {
    $guard = new Interruption;

    $guard->disarm();
    $guard->handle(15);
    expect($guard->pending())->toBe(15)
        ->and(fn () => $guard->arm())->toThrow(OperationInterrupted::class, 'SIGTERM');

    $guard->arm();
    try {
        $guard->handle(1);
        $this->fail('An armed guard must interrupt.');
    } catch (OperationInterrupted $e) {
        expect($e->signal)->toBe(1)->and($e->errorCode)->toBe('interrupted')->and($e->data)->toBe(['signal' => 'SIGHUP']);
    }
    $guard->handle(2); // disarmed by the first interruption: recorded only
    expect($guard->pending())->toBe(2);
});

it('installs real signal handlers around the body and restores the previous ones', function (): void {
    if (! Interruption::supported()) {
        $this->markTestSkipped('pcntl is not available in this PHP.');
    }
    $guard = new Interruption;
    $before = pcntl_signal_get_handler(SIGTERM);

    $caught = $guard->guard(function () use ($guard): ?int {
        $guard->arm();
        try {
            posix_kill(getmypid(), SIGTERM);
            usleep(1000); // the asynchronous handler runs here at the latest
        } catch (OperationInterrupted $e) {
            return $e->signal;
        }

        return null;
    });

    expect($caught)->toBe(SIGTERM)->and(pcntl_signal_get_handler(SIGTERM))->toBe($before);
});
