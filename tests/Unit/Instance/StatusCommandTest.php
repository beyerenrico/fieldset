<?php

declare(strict_types=1);

use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceState;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\TemporaryInstance;

/*
 * U5 / M9-T08: `fsa status`, `fsa logs` and `fsa version` (§7, R45, KTD1): read-only, never a secret.
 */

beforeEach(function (): void {
    $this->fixture = new InstanceCommandFixture;
});

afterEach(fn () => TemporaryInstance::cleanup());

it('reports version, mode, URL, mail, services, health and unused sign-in links without changing anything', function (): void {
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->fixture->withRunningStack();
    $calls = count($this->fixture->docker->calls);
    $before = $this->fixture->state()->toJson();

    [$code, $json] = $this->fixture->run('fsa:status');
    $methods = $this->fixture->docker->methodsSince($calls);

    expect($code)->toBe(0)
        ->and($json['changed'])->toBeFalse()
        ->and($json['data'])->toMatchArray(['installed' => true, 'version' => '2.0.0-alpha.1', 'mode' => 'local', 'url' => 'http://localhost:8080', 'health' => 'ok', 'operation' => null, 'lock' => null, 'last_backup' => null])
        ->and($json['data']['mail'])->toBe(['mode' => 'capture', 'catcher_url' => 'http://localhost:8025'])
        ->and($json['data']['services']['app'])->toBe(['state' => 'running', 'health' => null])
        ->and($json['data']['sign_in_links'])->toBe(['count' => 1, 'links' => [['id' => 1, 'email' => 'owner@example.com', 'kind' => 'owner', 'state' => 'unused']]])
        ->and($json['data']['disk']['free_gib'])->toEqual(50.0)
        ->and($methods)->toBe(['composePs', 'composeRun'])
        ->and($this->fixture->oneOffCalls('identity:login-links')[0]['command'])->toBe(['php', 'artisan', 'identity:login-links', '--unused', '--json'])
        ->and($this->fixture->state()->toJson())->toBe($before);
});

it('never prints the secrets of the env files', function (): void {
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->fixture->withRunningStack();
    $secrets = new EnvFiles($this->fixture->directory)->secretValues();
    $this->fixture->docker->logs = 'app-1 | connecting with '.$secrets[0]."\n";

    [, $status] = $this->fixture->run('fsa:status');
    [, $logs] = $this->fixture->run('fsa:logs', ['service' => ['app']]);
    Artisan::call('fsa:logs', ['--dir' => $this->fixture->directory->root]);
    $text = Artisan::output();

    expect(json_encode($status))->not->toContain($secrets[0])
        ->and($logs['data']['logs'])->toBe("app-1 | connecting with [redacted]\n")
        ->and($text)->toContain('connecting with [redacted]')->not->toContain($secrets[0]);
});

it('reports a stopped or degraded stack, an unfinished operation and the lock holder', function (): void {
    $this->fixture->oneOffs['migrate'] = new ProcessOutcome(1, '', 'failed');
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());

    [$code, $json] = $this->fixture->run('fsa:status');

    expect($code)->toBe(0)
        ->and($json['data']['installed'])->toBeFalse()
        ->and($json['data']['health'])->toBe('stopped')
        ->and($json['data']['operation'])->toMatchArray(['operation' => 'install', 'last_phase' => 'migrate', 'last_completed_phase' => 'up'])
        ->and($json['data']['sign_in_links'])->toBeNull()
        ->and(collect($json['warnings'])->pluck('code')->all())->toBe(['services_unhealthy', 'operation_unfinished'])
        ->and(collect($json['warnings'])->firstWhere('code', 'operation_unfinished')['hint'])->toContain('fsa install');
});

it('reports a degraded stack and a missing link list', function (): void {
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->fixture->withRunningStack();
    $this->fixture->docker->stopContainer('777777777777');
    $this->fixture->oneOffs['identity:login-links'] = new ProcessOutcome(1, '', 'down');

    [, $degraded] = $this->fixture->run('fsa:status');
    $this->fixture->docker->restartContainer('777777777777', '2026-10-18T10:00:00Z');
    [, $healthy] = $this->fixture->run('fsa:status');

    expect($degraded['data']['health'])->toBe('degraded')
        ->and($degraded['warnings'][0]['message'])->toContain('mailpit')
        ->and($healthy['data']['health'])->toBe('ok')
        ->and($healthy['data']['sign_in_links'])->toBeNull()
        ->and($healthy['warnings'][0]['code'])->toBe('sign_in_links_unavailable');
});

it('passes on only the known fields of the sign-in link list', function (): void {
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->fixture->withRunningStack();
    $this->fixture->oneOffs['identity:login-links'] = InstanceCommandFixture::oneOffJson(['command' => 'identity:login-links', 'status' => 'ok',
        'data' => ['links' => [['id' => 7, 'email' => 'a@example.com', 'state' => 'unused', 'url' => 'http://localhost:8080/login-link#leak', 'token' => 'leak']]]]);

    [, $json] = $this->fixture->run('fsa:status');

    expect($json['data']['sign_in_links'])->toBe(['count' => 1, 'links' => [['id' => 7, 'email' => 'a@example.com', 'state' => 'unused']]])
        ->and(json_encode($json))->not->toContain('leak');
});

it('reports an instance that is not installed', function (): void {
    [$code, $json] = $this->fixture->run('fsa:status');

    expect($code)->toBe(0)->and($json['data']['health'])->toBe('not_installed')->and($json['data']['installed'])->toBeFalse();
});

it('shows logs of chosen services with --since and --tail, and refuses unknown services', function (): void {
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    $this->fixture->docker->logs = "queue-1 | ready\n";

    [$code, $json] = $this->fixture->run('fsa:logs', ['service' => ['queue'], '--since' => '10m', '--tail' => '50']);
    [$allCode, $all] = $this->fixture->run('fsa:logs', ['--tail' => '0']);
    [$unknownCode, $unknown] = $this->fixture->run('fsa:logs', ['service' => ['nginx']]);
    [$badCode] = $this->fixture->run('fsa:logs', ['--since' => 'yesterday; rm']);
    [$tailCode] = $this->fixture->run('fsa:logs', ['--tail' => 'all']);

    expect($code)->toBe(0)
        ->and($json['data'])->toMatchArray(['services' => ['queue'], 'since' => '10m', 'tail' => 50, 'logs' => "queue-1 | ready\n"])
        ->and($this->fixture->docker->called('composeLogs')[0])->toMatchArray(['services' => ['queue'], 'since' => '10m', 'tail' => 50])
        ->and($allCode)->toBe(0)
        ->and($all['data']['services'])->toBe(['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit'])
        ->and($this->fixture->docker->called('composeLogs')[1]['tail'])->toBeNull()
        ->and($unknownCode)->toBe(2)
        ->and($unknown['errors'][0]['hint'])->toContain('app, queue')
        ->and($badCode)->toBe(2)
        ->and($tailCode)->toBe(2);
});

it('has no logs before the services exist', function (): void {
    $this->fixture->directory->saveState(InstanceState::create('3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192', $this->fixture->directory->root, 1, 1, 1));

    [$code, $json] = $this->fixture->run('fsa:logs');

    expect($code)->toBe(2)->and($json['errors'][0]['code'])->toBe('not_installed');
});

it('prints the fsa version without an instance directory, and the instance version inside one', function (): void {
    config(['app.version' => '2.0.0-alpha.1', 'app.revision' => 'abc123']);
    app()->instance(HostFacts::class, new HostFacts(tty: false));

    $outside = Artisan::call('fsa:version', ['--json' => true, '--dir' => '/nonexistent']);
    $json = json_decode(trim(Artisan::output()), true);
    $this->fixture->run('fsa:install', InstanceCommandFixture::installFlags());
    Artisan::call('fsa:version', ['--json' => true, '--dir' => $this->fixture->directory->root]);
    $inside = json_decode(trim(Artisan::output()), true);

    expect($outside)->toBe(0)
        ->and($json['data'])->toBe(['fsa' => '2.0.0-alpha.1', 'revision' => 'abc123', 'instance' => null, 'newest_known' => null])
        ->and($inside['data']['instance'])->toBe('2.0.0-alpha.1')
        ->and(new OperationJournal($this->fixture->directory)->current())->toBeNull();
});
