<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\AcmeHarness;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * `fsa config` against real Docker and Pebble (plan U11, PLAN M10-T04; R21, R31, R32, R54, KTD26; ADR 0077). A local
 * install from the harness release → `config show` → with an organization using SAML SSO, `config set domain`
 * without --yes exits 6 and names it → with --yes the instance switches to domain mode against Pebble: only app,
 * queue, scheduler and Mailpit are recreated, the edge is added, Postgres, Valkey and Gotenberg keep running, and
 * HTTPS serves a certificate that verifies against Pebble's issuing root → the same value again changes nothing →
 * `config set mode local` returns to the loopback URL and removes the edge → a domain whose ACME order cannot
 * succeed (the edge is not on the harness ACME network, so it reaches neither Pebble nor its challenges) reverts to
 * local mode with `reverted: true` while the old URL keeps serving → both successful changes are audited → uninstall.
 * Transcripts go to docs/agent/milestones/evidence/M10/ (paths and links redacted).
 */

const CFG_DOMAIN = 'fsa-test-cfg.test';
const CFG_FAIL_DOMAIN = 'fsa-test-cfg-fail.test';
const CFG_OWNER = 'owner@example.test';

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fsaCfg'] = new InstanceJourney($harness, $release, $harness->directory.'/cfg');
        $GLOBALS['fsaCfgAcme'] = AcmeHarness::start($harness);
    } catch (Throwable $exception) {
        $GLOBALS['fsaCfgError'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fsaCfg'])) {
        InstanceJourney::cleanup($GLOBALS['fsaCfg']->work.'/fsa-test-cfg', $GLOBALS['fsaCfgUuid'] ?? null);
    }
    if (isset($GLOBALS['fsaCfgAcme'])) {
        $GLOBALS['fsaCfgAcme']->stop();
    }
    unset($GLOBALS['fsaCfg'], $GLOBALS['fsaCfgAcme'], $GLOBALS['fsaCfgError'], $GLOBALS['fsaCfgUuid'], $GLOBALS['fsaCfgPort'], $GLOBALS['fsaCfgContainers']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FSA_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fsaCfgError'])) {
        throw $GLOBALS['fsaCfgError'];
    }
    $this->journey = $GLOBALS['fsaCfg'];
    $this->acme = $GLOBALS['fsaCfgAcme'];
    $this->dir = $this->journey->work.'/fsa-test-cfg';
    $address = cfgHostAddress();
    $this->env = ['FSA_TEST_DNS' => CFG_DOMAIN.'='.$address.';'.CFG_FAIL_DOMAIN.'='.$address];
});

/** An address the doctor accepts as this host's: a LAN address the shim reports, else a public one (NAT warning). */
function cfgHostAddress(): string
{
    $process = new Process(['/bin/sh', '-c', '. "$1"; fsa_lan_addresses', 'sh', TestImage::repository().'/distribution/shim.sh'], null, ['FSA_SHIM_LIBRARY' => '1']);
    $process->run();
    $addresses = array_values(array_filter(preg_split('/\s+/', trim($process->getOutput())) ?: []));

    return $addresses[0] ?? '1.1.1.1';
}

/** @param  list<string>  $command */
function cfgDocker(array $command): Process
{
    $process = new Process(['docker', ...$command]);
    $process->setTimeout(180);
    $process->run();

    return $process;
}

/** @return array<string, array{id: string, started: string}> service => its container (ID and start time) */
function cfgContainers(string $uuid): array
{
    $ids = array_filter(explode("\n", trim(cfgDocker(['ps', '-aq', '--no-trunc', '--filter', "label=app.form-schema.instance={$uuid}"])->getOutput())));
    $containers = [];
    foreach ($ids as $id) {
        $info = json_decode(cfgDocker(['inspect', '--format', '{{json .}}', $id])->getOutput(), true);
        $service = $info['Config']['Labels']['com.docker.compose.service'] ?? null;
        if (is_string($service) && ($info['Config']['Labels']['com.docker.compose.oneoff'] ?? 'False') !== 'True') {
            $containers[$service] = ['id' => substr($id, 0, 12), 'started' => (string) $info['State']['StartedAt']];
        }
    }
    ksort($containers);

    return $containers;
}

/**
 * Runs `fsa config set …` and attaches the edge to the harness ACME network as soon as Compose creates it (when
 * `$connect`), as J2 does for the install.
 *
 * @param  list<string>  $arguments
 */
function cfgSet(object $test, array $arguments, bool $connect): Process
{
    $process = new Process([$test->dir.'/fsa', 'config', 'set', ...$arguments, '--json'], $test->journey->work, [
        'FSA_REGISTRY' => $test->journey->harness->registry(), 'SSH_CONNECTION' => 'fsa-test 0 fsa-test 0',
        ...$test->journey->release->platformEnvironment(), ...$test->env,
    ]);
    $process->setTimeout(1200);
    $process->start();
    $edge = '';
    while ($process->isRunning()) {
        if ($connect && $edge === '') {
            $edge = trim(cfgDocker(['ps', '-aq', '--filter', 'label=app.form-schema.role=edge', '--filter', 'label=app.form-schema.instance='.$GLOBALS['fsaCfgUuid']])->getOutput());
            if ($edge !== '') {
                $test->acme->connectEdge($edge);
            }
        }
        usleep(200_000);
    }

    return $process;
}

/** @return list<string> ACME flags of the harness: Pebble's directory and the root of its HTTPS endpoint */
function cfgAcmeFlags(object $test): array
{
    return ['--acme-ca', AcmeHarness::DIRECTORY_URL, '--acme-ca-root-file', $test->acme->httpsRoot];
}

it('installs a local instance and shows its settings with secrets redacted', function (): void {
    $process = $this->journey->install($this->dir, ['--org', 'Cfg Org', '--owner-email', CFG_OWNER, '--locale', 'en', '--secrets-file', $this->journey->work.'/owner-link.json', '--json']);
    $json = InstanceJourney::json($process);
    $GLOBALS['fsaCfgUuid'] = InstanceJourney::uuid($this->dir);
    $GLOBALS['fsaCfgPort'] = $json['data']['port'] ?? null;
    $show = $this->journey->fsa($this->dir, ['config', 'show', '--json']);
    $shown = InstanceJourney::json($show);
    $this->journey->evidence('CFG-show.json', ['command' => 'fsa config show --json', 'exit_code' => $show->getExitCode(), 'stdout' => $shown], 'M10');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($show->getExitCode())->toBe(0, $show->getOutput())
        ->and($shown['data'])->toMatchArray(['mode' => 'local', 'url' => 'http://localhost:'.$GLOBALS['fsaCfgPort']])
        ->and($shown['data']['secrets']['app_key'])->toBe('[redacted]')
        ->and($show->getOutput())->not->toContain('base64:')
        ->and(collect($shown['data']['outbound_contacts']['features'])->where('active', true)->all())->toBe([]);
});

it('lists the organization with SSO and exits 6 without --yes', function (): void {
    $app = trim(cfgDocker(['ps', '-q', '--filter', 'label=app.form-schema.instance='.$GLOBALS['fsaCfgUuid'], '--filter', 'label=app.form-schema.role=app'])->getOutput());
    cfgDocker(['cp', TestImage::repository().'/tests/Install/Harness/sso-organization.php', "{$app}:/tmp/sso-organization.php"]);
    $created = cfgDocker(['exec', $app, 'php', '/tmp/sso-organization.php', CFG_OWNER]);

    $process = $this->journey->fsa($this->dir, ['config', 'set', 'domain', CFG_DOMAIN, ...cfgAcmeFlags($this), '--json'], $this->env);
    $json = InstanceJourney::json($process);
    $this->journey->evidence('CFG-confirmation.json', ['command' => 'fsa config set domain '.CFG_DOMAIN.' --acme-ca <pebble> --acme-ca-root-file <harness>/pebble-https-root.pem --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json], 'M10');

    expect($created->getExitCode())->toBe(0, $created->getErrorOutput().$created->getOutput())
        ->and($process->getExitCode())->toBe(6, $process->getOutput())
        ->and($json['errors'][0]['code'])->toBe('confirmation_required')
        ->and($json['errors'][0]['message'])->toContain('Fsa Test SSO Org (SAML SSO)')
        ->and($json['data']['impact']['sso_organizations'][0]['organization']['name'])->toBe('Fsa Test SSO Org')
        ->and(json_decode((string) file_get_contents($this->dir.'/state.json'), true)['mode'])->toBe('local');
});

it('switches to domain mode against Pebble, recreating only the affected services', function (): void {
    $before = cfgContainers($GLOBALS['fsaCfgUuid']);
    $started = microtime(true);

    $process = cfgSet($this, ['domain', CFG_DOMAIN, ...cfgAcmeFlags($this), '--yes'], connect: true);

    $json = InstanceJourney::json($process);
    $after = cfgContainers($GLOBALS['fsaCfgUuid']);
    $GLOBALS['fsaCfgContainers'] = $after;
    $kept = array_keys(array_filter($before, static fn (array $container, string $service): bool => ($after[$service] ?? null) === $container, ARRAY_FILTER_USE_BOTH));
    $this->journey->evidence('CFG-domain-switch.json', ['command' => 'fsa config set domain '.CFG_DOMAIN.' --acme-ca <pebble> --acme-ca-root-file <harness>/pebble-https-root.pem --yes --json', 'exit_code' => $process->getExitCode(), 'seconds' => round(microtime(true) - $started, 1), 'stdout' => $json, 'containers_before' => $before, 'containers_after' => $after, 'kept_running' => $kept], 'M10');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput()."\n".$this->acme->logs())
        ->and($json['data'])->toMatchArray(['mode' => 'domain', 'url' => 'https://'.CFG_DOMAIN, 'reverted' => false, 'audited' => true])
        ->and($json['data']['services']['added'])->toBe(['edge'])
        ->and($json['data']['services']['recreated'])->toEqualCanonicalizing(['app', 'queue', 'scheduler', 'mailpit'])
        ->and($kept)->toEqualCanonicalizing(['postgres', 'redis', 'gotenberg'])
        ->and(array_keys($after))->toContain('edge')
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('url_changed', 'sso_urls_changed');
});

it('serves HTTPS with a certificate that verifies against Pebble\'s issuing root', function (): void {
    $root = $this->journey->work.'/pebble-issuing-root.pem';
    file_put_contents($root, $this->acme->issuingRoot());
    $client = new Client(['http_errors' => false, 'allow_redirects' => false, 'timeout' => 30, RequestOptions::VERIFY => $root,
        'curl' => [CURLOPT_RESOLVE => [CFG_DOMAIN.':443:127.0.0.1', CFG_DOMAIN.':80:127.0.0.1']]]);

    $https = $client->get('https://'.CFG_DOMAIN.'/up');
    $http = $client->get('http://'.CFG_DOMAIN.'/up');
    $this->journey->evidence('CFG-https.json', ['https_up' => $https->getStatusCode(), 'http' => [$http->getStatusCode(), $http->getHeaderLine('Location')]], 'M10');

    expect($https->getStatusCode())->toBe(200)
        ->and($http->getStatusCode())->toBe(308)
        ->and($http->getHeaderLine('Location'))->toBe('https://'.CFG_DOMAIN.'/up');
});

it('changes nothing when the same domain is set again', function (): void {
    $process = $this->journey->fsa($this->dir, ['config', 'set', 'domain', CFG_DOMAIN, ...cfgAcmeFlags($this), '--yes', '--json'], $this->env);
    $json = InstanceJourney::json($process);
    $this->journey->evidence('CFG-idempotent.json', ['command' => 'fsa config set domain '.CFG_DOMAIN.' (same options) --yes --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json], 'M10');

    expect($process->getExitCode())->toBe(0, $process->getOutput())
        ->and($json['changed'])->toBeFalse()
        ->and(cfgContainers($GLOBALS['fsaCfgUuid']))->toBe($GLOBALS['fsaCfgContainers']);
});

it('returns to local mode with config set mode local and removes the edge', function (): void {
    $process = cfgSet($this, ['mode', 'local', '--yes'], connect: false);
    $json = InstanceJourney::json($process);
    $up = InstanceJourney::http((int) $GLOBALS['fsaCfgPort'])->get('/up');
    $this->journey->evidence('CFG-mode-local.json', ['command' => 'fsa config set mode local --yes --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'local_up' => $up->getStatusCode()], 'M10');

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($json['data'])->toMatchArray(['mode' => 'local', 'url' => 'http://localhost:'.$GLOBALS['fsaCfgPort']])
        ->and($json['data']['services']['removed'])->toBe(['edge'])
        ->and(array_keys(cfgContainers($GLOBALS['fsaCfgUuid'])))->not->toContain('edge')
        ->and($up->getStatusCode())->toBe(200);
});

it('reverts to local mode with reverted: true when the ACME order fails, and the old URL keeps serving', function (): void {
    $started = microtime(true);

    $process = cfgSet($this, ['domain', CFG_FAIL_DOMAIN, ...cfgAcmeFlags($this), '--yes'], connect: false);

    $json = InstanceJourney::json($process);
    $state = json_decode((string) file_get_contents($this->dir.'/state.json'), true);
    $up = InstanceJourney::http((int) $GLOBALS['fsaCfgPort'])->get('/up');
    $this->journey->evidence('CFG-revert.json', ['command' => 'fsa config set domain '.CFG_FAIL_DOMAIN.' --acme-ca <pebble> --acme-ca-root-file <harness>/pebble-https-root.pem --yes --json (edge kept off the ACME network)', 'exit_code' => $process->getExitCode(), 'seconds' => round(microtime(true) - $started, 1), 'stdout' => $json, 'state_mode' => $state['mode'], 'local_up' => $up->getStatusCode()], 'M10');

    expect($process->getExitCode())->toBe(4, $process->getErrorOutput().$process->getOutput())
        ->and($json['changed'])->toBeFalse()
        ->and($json['errors'][0]['code'])->toBe('certificate_failed')
        ->and($json['data'])->toMatchArray(['reverted' => true, 'mode' => 'local', 'url' => 'http://localhost:'.$GLOBALS['fsaCfgPort']])
        ->and($state['mode'])->toBe('local')
        ->and($state['url'])->toBeNull()
        ->and(array_keys(cfgContainers($GLOBALS['fsaCfgUuid'])))->not->toContain('edge')
        ->and(is_file($this->dir.'/operations/current.json'))->toBeFalse()
        ->and($up->getStatusCode())->toBe(200);
});

it('audited the two successful changes as instance.config_changed with the host UID', function (): void {
    $postgres = trim(cfgDocker(['ps', '-q', '--filter', 'label=app.form-schema.instance='.$GLOBALS['fsaCfgUuid'], '--filter', 'label=app.form-schema.role=postgres'])->getOutput());
    $rows = cfgDocker(['exec', $postgres, 'psql', '-U', 'form_schema', '-d', 'form_schema', '-tAc',
        "SELECT properties->>'key', properties->>'to', properties->>'host_uid', actor_type FROM activity_log WHERE event = 'instance.config_changed' ORDER BY id"]);
    $lines = array_values(array_filter(explode("\n", trim($rows->getOutput()))));
    $this->journey->evidence('CFG-audit.json', ['instance.config_changed' => $lines], 'M10');

    expect($lines)->toBe(['domain|'.CFG_DOMAIN.'|'.posix_getuid().'|cli', 'mode|local|'.posix_getuid().'|cli']);
});

it('uninstalls and leaves nothing labelled behind', function (): void {
    $process = $this->journey->fsa($this->dir, ['uninstall', '--yes', '--json'], $this->env);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(InstanceJourney::labelled($GLOBALS['fsaCfgUuid']))->toBe(['containers' => [], 'volumes' => [], 'networks' => []]);
});
