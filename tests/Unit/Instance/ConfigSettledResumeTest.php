<?php

declare(strict_types=1);

use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\Config\ConfigKeys;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\ConfigRollout;
use App\Domain\Instance\Config\MailOptions;
use App\Domain\Instance\Console\ConfigCommand;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Operations\Interruption;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\EnvFiles;
use Tests\Support\Instance\ConfigFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * M11 gate (ADR 0078 amendment): once a `config set` has verified its change and started the workers, a later run of
 * the same command (the first one died before its journal was archived) never applies, verifies or reverts it again:
 * it runs only the completion (catcher volume, CA deletion) and the audit. The SMTP stash goes only after the audit.
 */

const SETTLED_PASSWORD = 'settled-secret-8d1f';

beforeEach(function (): void {
    $this->config = new ConfigFixture;
    app()->instance(ConfigCommand::STDIN, fn (): string => SETTLED_PASSWORD."\n");
    [$code] = $this->config->set('mail', 'smtp', ['--smtp-host' => 'smtp.example.com', '--smtp-username' => 'settled-user', '--password-stdin' => true, '--from-address' => 'forms@example.com']);
    expect($code)->toBe(0);
    $state = $this->config->state();
    $this->volume = $state->project().'_'.MailpitService::VOLUME;
    $this->config->instance->docker->withResource(new DockerResource(DockerResource::VOLUME, $this->volume, [Labels::INSTANCE => $state->uuid()]));
    $this->request = new ConfigRequest('mail', 'smtp', mail: new MailOptions(removeCatcher: true));
});

afterEach(fn () => TemporaryInstance::cleanup());

/** Leaves the journal a run leaves when it dies right after its workers started: everything up to `workers` done. */
function diedAfterWorkers(ConfigFixture $config, ConfigRequest $request): void
{
    $before = $config->state();
    $applier = app(ConfigKeys::class)->applier($request->key);
    $change = $applier->preflight($applier->plan($before, $request), app(HostFacts::class));
    $rollout = app(ConfigRollout::class);
    $journal = new OperationJournal($config->instance->directory);
    $journal->begin('config', posix_getuid(), $request->toContext());
    $journal->startPhase('snapshot', [], false);
    $journal->completePhase('snapshot', [...$change->summary(), 'previous' => $before->toArray(), 'ca_root' => null]);
    $journal->startPhase('preflight', [], false);
    $journal->completePhase('preflight', ['pulled' => [], 'to' => $change->to, 'after' => $change->after->toArray(), 'warnings' => []]);
    $journal->startPhase('apply');
    $journal->completePhase('apply', $rollout->apply($change));
    $journal->startPhase('verify', [], false);
    $journal->completePhase('verify', ['checked' => ['smoke test']]);
    $journal->startPhase('workers');
    $journal->completePhase('workers', $rollout->startWorkers($change->after));
}

it('only completes and audits a change an earlier run verified and started the workers for', function (): void {
    diedAfterWorkers($this->config, $this->request);
    $audits = count($this->config->instance->oneOffCalls('instance:config-changed'));
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('mail', 'smtp', ['--remove-catcher' => true]);

    $methods = $this->config->methodsSince($mark);
    expect($code)->toBe(0, json_encode($json))
        ->and($methods)->not->toContain('composeStop')->not->toContain('composeUp')
        ->and($this->config->instance->docker->called('removeVolume'))->toBe([['name' => $this->volume]])
        ->and($json['data']['mail'])->toMatchArray(['catcher' => 'removed', 'catcher_volume_removed' => $this->volume])
        ->and($this->config->instance->oneOffCalls('instance:config-changed'))->toHaveCount($audits + 1)
        ->and($this->config->state()->setting('mail_catcher'))->toBeNull()
        ->and($this->config->openJournal())->toBeNull()
        ->and($this->config->instance->directory->exists(ConfigRollout::CREDENTIALS_STASH))->toBeFalse()
        ->and(new EnvFiles($this->config->instance->directory)->read('app'))->toMatchArray(['MAIL_PASSWORD' => SETTLED_PASSWORD]);
});

it('exits 9 and keeps the verified change when a signal arrives as such a run resumes', function (): void {
    diedAfterWorkers($this->config, $this->request);
    $guard = new Interruption;
    $guard->handle(SIGTERM); // arrived while the resumed run took the lock (disarmed: recorded)
    app()->instance(Interruption::class, $guard);
    $mark = $this->config->mark();

    [$code, $json] = $this->config->set('mail', 'smtp', ['--remove-catcher' => true]);

    expect($code)->toBe(9, json_encode($json))
        ->and($this->config->callsSince($mark, 'composeUp'))->toBe([])
        ->and($this->config->state()->setting('mail_catcher'))->toBeNull()
        ->and($this->config->openJournal())->not->toBeNull();
});

it('keeps the previous SMTP credentials until the audit of a verified change has run', function (): void {
    $seen = [];
    $directory = $this->config->instance->directory;
    $this->config->instance->docker->respondToComposeRun(function (array $command) use (&$seen, $directory): null {
        if (in_array('instance:config-changed', $command, true)) {
            $seen[] = $directory->exists(ConfigRollout::CREDENTIALS_STASH);
        }

        return null;
    });

    [$code, $json] = $this->config->set('mail', 'smtp', ['--remove-catcher' => true]);

    expect($code)->toBe(0, json_encode($json))
        ->and($seen)->toBe([true])
        ->and($directory->exists(ConfigRollout::CREDENTIALS_STASH))->toBeFalse();
});
