<?php

declare(strict_types=1);

use App\Domain\Instance\Backup\BackupEntry;
use App\Domain\Instance\Backup\BackupName;
use App\Domain\Instance\Backup\BackupRetention;
use App\Domain\Instance\Operations\OperationJournal;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\AcmeHarness;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\ReleaseHarness;
use Tests\Support\Docker\RestoreJourney;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;

/*
 * J5, demo part (BUILD_PROMPT_V2 §11, AE2, R29, R30; plan U13; ADR 0081), with real Docker against the harness release:
 *
 * - A loopback-only local install with --demo whose own organization is named "Demo" (the demo is seeded beside it, M11
 *   gate): the demo users sign in with `password` (Fortify's headless login, as the Field App does, then the session
 *   reaches /api/v1/me); the app-side check finds all nine and one organization besides the demo; `fieldset demo` changes
 *   no demo data and its new demo owner link signs in; `fieldset config set domain` is refused in its preflight (exit 3
 *   `default_password_in_use`) with nothing changed; `fieldset demo --rotate-passwords` makes `password` and an existing
 *   remembered session fail and the printed passwords work; the check then finds none.
 * - A domain-mode install with --demo against Pebble (as J2): `password` is refused, and every demo user signs in over
 *   HTTPS with the random password printed once into --secrets-file.
 *
 * Reset part (R43, R57, R68, KTD26; plan U23; ADR 0088):
 *
 * - `fieldset reset --yes` on the local demo: the fresh instance holds the install's organization and owner only (the demo
 *   users are gone, the owner's new link signs in), an empty storage volume (a planted file is gone, the volume is new),
 *   an empty catcher, a safety backup that retention never prunes, the ledger line with the final heads written before
 *   the database was recreated, and `instance.reset` as the first entry of the next epoch, naming those heads.
 * - `fieldset reset --demo --no-backup --yes` on the domain demo: no safety backup, but an audit export (a real organization
 *   existed), and the demo users of the fresh instance get random passwords (domain mode is reachable beyond loopback).
 *
 * Everything is named `fieldset-test-j05-…` and removed afterwards. Evidence goes to the milestone of FIELDSET_EVIDENCE_MILESTONE.
 */

const J05_DOMAIN = 'fieldset-test-j05.test';
const J05_DEMO_USERS = [
    'owner@demo.test', 'admin@demo.test', 'designer@demo.test', 'reviewer@demo.test', 'coordinator@demo.test',
    'worker@demo.test', 'mobile@demo.test', 'tablet@demo.test', 'viewer@demo.test',
];

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $GLOBALS['fieldsetJ05'] = new InstanceJourney($harness, $release, $harness->directory.'/j05');
        $GLOBALS['fieldsetJ05Acme'] = AcmeHarness::start($harness);
    } catch (Throwable $exception) {
        $GLOBALS['fieldsetJ05Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fieldsetJ05'])) {
        InstanceJourney::cleanup($GLOBALS['fieldsetJ05']->work.'/fieldset-test-j05-local', $GLOBALS['fieldsetJ05LocalUuid'] ?? null);
        InstanceJourney::cleanup($GLOBALS['fieldsetJ05']->work.'/fieldset-test-j05-domain', $GLOBALS['fieldsetJ05DomainUuid'] ?? null);
    }
    if (isset($GLOBALS['fieldsetJ05Acme'])) {
        $GLOBALS['fieldsetJ05Acme']->stop();
    }
    unset($GLOBALS['fieldsetJ05'], $GLOBALS['fieldsetJ05Acme'], $GLOBALS['fieldsetJ05Error'], $GLOBALS['fieldsetJ05LocalUuid'], $GLOBALS['fieldsetJ05DomainUuid'], $GLOBALS['fieldsetJ05Port'], $GLOBALS['fieldsetJ05Root'], $GLOBALS['fieldsetJ05Session']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FIELDSET_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fieldsetJ05Error'])) {
        throw $GLOBALS['fieldsetJ05Error'];
    }
    $this->journey = $GLOBALS['fieldsetJ05'];
    $this->acme = $GLOBALS['fieldsetJ05Acme'];
    $this->local = $this->journey->work.'/fieldset-test-j05-local';
    $this->domain = $this->journey->work.'/fieldset-test-j05-domain';
});

/** An address the doctor accepts as this host's: a LAN address the shim reports, else a public one (as in J2). */
function j05HostAddress(): string
{
    $process = new Process(['/bin/sh', '-c', '. "$1"; fieldset_lan_addresses', 'sh', TestImage::repository().'/distribution/shim.sh'], null, ['FIELDSET_SHIM_LIBRARY' => '1']);
    $process->run();

    return array_values(array_filter(preg_split('/\s+/', trim($process->getOutput())) ?: []))[0] ?? '1.1.1.1';
}

/** A client with its own cookie jar: on the local port, or on the edge's 443 with the domain as SNI and Host. */
function j05Client(?int $port = null): Client
{
    $options = ['http_errors' => false, 'allow_redirects' => false, 'timeout' => 30, 'cookies' => new CookieJar];

    return new Client($port !== null ? [...$options, 'base_uri' => "http://localhost:{$port}"] : [
        ...$options, 'base_uri' => 'https://'.J05_DOMAIN, RequestOptions::VERIFY => $GLOBALS['fieldsetJ05Root'] ?? false,
        'curl' => [CURLOPT_RESOLVE => [J05_DOMAIN.':443:127.0.0.1', J05_DOMAIN.':80:127.0.0.1']],
    ]);
}

/** Fortify's headless login as the Field App does it: CSRF cookie, then POST /auth/login with the XSRF header. */
function j05Login(Client $client, string $email, string $password, bool $remember = false): ResponseInterface
{
    $origin = rtrim((string) $client->getConfig('base_uri'), '/');
    $spa = ['Referer' => $origin.'/app', 'Origin' => $origin, 'Accept' => 'application/json'];
    $client->get('/sanctum/csrf-cookie', ['headers' => $spa]);
    $xsrf = $client->getConfig('cookies')->getCookieByName('XSRF-TOKEN')?->getValue() ?? '';

    return $client->post('/auth/login', ['headers' => [...$spa, 'X-XSRF-TOKEN' => urldecode($xsrf)], 'json' => ['email' => $email, 'password' => $password, 'remember' => $remember]]);
}

/** GET /api/v1/me with the client's session: the e-mail of the signed-in user, or null with the status. */
function j05Me(Client $client): array
{
    $origin = rtrim((string) $client->getConfig('base_uri'), '/');
    $response = $client->get('/api/v1/me', ['headers' => ['Referer' => $origin.'/app', 'Origin' => $origin, 'Accept' => 'application/json']]);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)['data']['user']['email'] ?? null];
}

/** @return array<string, string> secret name => value, from a --secrets-file */
function j05Secrets(string $file): array
{
    $document = json_decode((string) @file_get_contents($file), true);

    return array_column(is_array($document) ? ($document['secrets'] ?? []) : [], 'value', 'name');
}

/** `php artisan app:demo-passwords --check --json` inside the instance's app container (U14's check, for real). */
function j05Check(string $uuid): array
{
    $app = trim(j05Docker(['ps', '-q', '--filter', "label=app.fieldset.instance={$uuid}", '--filter', 'label=app.fieldset.role=app'])->getOutput());
    $process = j05Docker(['exec', $app, 'php', 'artisan', 'app:demo-passwords', '--check', '--json']);

    return json_decode(trim($process->getOutput()), true) ?? ['raw' => $process->getOutput().$process->getErrorOutput()];
}

/** @param  list<string>  $command */
function j05Docker(array $command): Process
{
    $process = new Process(['docker', ...$command]);
    $process->setTimeout(300);
    $process->run();

    return $process;
}

/** The number of messages in the instance's catcher, through its loopback UI port with the login from --secrets-file. */
function j05CatcherCount(InstanceJourney $journey, string $dir): ?int
{
    $status = InstanceJourney::json($journey->fieldset($dir, ['status', '--json']));
    $port = (int) parse_url((string) ($status['data']['mail']['catcher_url'] ?? ''), PHP_URL_PORT);
    $file = $journey->work.'/j05-catcher-login.json';
    @unlink($file);
    $journey->fieldset($dir, ['config', 'show', '--catcher-login', '--secrets-file', $file, '--json']);
    $password = (string) (json_decode((string) @file_get_contents($file), true)['secrets'][0]['value'] ?? '');
    @unlink($file);
    $response = new Client(['http_errors' => false, 'timeout' => 10])->get("http://localhost:{$port}/api/v1/messages", ['auth' => ['fieldset', $password]]);
    $json = json_decode((string) $response->getBody(), true);

    return $response->getStatusCode() === 200 && is_array($json) ? (int) ($json['total'] ?? count($json['messages'] ?? [])) : null;
}

/** @return array<string, mixed> the archived journal of the instance's last reset */
function j05ResetJournal(string $dir): array
{
    $files = glob($dir.'/'.OperationJournal::HISTORY_DIR.'/*.json') ?: [];
    $resets = array_values(array_filter(array_map(static fn (string $file): mixed => json_decode((string) file_get_contents($file), true), $files), static fn (mixed $record): bool => is_array($record) && ($record['operation'] ?? null) === 'reset'));

    return $resets === [] ? [] : $resets[array_key_last($resets)];
}

/** @return array<string, mixed> the first attempt of a journal phase */
function j05Phase(array $journal, string $name): array
{
    return array_values(array_filter($journal['phases'] ?? [], static fn (array $phase): bool => $phase['name'] === $name))[0] ?? [];
}

/** @return list<string> regular files on the app's storage volume, the image's placeholder files left out */
function j05StorageFiles(string $uuid): array
{
    $listing = j05Docker(['exec', RestoreJourney::container($uuid, 'app'), 'find', '/var/www/html/storage/app', '-type', 'f'])->getOutput();

    return array_values(array_filter(explode("\n", trim($listing)), static fn (string $path): bool => $path !== '' && basename($path) !== '.'.'gitignore'));
}

it('installs a loopback-only local demo whose users sign in with password', function (): void {
    $link = $this->journey->work.'/j05-owner-link.json';
    $started = microtime(true);
    $process = $this->journey->install($this->local, ['--org', 'Demo', '--owner-email', 'owner@example.test', '--locale', 'en', '--demo', '--secrets-file', $link, '--json']);
    $seconds = round(microtime(true) - $started, 1);
    $json = InstanceJourney::json($process);
    $GLOBALS['fieldsetJ05LocalUuid'] = InstanceJourney::uuid($this->local);
    $GLOBALS['fieldsetJ05Port'] = $json['data']['port'] ?? null;
    $client = j05Client($GLOBALS['fieldsetJ05Port']);
    $login = j05Login($client, 'worker@demo.test', 'password');
    $me = j05Me($client);
    $this->journey->evidence('J05-local-install.json', ['command' => 'install.sh --dir <work>/fieldset-test-j05-local --version '.ReleaseHarness::version().' --org Demo --owner-email owner@example.test --locale en --demo --secrets-file <work>/j05-owner-link.json --json', 'exit_code' => $process->getExitCode(), 'seconds' => $seconds, 'stdout' => $json, 'login_with_password' => [$login->getStatusCode(), json_decode((string) $login->getBody(), true)], 'me' => $me]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data']['demo'])->toMatchArray(['organization' => 'Demo Haustechnik GmbH', 'seeded' => true, 'passwords' => 'default', 'default_password' => 'password', 'exposure' => [], 'delivered' => 'data'])
        ->and(array_column($json['data']['demo']['users'], 'email'))->toBe(J05_DEMO_USERS)
        ->and(array_keys(j05Secrets($link)))->toBe(['sign_in_link'])
        ->and($login->getStatusCode())->toBe(200, (string) $login->getBody())
        ->and($me)->toBe([200, 'worker@demo.test']);
});

it('finds all nine demo accounts on password and the owner\'s organization "Demo" as a real one with the app-side check', function (): void {
    $check = j05Check($GLOBALS['fieldsetJ05LocalUuid']);
    $this->journey->evidence('J05-local-check.json', ['command' => 'docker exec <app> php artisan app:demo-passwords --check --json', 'stdout' => $check]);

    expect($check['status'] ?? null)->toBe('ok', json_encode($check))
        ->and($check['data']['count'])->toBe(9)
        ->and(array_column($check['data']['users'], 'email'))->toEqualCanonicalizing(J05_DEMO_USERS)
        ->and(array_unique(array_column($check['data']['users'], 'demo')))->toBe([true])
        ->and($check['data']['organizations'])->toBe(['total' => 2, 'non_demo' => 1]);
});

it('changes no demo data with fieldset demo on the seeded instance and reports the new demo owner link, which signs in', function (): void {
    $file = $this->journey->work.'/j05-demo-link.json';
    $process = $this->journey->fieldset($this->local, ['demo', '--secrets-file', $file, '--json']);
    $json = InstanceJourney::json($process);
    [, $token] = explode('#', j05Secrets($file)['sign_in_link'] ?? '#', 2);
    $client = j05Client($GLOBALS['fieldsetJ05Port']);
    preg_match('/name="_token" value="([^"]+)"/', (string) $client->get('/login-link')->getBody(), $csrf);
    $consumed = $client->post('/login-link', ['form_params' => ['_token' => $csrf[1] ?? '', 'token' => $token]]);
    $this->journey->evidence('J05-local-demo.json', ['command' => 'fieldset demo --secrets-file <work>/j05-demo-link.json --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'link_consumed' => [$consumed->getStatusCode(), $consumed->getHeaderLine('Location')]]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($json['changed'])->toBeTrue()
        ->and(array_column($json['warnings'], 'code'))->toBe(['sign_in_link_issued'])
        ->and($json['data']['demo'])->toMatchArray(['seeded' => false, 'passwords' => 'default', 'default_password_users' => 9])
        ->and($json['data']['sign_in_link']['delivered'])->toBe('secrets_file')
        ->and($process->getOutput())->not->toContain('/login-link#')
        ->and($token)->not->toBe('')
        ->and($consumed->getStatusCode())->toBe(303)
        ->and($consumed->getHeaderLine('Location'))->toContain('/admin')
        ->and($consumed->getHeaderLine('Location'))->not->toContain($token);
});

it('refuses config set domain while the demo accounts sign in with password: exit 3 in the preflight, nothing changed', function (): void {
    $state = (string) file_get_contents($this->local.'/state.json');
    $started = microtime(true);
    $process = $this->journey->fieldset($this->local, ['config', 'set', 'domain', J05_DOMAIN, '--yes', '--json']);
    $seconds = round(microtime(true) - $started, 1);
    $json = InstanceJourney::json($process);
    $client = j05Client($GLOBALS['fieldsetJ05Port']);
    $login = j05Login($client, 'viewer@demo.test', 'password');
    $this->journey->evidence('J05-local-config-domain-refused.json', ['command' => 'fieldset config set domain '.J05_DOMAIN.' --yes --json', 'exit_code' => $process->getExitCode(), 'seconds' => $seconds, 'stdout' => $json, 'state_unchanged' => $state === file_get_contents($this->local.'/state.json'), 'login_with_password_on_loopback' => $login->getStatusCode()]);

    expect($process->getExitCode())->toBe(3, $process->getErrorOutput().$process->getOutput())
        ->and($json['errors'][0]['code'])->toBe('default_password_in_use')
        ->and($json['errors'][0]['hint'])->toContain('fieldset demo --rotate-passwords')
        ->and($json['data']['users'])->toEqualCanonicalizing(J05_DEMO_USERS)
        ->and(file_get_contents($this->local.'/state.json'))->toBe($state)
        ->and(file_exists($this->local.'/'.OperationJournal::CURRENT))->toBeFalse()
        ->and($login->getStatusCode())->toBe(200, (string) $login->getBody())
        ->and(j05Me($client))->toBe([200, 'viewer@demo.test']);
});

it('rotates the demo passwords: password and a remembered session stop working, the printed passwords work', function (): void {
    $session = j05Client($GLOBALS['fieldsetJ05Port']);
    $before = j05Login($session, 'mobile@demo.test', 'password', remember: true);
    $meBefore = j05Me($session);
    $file = $this->journey->work.'/j05-rotated.json';
    $process = $this->journey->fieldset($this->local, ['demo', '--rotate-passwords', '--secrets-file', $file, '--json']);
    $json = InstanceJourney::json($process);
    $passwords = j05Secrets($file);
    $meAfter = j05Me($session);
    $old = j05Login(j05Client($GLOBALS['fieldsetJ05Port']), 'mobile@demo.test', 'password');
    $fresh = j05Client($GLOBALS['fieldsetJ05Port']);
    $new = j05Login($fresh, 'mobile@demo.test', $passwords['demo_password:mobile@demo.test'] ?? '');
    $check = j05Check($GLOBALS['fieldsetJ05LocalUuid']);
    $this->journey->evidence('J05-local-rotate.json', [
        'command' => 'fieldset demo --rotate-passwords --secrets-file <work>/j05-rotated.json --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json,
        'session_before' => [$before->getStatusCode(), $meBefore], 'session_after' => $meAfter, 'old_password' => $old->getStatusCode(), 'new_password' => [$new->getStatusCode(), j05Me($fresh)],
        'check_after' => $check['data'] ?? $check,
    ]);

    expect($before->getStatusCode())->toBe(200, (string) $before->getBody())
        ->and($meBefore)->toBe([200, 'mobile@demo.test'])
        ->and($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($json['changed'])->toBeTrue()
        ->and($json['data']['demo'])->toMatchArray(['rotated' => true, 'passwords' => 'random', 'delivered' => 'secrets_file'])
        ->and($json['data']['demo']['sessions_ended'])->toBeGreaterThanOrEqual(1)
        ->and(array_keys($passwords))->toBe(array_map(static fn (string $email): string => 'demo_password:'.$email, J05_DEMO_USERS))
        ->and($process->getOutput())->not->toContain($passwords['demo_password:mobile@demo.test'] ?? 'missing')
        ->and($meAfter[0])->toBe(401)
        ->and($old->getStatusCode())->toBe(422)
        ->and($new->getStatusCode())->toBe(200, (string) $new->getBody())
        ->and($check['data']['count'] ?? null)->toBe(0);
});

it('resets the local demo: install org and owner only, empty storage and catcher, a kept safety backup, the ledger before the drop, instance.reset with the old heads', function (): void {
    $uuid = $GLOBALS['fieldsetJ05LocalUuid'];
    j05Docker(['exec', RestoreJourney::container($uuid, 'app'), 'touch', '/var/www/html/storage/app/fieldset-test-j05-planted.txt']);
    $before = [
        'catcher' => j05CatcherCount($this->journey, $this->local), 'volume' => RestoreJourney::storageVolume($uuid), 'files' => j05StorageFiles($uuid),
        'organizations' => RestoreJourney::sql($uuid, 'SELECT count(*) FROM organizations'), 'users' => RestoreJourney::sql($uuid, 'SELECT count(*) FROM users'),
        'max_ids' => ['users' => (int) RestoreJourney::sql($uuid, 'SELECT max(id) FROM users'), 'activity_log' => (int) RestoreJourney::sql($uuid, 'SELECT max(id) FROM activity_log')],
    ];
    $file = $this->journey->work.'/j05-reset-link.json';
    $started = microtime(true);
    $process = $this->journey->fieldset($this->local, ['reset', '--yes', '--secrets-file', $file, '--json']);
    $seconds = round(microtime(true) - $started, 1);
    $json = InstanceJourney::json($process);
    $journal = j05ResetJournal($this->local);
    $ledgerLines = file($this->local.'/backups/ledger.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $ledger = json_decode((string) end($ledgerLines), true);
    $first = json_decode(RestoreJourney::sql($uuid, 'SELECT row_to_json(a) FROM (SELECT id, event, organization_id, properties FROM activity_log ORDER BY id LIMIT 1) a'), true);
    $epoch = json_decode(RestoreJourney::sql($uuid, 'SELECT row_to_json(e) FROM (SELECT epoch, reason, previous_heads FROM audit_chain_epochs ORDER BY epoch DESC LIMIT 1) e'), true);
    $safety = (string) ($json['data']['safety_backup'] ?? '');
    $entries = array_values(array_filter(array_map(static fn (string $path): ?BackupEntry => ($name = BackupName::parse(basename($path))) instanceof BackupName ? new BackupEntry($name, 1, false) : null, glob($this->local.'/backups/*') ?: [])));
    $scheduled = array_map(static fn (int $day): BackupEntry => new BackupEntry(BackupName::for('scheduled', CarbonImmutable::now('UTC')->addDays($day)), 1, false), range(1, 3));
    $pruned = array_map(static fn (BackupEntry $entry): string => $entry->name->name(), BackupRetention::prune([...$entries, ...$scheduled], 1));
    [, $token] = explode('#', j05Secrets($file)['sign_in_link'] ?? '#', 2);
    $client = j05Client($GLOBALS['fieldsetJ05Port']);
    preg_match('/name="_token" value="([^"]+)"/', (string) $client->get('/login-link')->getBody(), $csrf);
    $consumed = $client->post('/login-link', ['form_params' => ['_token' => $csrf[1] ?? '', 'token' => $token]]);
    $demoLogin = j05Login(j05Client($GLOBALS['fieldsetJ05Port']), 'worker@demo.test', 'password');
    $after = [
        'catcher' => j05CatcherCount($this->journey, $this->local), 'volume' => RestoreJourney::storageVolume($uuid), 'files' => j05StorageFiles($uuid),
        'old_volume_left' => in_array($before['volume'], explode("\n", trim(j05Docker(['volume', 'ls', '-q', '--filter', 'name='.$before['volume']])->getOutput())), true),
        'organizations' => RestoreJourney::sql($uuid, "SELECT string_agg(name, ',') FROM organizations"), 'users' => RestoreJourney::sql($uuid, "SELECT string_agg(email, ',') FROM users"),
        'first_entry' => $first, 'epoch' => $epoch, 'min_user_id' => (int) RestoreJourney::sql($uuid, 'SELECT min(id) FROM users'), 'safety_mode' => $safety === '' ? null : decoct(fileperms($this->local.'/backups/'.$safety) & 0777), 'pruned_with_retention_1' => $pruned,
    ];
    $this->journey->evidence('J05-local-reset.json', [
        'command' => 'fieldset reset --yes --secrets-file <work>/j05-reset-link.json --json', 'exit_code' => $process->getExitCode(), 'seconds' => $seconds, 'stdout' => $json,
        'before' => $before, 'after' => $after, 'ledger' => $ledger, 'phases' => array_map(static fn (array $phase): array => [$phase['name'], $phase['started_at'], $phase['finished_at']], $journal['phases'] ?? []),
        'link_consumed' => [$consumed->getStatusCode(), $consumed->getHeaderLine('Location')], 'demo_login_after' => $demoLogin->getStatusCode(),
    ]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($before['files'])->toContain('/var/www/html/storage/app/fieldset-test-j05-planted.txt')
        ->and($before['catcher'])->toBeGreaterThan(0)->and((int) $before['users'])->toBeGreaterThan(1)
        ->and($json['data'])->toMatchArray(['organization' => 'Demo', 'owner_email' => 'owner@example.test', 'audit_export' => null, 'flushed' => ['valkey' => true, 'catcher' => true], 'left_behind' => []])
        ->and($safety)->toStartWith('safety-reset-')
        ->and($after['organizations'])->toBe('Demo')
        ->and($after['users'])->toBe('owner@example.test')
        ->and($demoLogin->getStatusCode())->toBe(422)
        ->and($consumed->getStatusCode())->toBe(303)->and($consumed->getHeaderLine('Location'))->toContain('/admin')
        ->and($after['files'])->toBe([])
        ->and($after['volume'])->toStartWith($before['volume'].'-reset-')
        ->and($after['old_volume_left'])->toBeFalse()
        ->and($after['catcher'])->toBe(0)
        ->and(is_file($this->local.'/backups/'.$safety.'/SHA256SUMS'))->toBeTrue()
        ->and($after['safety_mode'])->toBe('700')
        ->and($pruned)->not->toContain($safety)->and($pruned)->not->toBe([])
        ->and($ledger)->toMatchArray(['action' => 'reset', 'journal' => $journal['id'] ?? null, 'no_backup' => false])
        ->and($ledger['heads'])->not->toBe([])
        ->and($ledger['at'] <= j05Phase($journal, 'recreate_database')['started_at'])->toBeTrue()
        ->and(j05Phase($journal, 'quiesce')['finished_at'] <= $ledger['at'])->toBeTrue()
        ->and($after['min_user_id'])->toBeGreaterThan($before['max_ids']['users']) // review S1: no ID handed out twice
        ->and($first['id'])->toBeGreaterThan($before['max_ids']['activity_log'])
        ->and($first['event'])->toBe('instance.reset')
        ->and($first['organization_id'])->toBeNull()
        ->and($first['properties']['previous_heads'])->toBe($ledger['heads'])
        ->and($first['properties'])->toMatchArray(['epoch' => $ledger['epoch'] + 1, 'previous_epoch' => $ledger['epoch'], 'safety_backup' => $safety])
        ->and($epoch)->toMatchArray(['epoch' => $ledger['epoch'] + 1, 'reason' => 'reset'])
        ->and($epoch['previous_heads'])->toBe($ledger['heads'])
        ->and($json['data']['epoch'])->toBe($ledger['epoch'] + 1);
});

it('uninstalls the local demo without leaving anything labelled behind', function (): void {
    $process = $this->journey->fieldset($this->local, ['uninstall', '--yes', '--json']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(InstanceJourney::labelled($GLOBALS['fieldsetJ05LocalUuid']))->toBe(['containers' => [], 'volumes' => [], 'networks' => []]);
});

it('installs a domain-mode demo: password is refused and every demo user signs in with its printed random password', function (): void {
    $secrets = $this->journey->work.'/j05-domain-secrets.json';
    $flags = ['--org', 'J5 Domain Org', '--owner-email', 'owner@example.test', '--locale', 'en', '--domain', J05_DOMAIN, '--acme-ca', AcmeHarness::DIRECTORY_URL, '--acme-ca-root-file', $this->acme->httpsRoot, '--demo', '--secrets-file', $secrets, '--json'];
    $started = microtime(true);
    $process = $this->journey->installProcess($this->domain, $flags, ['FIELDSET_TEST_DNS' => J05_DOMAIN.'='.j05HostAddress()]);
    $process->start();
    $connected = false;
    while ($process->isRunning()) {
        $uuid = InstanceJourney::uuid($this->domain);
        $edge = $uuid === null || $connected ? '' : trim(j05Docker(['ps', '-aq', '--filter', 'label=app.fieldset.role=edge', '--filter', "label=app.fieldset.instance={$uuid}"])->getOutput());
        if ($edge !== '') {
            $this->acme->connectEdge($edge);
            $connected = true;
        }
        usleep(200_000);
    }
    $seconds = round(microtime(true) - $started, 1);
    $json = InstanceJourney::json($process);
    $GLOBALS['fieldsetJ05DomainUuid'] = InstanceJourney::uuid($this->domain);
    $root = $this->journey->work.'/j05-pebble-root.pem';
    file_put_contents($root, $process->isSuccessful() ? $this->acme->issuingRoot() : '');
    $GLOBALS['fieldsetJ05Root'] = $root;
    $passwords = j05Secrets($secrets);
    $refused = j05Login(j05Client(), 'owner@demo.test', 'password');
    $logins = [];
    foreach (J05_DEMO_USERS as $email) {
        $client = j05Client();
        $logins[$email] = [j05Login($client, $email, $passwords['demo_password:'.$email] ?? '')->getStatusCode(), j05Me($client)[1]];
    }
    $this->journey->evidence('J05-domain-install.json', [
        'command' => 'install.sh --dir <work>/fieldset-test-j05-domain --version '.ReleaseHarness::version().' --org "J5 Domain Org" --owner-email owner@example.test --locale en --domain '.J05_DOMAIN.' --acme-ca '.AcmeHarness::DIRECTORY_URL.' --acme-ca-root-file <harness>/pebble-https-root.pem --demo --secrets-file <work>/j05-domain-secrets.json --json',
        'exit_code' => $process->getExitCode(), 'seconds' => $seconds, 'stdout' => $json, 'secret_names' => array_keys($passwords),
        'login_with_password' => $refused->getStatusCode(), 'logins_with_printed_passwords' => $logins,
    ]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput()."\n".$this->acme->logs())
        ->and($json['data'])->toMatchArray(['url' => 'https://'.J05_DOMAIN, 'mode' => 'domain'])
        ->and($json['data']['demo'])->toMatchArray(['seeded' => true, 'passwords' => 'random', 'exposure' => ['domain_mode'], 'delivered' => 'secrets_file'])
        ->and($json['data']['demo'])->not->toHaveKey('default_password')
        ->and(array_keys($passwords))->toBe(['sign_in_link', ...array_map(static fn (string $email): string => 'demo_password:'.$email, J05_DEMO_USERS)])
        ->and(array_map(strlen(...), array_values(array_slice($passwords, 1))))->each->toBe(24)
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain($passwords['demo_password:owner@demo.test'] ?? 'missing')
        ->and($refused->getStatusCode())->toBe(422)
        ->and($logins)->toBe(array_combine(J05_DEMO_USERS, array_map(static fn (string $email): array => [200, $email], J05_DEMO_USERS)));
});

it('resets the domain demo with --demo --no-backup: an audit export instead of a backup, random demo passwords that sign in', function (): void {
    $uuid = $GLOBALS['fieldsetJ05DomainUuid'];
    $secrets = $this->journey->work.'/j05-domain-reset.json';
    $started = microtime(true);
    $process = $this->journey->fieldset($this->domain, ['reset', '--demo', '--no-backup', '--yes', '--secrets-file', $secrets, '--json'], ['FIELDSET_TEST_DNS' => J05_DOMAIN.'='.j05HostAddress()]);
    $seconds = round(microtime(true) - $started, 1);
    $json = InstanceJourney::json($process);
    $passwords = j05Secrets($secrets);
    $export = (string) ($json['data']['audit_export'] ?? '');
    $path = $this->domain.'/backups/'.$export;
    $header = $export === '' ? null : json_decode((string) strtok((string) @file_get_contents($path), "\n"), true);
    $refused = j05Login(j05Client(), 'owner@demo.test', 'password');
    $client = j05Client();
    $login = j05Login($client, 'designer@demo.test', $passwords['demo_password:designer@demo.test'] ?? '');
    $me = j05Me($client);
    $organizations = RestoreJourney::sql($uuid, "SELECT string_agg(name, ',' ORDER BY id) FROM organizations");
    $this->journey->evidence('J05-domain-reset.json', [
        'command' => 'fieldset reset --demo --no-backup --yes --secrets-file <work>/j05-domain-reset.json --json', 'exit_code' => $process->getExitCode(), 'seconds' => $seconds, 'stdout' => $json,
        'secret_names' => array_keys($passwords), 'export_header' => is_array($header) ? array_diff_key($header, ['heads' => true, 'epochs' => true]) : $header,
        'export_mode' => $export === '' ? null : decoct(fileperms($path) & 0777), 'safety_backups' => array_map(basename(...), glob($this->domain.'/backups/safety-*') ?: []),
        'organizations' => $organizations, 'login_with_password' => $refused->getStatusCode(), 'login_with_printed_password' => [$login->getStatusCode(), $me],
    ]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data'])->toMatchArray(['organization' => 'J5 Domain Org', 'safety_backup' => null])
        ->and($export)->toMatch('/^audit-export-reset-\d{8}T\d{6}Z\.jsonl$/')
        ->and($header)->toMatchArray(['format' => 'fieldset-audit-export', 'version' => '1.0'])
        ->and($header['entries'])->toBeGreaterThan(0)
        ->and(decoct(fileperms($path) & 0777))->toBe('600')
        ->and(glob($this->domain.'/backups/safety-*') ?: [])->toBe([])
        ->and(collect($json['warnings'])->pluck('code')->all())->toContain('no_backup')
        ->and($json['data']['demo'])->toMatchArray(['seeded' => true, 'passwords' => 'random', 'exposure' => ['domain_mode'], 'delivered' => 'secrets_file'])
        ->and(array_keys($passwords))->toBe(['sign_in_link', ...array_map(static fn (string $email): string => 'demo_password:'.$email, J05_DEMO_USERS)])
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain($passwords['demo_password:designer@demo.test'] ?? 'missing')
        ->and($organizations)->toBe('J5 Domain Org,Demo Haustechnik GmbH')
        ->and($refused->getStatusCode())->toBe(422)
        ->and($login->getStatusCode())->toBe(200, (string) $login->getBody())
        ->and($me)->toBe([200, 'designer@demo.test']);
});

it('uninstalls the domain demo without leaving anything labelled behind', function (): void {
    $process = $this->journey->fieldset($this->domain, ['uninstall', '--yes', '--json'], ['FIELDSET_TEST_DNS' => J05_DOMAIN.'='.j05HostAddress()]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and(InstanceJourney::labelled($GLOBALS['fieldsetJ05DomainUuid']))->toBe(['containers' => [], 'volumes' => [], 'networks' => []]);
});
