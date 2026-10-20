<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\AgeCli;
use App\Domain\Instance\Backup\BackupEntry;
use App\Domain\Instance\Backup\BackupRetention;
use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\Ledger;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Tests\Support\Instance\DemoFixtures;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\ResetFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * `fsa reset` on the ops side (R43, R57, R68, KTD26, plan U23, ADR 0088), against the recording Docker gateway: the plan
 * one-off, quiesce, the ledger with the final heads before the database goes, the safety backup or the audit export, the
 * recreated database, migrations, the epoch from the ledger with `instance.reset`, a fresh storage volume, the Valkey and
 * catcher flush, install's seed phase, the verification behind the gate and `--demo` under the reachability rule. Ways
 * back and resumes are in ResetRecoveryTest.
 */

beforeEach(function (): void {
    $this->reset = new ResetFixture;
    $this->dir = $this->reset->instance->directory->root;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

/** @return array<string, mixed> */
function resetRuntime(string $dir): array
{
    return json_decode((string) file_get_contents($dir.'/runtime/runtime.json'), true);
}

it('asks before resetting (exit 6 without a terminal and without --yes) and changes nothing', function (): void {
    $mark = $this->reset->config->mark();

    [$code, $json] = $this->reset->reset([]);

    expect($code)->toBe(6)->and($json['errors'][0]['code'])->toBe('confirmation_required')
        ->and($json['errors'][0]['message'])->toContain('safety backup is taken first')
        ->and($this->reset->config->methodsSince($mark))->not->toContain('composeStop')->not->toContain('composeRun')
        ->and(new Ledger($this->reset->instance->directory)->entries())->toBe([]);
});

it('resets with --yes: quiesce, the ledger before the drop, safety backup, recreate, migrate, epoch, fresh storage, flush, seed, verify, open, gate, cleanup', function (): void {
    $state = $this->reset->config->state();
    $mark = $this->reset->config->mark();

    [$code, $json] = $this->reset->reset();
    $after = $this->reset->config->state();
    $journal = $this->reset->archived();
    $id = substr((string) preg_replace('/[^a-f0-9]/', '', $journal->id()), 0, 12);
    $fresh = $state->project().'_app-storage-reset-'.$id;
    $epoch = $this->reset->calls[array_search('epoch', $this->reset->actions(), true)];
    $install = $this->reset->instance->oneOffCalls('app:install');
    $flush = array_values(array_filter($this->reset->instance->runSpecs('curl'), static fn ($spec): bool => in_array('DELETE', $spec->command, true)));
    $flushCall = array_values(array_filter($this->reset->config->callsSince($mark, 'runContainer'), static fn (array $call): bool => in_array('DELETE', $call['spec']->command, true)));

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(ResetFixture::phases($journal))->toBe(['plan', 'quiesce', 'ledger', 'safety_backup', 'recreate_database', 'migrate', 'epoch', 'storage', 'flush', 'seed', 'verify', 'open', 'gate', 'cleanup'])
        ->and($this->reset->actions())->toBe(['plan', 'plan', 'recreate', 'epoch'])
        ->and($this->reset->config->callsSince($mark, 'composeStop')[0]['services'])->toBe(['app', 'scheduler', 'backup'])
        ->and($this->reset->config->callsSince($mark, 'composeStop')[2]['services'])->toBe(['app', 'queue', 'scheduler', 'backup']) // before the drop (A1)
        ->and($this->reset->config->callsSince($mark, 'composeStop')[1])->toMatchArray(['services' => ['queue'], 'timeout' => 100])
        ->and($this->reset->instance->oneOffCalls('audit:seal'))->toHaveCount(1)
        ->and(array_filter($this->reset->instance->docker->called('composeRun'), static fn (array $call): bool => ($call['command'][3] ?? null) === '--kind=safety-reset'))->toHaveCount(1)
        ->and(array_filter($this->reset->config->callsSince($mark, 'composeRun'), static fn (array $call): bool => ($call['command'][2] ?? null) === 'migrate'))->toHaveCount(1)
        ->and($epoch['command'])->toContain('--epoch-seed=1', '--safety-backup='.ResetFixture::SAFETY, '--actor-uid='.posix_getuid())->not->toContain('--demo')
        ->and(json_decode((string) $epoch['input'], true))->toBe(['previous_heads' => ['0' => ['seq' => 7, 'hash' => ResetFixture::HEAD]], 'previous_epoch' => 1, 'sequences' => ['users_id_seq' => 40, 'organization_user_id_seq' => 11, 'activity_log_id_seq' => 900]])
        ->and($this->reset->instance->docker->called('createVolume')[0]['name'])->toBe($fresh)
        ->and(InstanceSettings::of($after)->storageVolume())->toBe($fresh)
        ->and(array_column($this->reset->instance->docker->called('removeVolume'), 'name'))->toBe([$fresh, $state->project().'_app-storage'])
        ->and(collect($this->reset->instance->docker->called('composeRun'))->contains(fn (array $call): bool => ($call['command'][0] ?? null) === 'valkey-cli'))->toBeTrue()
        ->and($flush)->toHaveCount(1)
        ->and(implode(' ', $flush[0]->command))->toContain('http://mailpit:8025/api/v1/messages')->not->toContain('fsa:')
        ->and($flushCall[0]['input'])->toStartWith('user = "fsa:')
        ->and($install[0]['command'])->toContain('--organization=Acme Forms', '--email=owner@example.com', '--name=Olivia Owner', '--locale=en', '--owner-link')
        ->and(resetRuntime($this->dir)['gate']['raised'])->toBeFalse()
        ->and(new OperationJournal($this->reset->instance->directory)->current())->toBeNull()
        ->and($json['data'])->toMatchArray(['organization' => 'Acme Forms', 'owner_email' => 'owner@example.com', 'safety_backup' => ResetFixture::SAFETY, 'audit_export' => null,
            'epoch' => 2, 'previous_epoch' => 1, 'storage_volume' => $fresh, 'flushed' => ['valkey' => true, 'catcher' => true], 'left_behind' => [],
            'removed' => ['volume '.$state->project().'_app-storage']])
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets')
        ->and(array_column($json['secrets'], 'name'))->toBe(['sign_in_link'])
        ->and($json['warnings'])->toBe([])
        ->and($after->history()[array_key_last($after->history())])->toMatchArray(['event' => 'reset', 'journal' => $journal->id(), 'epoch' => 2, 'previous_epoch' => 1, 'safety_backup' => ResetFixture::SAFETY, 'demo' => false]);
});

it('appends the final heads to the ledger after the writers stopped and before the database is recreated', function (): void {
    $this->reset->reset();
    $journal = $this->reset->archived();
    $ledger = new Ledger($this->reset->instance->directory)->last('reset');
    $phase = static fn (string $name): array => array_values(array_filter($journal->phases(), static fn (array $p): bool => $p['name'] === $name))[0];

    expect($ledger)->toMatchArray(['action' => 'reset', 'journal' => $journal->id(), 'epoch' => 1, 'intact' => true, 'host_uid' => posix_getuid(), 'demo' => false, 'no_backup' => false])
        ->and($ledger['heads'])->toBe(['0' => ['seq' => 7, 'hash' => ResetFixture::HEAD]])
        ->and($ledger['at'] <= $phase('recreate_database')['started_at'])->toBeTrue()
        ->and($phase('quiesce')['finished_at'] <= $ledger['at'])->toBeTrue();
});

it('keeps settings and secrets: env files and every setting but the storage volume are unchanged, the owner only as a digest in the archive', function (): void {
    $env = new EnvFiles($this->reset->instance->directory);
    $before = [$env->read('app'), $env->read('mailpit'), $env->read('backup')];
    $settings = $this->reset->config->state()->toArray()['settings'];

    $this->reset->reset();
    $after = $this->reset->config->state()->toArray()['settings'];
    unset($after['storage_volume']);

    expect([$env->read('app'), $env->read('mailpit'), $env->read('backup')])->toBe($before)
        ->and($after)->toBe($settings)
        ->and(json_encode($this->reset->archived()->toArray()))->not->toContain('owner@example.com')->not->toContain('Olivia Owner')->toContain('hmac-sha256:');
});

it('takes a safety backup that retention never prunes', function (): void {
    $this->reset->reset();
    $store = app(BackupStore::class);
    $entries = $store->entries();

    expect(array_map(static fn (BackupEntry $entry): string => $entry->name->name(), $entries))->toContain(ResetFixture::SAFETY)
        ->and(array_map(static fn (BackupEntry $entry): string => $entry->name->name(), BackupRetention::prune($entries, 1)))->not->toContain(ResetFixture::SAFETY);
});

it('writes an audit export instead of a safety backup with --no-backup while a real organization exists', function (): void {
    [$code, $json] = $this->reset->reset(['--yes' => true, '--no-backup' => true]);
    $export = $json['data']['audit_export'];
    $epoch = $this->reset->calls[array_search('epoch', $this->reset->actions(), true)];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and(ResetFixture::phases($this->reset->archived()))->toContain('audit_export')->not->toContain('safety_backup')
        ->and($export)->toMatch('/^audit-export-reset-\d{8}T\d{6}Z\.jsonl$/')
        ->and(fileperms($this->dir.'/backups/'.$export) & 0777)->toBe(0600)
        ->and(json_decode((string) strtok((string) file_get_contents($this->dir.'/backups/'.$export), "\n"), true)['entries'])->toBe(2)
        ->and(glob($this->dir.'/backups/*.partial'))->toBe([])
        ->and(array_filter($this->reset->instance->docker->called('composeRun'), static fn (array $call): bool => ($call['command'][1] ?? null) === 'backup-daemon'))->toBe([])
        ->and($epoch['command'])->toContain('--audit-export='.$export)
        ->and($json['data']['safety_backup'])->toBeNull()
        ->and(collect($json['warnings'])->firstWhere('code', 'no_backup')['message'])->toContain($export)
        ->and(new Ledger($this->reset->instance->directory)->last('reset'))->toMatchArray(['no_backup' => true]);
});

it('encrypts the audit export to the backup recipients when encryption is on, and never leaves it plain in backups/ (M12 gate S5)', function (): void {
    app()->instance(AgeCli::class, new AgeCli(base_path('tests/Fixtures/bin/fake-age'), base_path('tests/Fixtures/bin/fake-age-keygen')));
    $recipient = 'age1'.str_repeat('z', 58);
    $this->reset->instance->directory->saveState($this->reset->config->state()->withRecipients([$recipient]));
    $seen = [];
    $responder = $this->reset->instance->docker->fileRunResponder;
    $this->reset->instance->docker->fileRunResponder = function (?string $entrypoint, array $command, string $file) use ($responder, &$seen): ?ProcessOutcome {
        $seen[] = $file;

        return $responder($entrypoint, $command, $file);
    };

    [$code, $json] = $this->reset->reset(['--yes' => true, '--no-backup' => true]);
    $export = (string) $json['data']['audit_export'];
    $epoch = $this->reset->calls[array_search('epoch', $this->reset->actions(), true)];

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($export)->toMatch('/^audit-export-reset-\d{8}T\d{6}Z\.jsonl\.age$/')
        ->and((string) file_get_contents($this->dir.'/backups/'.$export))->toStartWith('fake-age:to:'.$recipient)
        ->and(fileperms($this->dir.'/backups/'.$export) & 0777)->toBe(0600)
        ->and(glob($this->dir.'/backups/audit-export-reset-*.jsonl'))->toBe([])
        ->and($seen[0] ?? '')->toStartWith($this->dir.'/staging/') // the plain stream never lands in backups/
        ->and(glob($this->dir.'/staging/audit-export-reset-*'))->toBe([])
        ->and($epoch['command'])->toContain('--audit-export='.$export);
});

it('keeps nothing with --no-backup when only demo organizations exist, and says so', function (): void {
    $this->reset->plan['organizations'] = ['total' => 1, 'non_demo' => 0];

    [$code, $json] = $this->reset->reset(['--yes' => true, '--no-backup' => true]);

    expect($code)->toBe(0)
        ->and(ResetFixture::phases($this->reset->archived()))->toContain('audit_export')->not->toContain('safety_backup')
        ->and($this->reset->archived()->phaseData('audit_export'))->toBe(['file' => null, 'skipped' => 'only_demo_organizations'])
        ->and($this->reset->instance->docker->called('composeRunToFile'))->toBe([])
        ->and($json['data'])->toMatchArray(['safety_backup' => null, 'audit_export' => null])
        ->and(collect($json['warnings'])->firstWhere('code', 'no_backup')['message'])->toContain('nothing of the old data was kept');
});

it('counts the organizations for the export again after the writers stopped: one created meanwhile is exported', function (): void {
    $demoOnly = [...$this->reset->plan, 'organizations' => ['total' => 1, 'non_demo' => 0]];
    $this->reset->answers['plan'] = [ResetFixture::ok($demoOnly, false), ResetFixture::ok([...$demoOnly, 'organizations' => ['total' => 2, 'non_demo' => 1]], false)];

    [$code, $json] = $this->reset->reset(['--yes' => true, '--no-backup' => true]);

    expect($code)->toBe(0)
        ->and($this->reset->actions())->toBe(['plan', 'plan', 'recreate', 'epoch'])
        ->and($json['data']['audit_export'])->toStartWith('audit-export-reset-');
});

it('refuses when the organization of the fresh instance is unknown (no install record of it, no real organization): exit 3', function (): void {
    $state = $this->reset->config->state()->toArray();
    $state['history'] = array_map(static fn (array $entry): array => array_diff_key($entry, ['organization' => true]), $state['history']);
    $this->reset->instance->directory->saveState(InstanceState::fromArray($state));
    $this->reset->plan['organization'] = null;

    [$code, $json] = $this->reset->reset();

    expect($code)->toBe(3)->and($json['errors'][0]['code'])->toBe('organization_unknown');
});

it('gives the demo users random passwords with --demo on an instance reachable beyond loopback, as secrets only', function (): void {
    $this->reset->instance->directory->saveState($this->reset->config->state()->withMode('proxy')->withUrl('https://forms.example.com')->withSetting('proxy', 'port'));
    $this->reset->instance->oneOffs['app:demo-seed'] = DemoFixtures::seeded('random');

    [$code, $json] = $this->reset->reset(['--yes' => true, '--demo' => true]);
    $secrets = array_column($json['secrets'], 'value', 'name');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($this->reset->instance->oneOffCalls('app:demo-seed')[0]['command'])->toContain('--passwords=random')
        ->and($json['data']['demo'])->toMatchArray(['seeded' => true, 'passwords' => 'random', 'exposure' => ['proxy_mode'], 'delivered' => 'secrets'])
        ->and($secrets['demo_password:worker@demo.test'])->toBe(DemoFixtures::password('worker@demo.test'))
        ->and(json_encode([$json['data'], $json['warnings'], $this->reset->archived()->toArray(), $this->reset->config->state()->toArray()]))->not->toContain('pw-seed-')
        ->and($this->reset->calls[array_search('epoch', $this->reset->actions(), true)]['command'])->toContain('--demo')
        ->and(array_search('demo', ResetFixture::phases($this->reset->archived()), true))->toBeGreaterThan(array_search('open', ResetFixture::phases($this->reset->archived()), true));
});

it('keeps the documented password with --demo on a loopback-only instance, and records the demo after the reset entry', function (): void {
    $this->reset->instance->oneOffs['app:demo-seed'] = DemoFixtures::seeded('default');

    [$code, $json] = $this->reset->reset(['--yes' => true, '--demo' => true]);
    $events = array_column($this->reset->config->state()->history(), 'event');

    expect($code)->toBe(0)
        ->and($json['data']['demo'])->toMatchArray(['passwords' => 'default', 'default_password' => 'password', 'exposure' => []])
        ->and(array_search('demo_seeded', array_reverse($events, true), true))->toBeGreaterThan(array_search('reset', array_reverse($events, true), true));
});

it('forgets the demo of before the reset: install --demo then counts as a change', function (): void {
    $this->reset->instance->oneOffs['app:demo-seed'] = DemoFixtures::seeded('default');
    $this->reset->instance->run('fsa:demo');

    $this->reset->reset();
    [$code, $json] = $this->reset->instance->run('fsa:install', InstanceCommandFixture::installFlags(['--owner-email' => 'owner@example.com', '--demo' => true]));

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('use_config_set')
        ->and($json['errors'][0]['hint'])->toContain('fsa demo');
});

it('refuses when no active operator can own the fresh instance: exit 3 before anything stops', function (): void {
    $this->reset->plan['operators'] = [];
    $mark = $this->reset->config->mark();

    [$code, $json] = $this->reset->reset();

    expect($code)->toBe(3)->and($json['errors'][0]['code'])->toBe('owner_unknown')
        ->and($json['errors'][0]['hint'])->toContain('fsa config set operator-email')
        ->and($this->reset->config->methodsSince($mark))->not->toContain('composeStop')
        ->and(new OperationJournal($this->reset->instance->directory)->current())->toBeNull();
});

it('picks the install owner by its digest among several operators, the organization and locale from the install record', function (): void {
    $this->reset->plan['operators'] = [['email' => 'first@example.com', 'name' => 'First Operator', 'locale' => 'de'], ['email' => 'owner@example.com', 'name' => 'Olivia Owner', 'locale' => 'de']];
    $this->reset->plan['organization'] = ['name' => 'Renamed GmbH', 'locale' => 'de'];

    $this->reset->reset();

    expect($this->reset->instance->oneOffCalls('app:install')[0]['command'])->toContain('--email=owner@example.com', '--organization=Acme Forms', '--locale=en');
});

it('keeps the gate up while Valkey cannot be emptied and empties it on the next run before anything starts (review S3)', function (): void {
    $previous = $this->reset->instance->docker->composeRunResponder;
    $noauth = true;
    $this->reset->instance->docker->respondToComposeRun(static function (array $command, ?string $input = null) use ($previous, &$noauth): ?ProcessOutcome {
        return ($command[0] ?? null) === 'valkey-cli' && $noauth ? new ProcessOutcome(0, "NOAUTH Authentication required.\n") : $previous($command, $input);
    });

    $mark = $this->reset->config->mark();
    [$failed, $failedJson] = $this->reset->reset();
    $gate = resetRuntime($this->dir)['gate']['raised'];
    $started = collect($this->reset->config->callsSince($mark, 'composeUp'))->contains(fn (array $call): bool => in_array($call['services'], [['app'], []], true));
    $noauth = false;
    [$code, $json] = $this->reset->reset([]);

    expect($failed)->toBe(4)->and($failedJson['errors'][0]['code'])->toBe('valkey_not_flushed')
        ->and($gate)->toBeTrue()
        ->and($started)->toBeFalse()
        ->and($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['flushed']['valkey'])->toBeTrue();
});

it('warns when the catcher cannot be emptied and when the replaced volume cannot be removed', function (): void {
    $run = $this->reset->instance->docker->runResponder;
    $this->reset->instance->docker->respondToRun(static fn (ContainerSpec $spec): ?ProcessOutcome => in_array('DELETE', $spec->command, true)
        ? new ProcessOutcome(22, '', 'curl: (22) 401') : $run($spec));
    $this->reset->instance->docker->failOn('removeVolume', new RuntimeException('volume is in use'));
    $project = $this->reset->config->state()->project();

    [$code, $json] = $this->reset->reset();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['flushed'])->toBe(['valkey' => true, 'catcher' => false])
        ->and(array_column($json['warnings'], 'code'))->toBe(['catcher_not_flushed', 'reset_cleanup_incomplete'])
        ->and($json['data']['left_behind'])->toBe(['volume '.$project.'_app-storage']);
});

it('finds nothing to empty without a catcher (SMTP mode, catcher removed)', function (): void {
    $this->reset->instance->directory->saveState($this->reset->config->state()->withSetting('mail', 'smtp')->withSetting('mail_catcher', null)->withSetting('smtp_host', 'smtp.example.com')->withSetting('smtp_port', 587));

    [$code, $json] = $this->reset->reset();

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($json['data']['flushed']['catcher'])->toBeNull()
        ->and(array_filter($this->reset->instance->runSpecs('curl'), static fn ($spec): bool => in_array('DELETE', $spec->command, true)))->toBe([]);
});

it('reports the last reset in fsa status', function (): void {
    $this->reset->reset();

    [, $status] = $this->reset->instance->run('fsa:status');

    expect($status['data']['last_reset'])->toMatchArray(['epoch' => 2, 'safety_backup' => ResetFixture::SAFETY, 'audit_export' => null, 'demo' => false])
        ->and($status['data']['reset']['interrupted'])->toBeNull();
});

it('refuses an instance that is not installed', function (): void {
    $fresh = new InstanceCommandFixture;

    [$code, $json] = $fresh->run('fsa:reset', ['--yes' => true]);

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('not_installed');
});
