<?php

declare(strict_types=1);

use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\LocalCa\LanAddress;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\Runtime\RuntimeDocument;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\RuntimeWriter;
use App\Domain\Instance\Tunnel\QuickTunnelHostname;
use App\Domain\Instance\Tunnel\TunnelClock;
use App\Domain\Instance\Tunnel\TunnelOperation;
use App\Domain\Instance\Tunnel\TunnelStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Instance\ComposeFixtures;
use Tests\Support\Instance\InstanceCommandFixture;
use Tests\Support\Instance\LocalCaFixture;
use Tests\Support\Instance\PhoneFixtures;
use Tests\Support\Instance\RecordingDockerGateway;
use Tests\Support\Instance\TemporaryInstance;
use Tests\Support\Instance\TunnelFixture;

/*
 * Plan U14 (R19, R20, AE6, KTD11, KTD12; ADR 0082): what the tunnel's state is made of — the hostname grammar, the
 * lifetime, the read-only status, the runtime block and the LAN address rules.
 */

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    TemporaryInstance::cleanup();
});

it('takes only a trycloudflare hostname from /quicktunnel', function (string $answer, ?string $hostname): void {
    expect(QuickTunnelHostname::hostname($answer))->toBe($hostname);
})->with([
    'connected' => ['{"hostname":"quiet-river-1234.trycloudflare.com"}', 'quiet-river-1234.trycloudflare.com'],
    'upper case' => ['{"hostname":"Quiet-River.TryCloudflare.com"}', 'quiet-river.trycloudflare.com'],
    'still connecting' => ['{"hostname":""}', null],
    'another domain' => ['{"hostname":"evil.example.com"}', null],
    'a subdomain trick' => ['{"hostname":"a.trycloudflare.com.evil.example"}', null],
    'nested' => ['{"hostname":"x.y.trycloudflare.com"}', null],
    'not json' => ['<html>', null],
    'no hostname' => ['{"url":"https://x.trycloudflare.com"}', null],
]);

it('keeps the hostname grammar in step with the app\'s', function (): void {
    expect(preg_match(RuntimeDocument::TUNNEL_HOST, PhoneFixtures::HOSTNAME))->toBe(1);
});

it('lives 4 hours unless a harness build shortens it', function (): void {
    expect(TunnelClock::lifetime())->toBe(4 * 3600)
        ->and(TunnelClock::expiry(CarbonImmutable::parse('2026-10-18T12:00:00Z'))->toIso8601ZuluString())->toBe('2026-10-18T16:00:00Z');
});

it('reads the tunnel status from the state, runtime.json and the container, without changing anything', function (?string $setting, ?array $tunnel, ?string $container, string $expected): void {
    $directory = InstanceDirectory::initialize(TemporaryInstance::path());
    $docker = RecordingDockerGateway::bind();
    $state = ComposeFixtures::state(['phone_https' => $setting, 'tunnel_subnet' => PhoneFixtures::TUNNEL_SUBNET]);
    $writer = new RuntimeWriter($directory);
    $writer->write($state);
    if ($tunnel !== null) {
        $writer->writeTunnel($state, $tunnel);
    }
    if ($container !== null) {
        $docker->withRunningContainer('7unne1', '2026-10-18T12:00:00Z', [Labels::COMPOSE_PROJECT => $state->project(), Labels::COMPOSE_SERVICE => 'tunnel']);
        if ($container === 'exited') {
            $docker->stopContainer('7unne1');
        }
    }
    $before = $directory->read('runtime/runtime.json');

    $status = new TunnelStatus($directory, $docker)->of($state, CarbonImmutable::parse('2026-10-18T13:00:00Z'));

    expect($status['state'])->toBe($expected)
        ->and($status['url'])->toBe($expected === 'active' ? 'https://'.PhoneFixtures::HOSTNAME : null)
        ->and($directory->read('runtime/runtime.json'))->toBe($before)
        ->and($docker->methods())->not->toContain('composeUp', 'composeStop', 'removeContainer');
})->with([
    'none' => [null, null, null, 'none'],
    'active' => ['tunnel', PhoneFixtures::tunnelBlock('2026-10-18T16:00:00Z'), 'running', 'active'],
    'expired, cloudflared still running' => ['tunnel', PhoneFixtures::tunnelBlock('2026-10-18T12:59:59Z'), 'running', 'expired'],
    'cloudflared exited' => ['tunnel', PhoneFixtures::tunnelBlock('2026-10-18T16:00:00Z'), 'exited', 'stopped'],
    'cloudflared gone' => ['tunnel', PhoneFixtures::tunnelBlock('2026-10-18T16:00:00Z'), null, 'stopped'],
    'configured, no hostname yet' => ['tunnel', null, 'running', 'stopped'],
    'a stray runtime block' => [null, PhoneFixtures::tunnelBlock('2026-10-18T16:00:00Z'), null, 'stopped'],
]);

it('writes and clears the tunnel block and the local CA root, keeping the gate', function (): void {
    $directory = InstanceDirectory::initialize(TemporaryInstance::path());
    $writer = new RuntimeWriter($directory);
    $state = PhoneFixtures::localCa();
    $writer->raiseGate($state, 'upgrade', str_repeat('a', 64));
    $writer->writeTunnel($state, PhoneFixtures::tunnelBlock());
    $withTunnel = json_decode((string) $directory->read(RuntimeWriter::FILE), true);
    $writer->writeLocalCaRoot(LocalCaFixture::root());
    $writer->writeTunnel($state, null);
    $cleared = json_decode((string) $directory->read(RuntimeWriter::FILE), true);
    $keptRoot = $directory->exists(RuntimeWriter::LOCAL_CA_ROOT);
    $mode = decoct(fileperms($directory->path(RuntimeWriter::LOCAL_CA_ROOT)) & 0777);
    $writer->write(ComposeFixtures::state());

    expect($withTunnel['tunnel'])->toBe(PhoneFixtures::tunnelBlock())
        ->and($withTunnel['gate']['raised'])->toBeTrue()
        ->and($cleared['tunnel'])->toBe(['hostname' => null, 'expires_at' => null, 'peer' => null, 'admin' => false])
        ->and($cleared['gate']['raised'])->toBeTrue()
        ->and($keptRoot)->toBeTrue()
        ->and($mode)->toBe('644')
        ->and($directory->exists(RuntimeWriter::LOCAL_CA_ROOT))->toBeFalse()
        ->and(RuntimeDocument::parse(json_decode((string) $directory->read(RuntimeWriter::FILE), true))->tunnel->hostname)->toBeNull();
});

it('picks the best LAN address: private first, never loopback, link-local or IPv6', function (array $addresses, ?string $best): void {
    expect(LanAddress::best(new HostFacts(lanAddresses: $addresses)))->toBe($best);
})->with([
    'private first' => [['203.0.113.7', '192.168.1.20', '10.0.0.5'], '192.168.1.20'],
    'public when nothing else' => [['203.0.113.7'], '203.0.113.7'],
    'skips unusable' => [['127.0.0.1', '169.254.1.1', 'fe80::1', '0.0.0.0', '224.0.0.1'], null],
    'none' => [[], null],
]);

it('validates a LAN address and tells whether it is still the host\'s', function (): void {
    expect(LanAddress::validate(' 192.168.1.20 '))->toBe('192.168.1.20')
        ->and(fn () => LanAddress::validate('fe80::1'))->toThrow(InvalidInput::class)
        ->and(LanAddress::isCurrent('192.168.1.20', new HostFacts(lanAddresses: ['192.168.1.20'])))->toBeTrue()
        ->and(LanAddress::isCurrent('192.168.1.20', new HostFacts(lanAddresses: ['10.0.0.5'])))->toBeFalse()
        ->and(LanAddress::isCurrent('192.168.1.20', new HostFacts))->toBeNull();
});

it('starts the tunnel right after `fieldset install --phone-https tunnel`, the install\'s command line confirming it', function (): void {
    $fixture = new TunnelFixture(['--phone-https' => 'tunnel']);
    $install = $fixture->config->archivedJournal('install');

    expect($fixture->instance->state()->setting('phone_https'))->toBe('tunnel')
        ->and($fixture->runtimeTunnel()['hostname'])->toBe(PhoneFixtures::HOSTNAME)
        ->and($fixture->tunnelContainer())->toBe('running')
        ->and($install->context()['phone_https'])->toBe('tunnel')
        ->and($fixture->config->archivedJournal('tunnel')->context())->toBe(['action' => 'start', 'admin' => false]);
});

it('reports the tunnel in the install result and fails the install command when it does not start', function (): void {
    $fixture = new TunnelFixture;
    $fixture->hostname = null;
    $fresh = new InstanceCommandFixture;
    $fresh->docker->respondToRun($fixture->instance->docker->runResponder);
    $fresh->docker->respondToComposeRun($fixture->instance->docker->composeRunResponder);

    [$code, $json] = $fresh->run('fieldset:install', InstanceCommandFixture::installFlags(['--phone-https' => 'tunnel']));

    expect($code)->toBe(5)
        ->and($json['command'])->toBe('install')
        ->and($json['data']['url'])->toBe('http://localhost:8080')
        ->and($json['errors'][0]['message'])->toStartWith('The instance is installed, but the quick tunnel did not start')
        ->and($fresh->state()->setting('phone_https'))->toBeNull();
});

it('waits for the hostname no longer than it announces, however slow each probe container is (A8)', function (): void {
    CarbonImmutable::setTestNow('2026-10-18T12:00:00Z');
    Sleep::fake(syncWithCarbon: true);
    $docker = RecordingDockerGateway::bind();
    $timeouts = [];
    $docker->respondToRun(function (ContainerSpec $spec) use ($docker, &$timeouts): ProcessOutcome {
        $timeout = $docker->calls[array_key_last($docker->calls)]['args']['timeout'];
        $timeouts[] = $timeout;
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds((int) ceil($timeout))); // a container that hangs until killed

        return new ProcessOutcome(-1, '', 'The process exceeded the timeout.');
    });
    $state = PhoneFixtures::tunnel();

    try {
        new QuickTunnelHostname($docker)->read($state, new ComposeProject($state->project(), TemporaryInstance::path().'/compose.json', TemporaryInstance::path()));
        $this->fail('No hostname must fail.');
    } catch (InstanceException $e) {
        expect($e->errorCode)->toBe('tunnel_unavailable');
    }

    expect(CarbonImmutable::now()->diffInSeconds(CarbonImmutable::parse('2026-10-18T12:00:00Z'), true))->toBeLessThanOrEqual((float) QuickTunnelHostname::maxSeconds())
        ->and(max($timeouts))->toBeLessThanOrEqual(60.0)
        ->and(count($timeouts))->toBeGreaterThan(1);
});

it('announces the account check before it takes the lock, with its maximum duration (A8)', function (): void {
    $fixture = new TunnelFixture;
    $output = new BufferedOutput;
    $redactor = new SecretRedactor;
    $context = new FieldsetContext(app(HostFacts::class), new ProgressReporter($output, true, $redactor), $redactor, true, true, $fixture->config->instance->directory);

    app(TunnelOperation::class)->start($context, false, true, null, false);

    $events = array_map(static fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", $output->fetch()))));
    expect($events[0])->toMatchArray(['phase' => 'accounts', 'max_seconds' => 300])
        ->and($events[0]['message'])->toContain('at most 5 min')
        ->and($events[1]['phase'])->toBe('accounts')
        ->and($events[1])->toHaveKey('elapsed_seconds');
});
