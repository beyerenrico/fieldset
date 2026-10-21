<?php

declare(strict_types=1);

use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Output\ResultStatus;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M10 gate (ADR 0078): two `fieldset config set` runs must never mix. A live lock refuses a second run up front, before
 * its URL-impact one-off; a run that found no unfinished journal never adopts one another run opened while it asked
 * for confirmation; a resume takes only the journal of the same key, value and options.
 */

beforeEach(function (): void {
    $this->config = new ConfigFixture;
    $this->journal = new OperationJournal($this->config->instance->directory);
});

afterEach(fn () => TemporaryInstance::cleanup());

/** Runs `$during` when the run being tested asks for the URL impact (its confirmation window, before the lock). */
function duringConfirmation(ConfigFixture $config, Closure $during): void
{
    $config->instance->docker->respondToComposeRun(function (array $command) use ($during): ?ProcessOutcome {
        if (($command[2] ?? null) === 'instance:url-impact') {
            $during();
        }

        return null;
    });
}

it('refuses a run while another command holds the lock, before the URL-impact one-off (exit 9)', function (): void {
    $this->config->instance->docker->withRunningContainer(str_repeat('b', 64), '2026-10-18T10:00:00Z');
    app(InstanceLock::class)->acquire(TemporaryInstance::holder('b', '2026-10-18T10:00:00Z'), 'upgrade');
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(9, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('instance_locked')
        ->and($this->config->instance->oneOffCalls('instance:url-impact'))->toBe([])
        ->and($this->config->methodsSince($mark))->not->toContain('composeRun');
});

it('does not adopt a journal another run opened during its confirmation window', function (): void {
    duringConfirmation($this->config, function (): void {
        $this->journal->begin('config', 501, new ConfigRequest('port', '8095')->toContext());
        $this->journal->startPhase('apply');
    });

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    $open = $this->config->openJournal();
    expect($code)->toBe(9, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($json['data']['command'])->toBe('fieldset config set port 8095 --yes')
        ->and($open?->context()['value'])->toBe('8095')
        ->and(collect($open?->events())->pluck('type')->all())->not->toContain('resumed')
        ->and($this->config->state()->setting('port'))->toBe(8080);
});

it('resumes only the journal it saw: one replaced meanwhile by another change is refused', function (): void {
    $this->config->interruptedDuringApply(new ConfigRequest('port', '8090'));
    duringConfirmation($this->config, function (): void {
        $this->journal->finish(ResultStatus::Failed);
        $this->journal->begin('config', 501, new ConfigRequest('port', '8095')->toContext());
        $this->journal->startPhase('apply');
    });

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);

    expect($code)->toBe(9, json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('operation_unfinished')
        ->and($this->config->openJournal()?->context()['value'])->toBe('8095')
        ->and(collect($this->config->openJournal()?->events())->pluck('type')->all())->not->toContain('resumed');
});

it('names the exact command with its options when another config change is unfinished', function (): void {
    $this->journal->begin('config', 501, new ConfigRequest('domain', ConfigFixture::DOMAIN, 'ops@example.com', 'https://acme-staging-v02.api.letsencrypt.org/directory')->toContext());
    $this->journal->startPhase('apply');

    [$code, $json] = $this->config->set('port', '8090', ['--yes' => true]);
    [, $status] = $this->config->instance->run('fieldset:status');

    $command = 'fieldset config set domain '.ConfigFixture::DOMAIN.' --acme-email ops@example.com --acme-staging --yes';
    expect($code)->toBe(9)
        ->and($json['data']['command'])->toBe($command)
        ->and($json['data']['options'])->toBe(['--acme-email' => 'ops@example.com', '--acme-staging' => true, '--yes' => true])
        ->and($json['errors'][0]['hint'])->toContain($command)
        ->and($status['data']['operation'])->toMatchArray(['command' => $command])
        ->and(collect($status['warnings'])->firstWhere('code', 'operation_unfinished')['hint'])->toContain($command);
});
