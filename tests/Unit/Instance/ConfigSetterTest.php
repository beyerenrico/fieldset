<?php

declare(strict_types=1);

use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\ConfigSetter;
use App\Domain\Instance\Console\FsaContext;
use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\SecretRedactor;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * Plan U11 (R54, ADR 0077): ConfigSetter's own rules, below the console: the terminal confirmation, a state changed by
 * another command while this one waited, an apply whose services stay unhealthy, a failed audit one-off, and the
 * redaction of progress.
 */

beforeEach(function (): void {
    $this->config = new ConfigFixture;
    $this->output = new BufferedOutput;
});

afterEach(fn () => TemporaryInstance::cleanup());

function configContext(object $test, bool $yes = false): FsaContext
{
    $redactor = new SecretRedactor;

    return new FsaContext(app(HostFacts::class), new ProgressReporter($test->output, true, $redactor), $redactor, true, $yes, $test->config->instance->directory);
}

it('asks in a terminal with the impact, and a declined question changes nothing (exit 9)', function (): void {
    $asked = null;

    try {
        app(ConfigSetter::class)->set(new ConfigRequest('port', '8090'), configContext($this), function (string $question) use (&$asked): bool {
            $asked = $question;

            return false;
        });
        $this->fail('Declining must cancel.');
    } catch (InstanceException $e) {
        expect($e->exitCode)->toBe(ExitCode::AbortedUnchanged)->and($e->errorCode)->toBe('cancelled');
    }

    expect($asked)->toContain('from http://localhost:8080 to http://localhost:8090')->toContain('home-screen apps')
        ->and($this->config->state()->setting('port'))->toBe(8080);
});

it('applies after a confirmed question and reports progress per phase', function (): void {
    $result = app(ConfigSetter::class)->set(new ConfigRequest('port', '8090'), configContext($this), fn (): bool => true);

    $phases = array_map(fn (string $line): string => json_decode($line, true)['phase'], array_filter(explode("\n", $this->output->fetch())));
    expect($result->exitCode)->toBe(ExitCode::Success)
        ->and($result->changed)->toBeTrue()
        ->and($phases)->toContain('snapshot', 'preflight', 'apply', 'verify', 'audit');
});

it('stops with exit 9 when another command changed the state while this one waited for the lock', function (): void {
    $directory = $this->config->instance->directory;
    $this->config->instance->docker->respondToComposeRun(function (array $command) use ($directory): ?ProcessOutcome {
        if (($command[2] ?? null) === 'instance:url-impact') {
            $directory->saveState($directory->state()->withSetting('size', 'medium'));
        }

        return null;
    });

    $result = app(ConfigSetter::class)->set(new ConfigRequest('port', '8090'), configContext($this, yes: true), null);

    expect($result->exitCode)->toBe(ExitCode::AbortedUnchanged)
        ->and($result->errors[0]->code)->toBe('state_changed')
        ->and($this->config->openJournal())->toBeNull()
        ->and($this->config->state()->setting('port'))->toBe(8080);
});

it('reverts when the changed services do not become healthy', function (): void {
    $ups = 0;
    $this->config->instance->docker->onCall['composeUp'] = function () use (&$ups): void {
        if (++$ups === 1) {
            throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'dependency failed to start: container app is unhealthy'));
        }
    };

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(4, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('services_unhealthy')
        ->and($json['data']['reverted'])->toBeTrue()
        ->and($json['data']['failure']['docker'])->toContain('unhealthy')
        ->and(ConfigFixture::phases($this->config->archivedJournal()))->toBe(['snapshot:completed', 'preflight:completed', 'apply:failed', 'revert:completed']);
});

it('keeps a verified change when its audit one-off fails, with a warning', function (): void {
    $this->config->instance->oneOffs['instance:config-changed'] = new ProcessOutcome(1, '', 'could not connect to server');

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(0)
        ->and($json['data']['audited'])->toBeFalse()
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('audit_failed')
        ->and($this->config->state()->setting('port'))->toBe(8090);
});

it('pulls a missing image before changing anything, and a failed pull is exit 3', function (): void {
    $this->config->instance->docker->failOn('pullImage', new DockerCommandFailed('pull', new ProcessOutcome(1, '', 'manifest unknown')));

    [$code, $json] = $this->config->set('domain', ConfigFixture::DOMAIN, ['--yes' => true]);

    expect($code)->toBe(3, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('image_unavailable')
        ->and($this->config->state()->mode())->toBe('local')
        ->and($this->config->instance->docker->called('composeUp'))->toHaveCount(2); // the install's two
});
