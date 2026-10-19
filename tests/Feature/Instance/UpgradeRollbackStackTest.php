<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\DockerCommandFailed;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Operations\OperationJournal;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\UpgradeFixture;

/*
 * What the automatic rollback does with the stack (plan U18, R35, R62, KTD19, ADR 0086, review U18 A2, A3, A4, A7, A9):
 * a rollback killed after it reopened the instance only finishes its cleanup; the env files the target overwrote come
 * back before the installed release renders again; services only the target had go; the previous app answers behind
 * the gate while the data is restored; `fsa status` reports the rollback and no scratch warning while a journal is open.
 */

beforeEach(function (): void {
    $this->upgrade = new UpgradeFixture;
    $this->dir = $this->upgrade->instance->directory->root;
    $this->config = $this->upgrade->restore->config;
});

afterEach(function (): void {
    TemporaryInstance::cleanup();
});

it('only finishes the cleanup of a rollback killed after it reopened the instance: no gate, no smoke test, no exit 8', function (): void {
    $this->upgrade->child = 'fail';
    [$code] = $this->upgrade->upgrade();
    expect($code)->toBe(5);
    $this->upgrade->reopen('upgrade', 'rollback_cleanup');
    $mark = $this->config->mark();
    $this->upgrade->instance->docker->onCall['composeUp'] = static fn () => throw new DockerCommandFailed('compose up', new ProcessOutcome(1, '', 'a transient failure'));

    [$code, $json] = $this->upgrade->upgrade(['--resume' => true, '--to' => null]);

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('migration_failed')
        ->and($this->upgrade->ups($mark))->toBe([])
        ->and($this->upgrade->gate()['raised'])->toBeFalse()
        ->and(new OperationJournal($this->upgrade->instance->directory)->current())->toBeNull();
});

it('puts back the env files the target overwrote before the installed release renders again', function (string $from, string $to): void {
    $before = $this->upgrade->envFiles();
    $this->upgrade->editStagedCompose(static fn (array $document): array => $document, static function (string $root) use ($from, $to): void {
        $app = (string) file_get_contents($root.'/env/app.env');
        file_put_contents($root.'/env/app.env', (string) preg_replace('/^'.$from.'=/m', $to.'=', $app)); // the target's own layout
    });
    $this->upgrade->appUnhealthy = true;

    [$code, $json] = $this->upgrade->upgrade();

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($json['errors'][0]['code'])->toBe('target_unhealthy')
        ->and($this->upgrade->envFiles())->toBe($before);
})->with([
    'a renamed database password (exit 8 before)' => ['DB_PASSWORD', 'DB_PASS'],
    'a renamed Valkey password (regenerated silently before)' => ['REDIS_PASSWORD', 'VALKEY_PASSWORD'],
]);

it('removes the containers of services only the target had: orphans go when it rolls back and when it opens', function (): void {
    $this->upgrade->editStagedCompose(static function (array $document): array {
        $document['services']['indexer'] = [...$document['services']['gotenberg'], 'image' => $document['services']['app']['image'], 'env_file' => ['./env/gotenberg.env']];

        return $document;
    });
    $this->upgrade->unhealthyService = 'indexer';
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade();
    $ups = $this->config->callsSince($mark, 'composeUp');
    $failed = array_key_first(array_filter($ups, static fn (array $up): bool => in_array('indexer', $up['services'], true)));

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($ups[$failed + 1]['removeOrphans'])->toBeTrue()
        ->and($ups[array_key_last($ups)]['removeOrphans'])->toBeTrue()
        ->and($this->upgrade->compose())->not->toContain('indexer');
});

it('removes orphans when a committed upgrade opens', function (): void {
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade();
    $ups = $this->config->callsSince($mark, 'composeUp');

    expect($code)->toBe(0, (string) json_encode($json))
        ->and($ups[array_key_last($ups)])->toMatchArray(['services' => ['queue', 'scheduler', 'backup'], 'removeOrphans' => true]);
});

it('keeps the gated app answering while the data is restored: the previous app replaces the target, never stopped', function (): void {
    $this->upgrade->appUnhealthy = true;
    $mark = $this->config->mark();

    [$code, $json] = $this->upgrade->upgrade();
    $ups = $this->config->callsSince($mark, 'composeUp');
    $failed = array_key_first(array_filter($ups, static fn (array $up): bool => in_array('app', $up['services'], true)));
    $stops = array_merge(...array_map(static fn (array $stop): array => $stop['services'], $this->config->callsSince($mark, 'composeStop')));

    expect($code)->toBe(5, (string) json_encode($json))
        ->and($stops)->not->toContain('app')
        ->and(array_column(array_slice($ups, $failed + 1, 2), 'services'))->toBe([['postgres', 'redis'], ['app']]) // rollback_stop, before the restore
        ->and($this->upgrade->restore->actions())->toContain('swap');
});

it('reports an automatic rollback as the last restore in fsa status', function (): void {
    $this->upgrade->child = 'fail';
    [$code] = $this->upgrade->upgrade();

    [, $status] = $this->upgrade->instance->run('fsa:status');

    expect($code)->toBe(5)
        ->and($status['data']['last_restore'])->toMatchArray(['backup' => $this->upgrade->safetyBackups[0]]);
});

it('raises no scratch warning in fsa status while an upgrade journal is open', function (): void {
    $this->upgrade->interrupted('migrate');
    mkdir($this->dir.'/backups/.restore-0123456789ab', 0700, true);

    [, $status] = $this->upgrade->instance->run('fsa:status');

    expect(array_column($status['warnings'], 'code'))->not->toContain('restore_scratch_left')
        ->and($status['data']['restore']['scratch_left'])->toBe([]);
});
