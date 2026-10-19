<?php

declare(strict_types=1);

use App\Domain\Instance\Adopt\DatabaseProbe;
use App\Domain\Instance\Restore\BackupSet;
use Symfony\Component\Process\Process;
use Tests\Support\Docker\InstanceJourney;
use Tests\Support\Docker\SharedHarness;
use Tests\Support\Docker\TestImage;
use Tests\Support\Docker\V1Install;

/*
 * J9 (BUILD_PROMPT_V2 §11, R46 as changed by R60, F7, KTD20; plan U19; ADR 0087), with real Docker: a 1.0.0 install
 * built from the local tag `v1.0.0` and started as its install guide says (V1Install), with demo data and a file in its
 * storage; `install.sh --adopt <checkout>` of the harness release while a writer keeps creating submissions through
 * 1.0's app until adopt stops it. Then:
 *
 * - every write acknowledged before the stop is in 2.0, and 2.0 holds exactly what the 1.0-format backup holds
 *   (submissions, users); each 1.0 audit chain head is in 2.0 with its hash and `audit:verify` passes;
 * - the unique text indexes (emails, slugs) pass amcheck (`bt_index_check` with heapallindexed) on the copy;
 * - 1.0's volumes still exist unlabelled and its containers are stopped; the backup restores in a drill;
 * - the adopt's 1.0 backup restores in place (`--accept-unauthenticated`): the upgrade child migrates it to this release
 *   (journal phase `upgrade_child`), the data and the stored file are the backup's, the audit epoch goes up with one
 *   `instance.restored` entry, `audit:verify` passes and the instance is healthy (review EV2);
 * - `fsa uninstall` takes a final backup and reports the writes made since adopt; `docker compose up -d` in the checkout
 *   brings 1.0 back with its pre-adopt data.
 *
 * Everything is named `fsa-test-j09…` (the 1.0 image `<FSA_TEST_IMAGE_REPOSITORY>-v1:1.0.0`) and removed afterwards.
 */

beforeAll(function (): void {
    if (TestImage::dockerUnavailableReason() !== null) {
        return;
    }

    try {
        [$harness, $release] = SharedHarness::get();
        $journey = new InstanceJourney($harness, $release, $harness->directory.'/j09');
        $v1 = V1Install::prepare($journey->work, bin2hex(random_bytes(3)));
        $GLOBALS['fsaJ09'] = $journey;
        $GLOBALS['fsaJ09V1'] = $v1;
        $v1->build();
    } catch (Throwable $exception) {
        $GLOBALS['fsaJ09Error'] = $exception;
    }
});

afterAll(function (): void {
    if (isset($GLOBALS['fsaJ09'])) {
        InstanceJourney::cleanup($GLOBALS['fsaJ09']->work.'/fsa-test-j09', $GLOBALS['fsaJ09Uuid'] ?? null);
    }
    $GLOBALS['fsaJ09V1']?->remove();
    unset($GLOBALS['fsaJ09'], $GLOBALS['fsaJ09V1'], $GLOBALS['fsaJ09Error'], $GLOBALS['fsaJ09Uuid'], $GLOBALS['fsaJ09Before'], $GLOBALS['fsaJ09Adopt'], $GLOBALS['fsaJ09Acked']);
});

beforeEach(function (): void {
    $reason = TestImage::dockerUnavailableReason();
    if ($reason !== null) {
        expect(getenv('FSA_REQUIRE_DOCKER'))->not->toBe('1', $reason);
        $this->markTestSkipped($reason.' (install journeys need a Docker engine)');
    }
    if (isset($GLOBALS['fsaJ09Error'])) {
        throw $GLOBALS['fsaJ09Error'];
    }
    $this->journey = $GLOBALS['fsaJ09'];
    $this->v1 = $GLOBALS['fsaJ09V1'];
    $this->dir = $this->journey->work.'/fsa-test-j09';
});

/** The instance's container of a role. */
function j09Container(string $uuid, string $role): string
{
    $process = new Process(['docker', 'ps', '-q', '--filter', "label=app.form-schema.instance={$uuid}", '--filter', "label=app.form-schema.role={$role}", '--filter', 'label=com.docker.compose.oneoff=False']);
    $process->run();

    return trim($process->getOutput());
}

/** @return array{status: int, body: string} */
function j09Get(int $port, string $path): array
{
    $response = InstanceJourney::http($port)->get($path);

    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
}

it('runs a 1.0.0 install built from the tag, with demo data and a stored file', function (): void {
    // The app first: Docker races when app, queue and scheduler copy the image's storage/app into the new app-storage
    // volume at once ("failed to mkdir …/_data/private: file exists"); the rest then starts as the install guide says.
    $first = $this->v1->compose(['up', '-d', '--wait', 'app']);
    $up = $first->isSuccessful() ? $this->v1->compose(['up', '-d', '--wait']) : $first;
    if (! $up->isSuccessful()) {
        $logs = $this->v1->compose(['logs', '--no-color', '--tail', '40']);
        expect($up->getExitCode())->toBe(0, $up->getErrorOutput()."\n".$logs->getOutput());
    }
    $seed = $this->v1->artisan(['db:seed', '--class=Database\\Seeders\\DemoSeeder', '--force']);
    $load = $this->v1->artisan(['app:seed-load', 'demo', '25', '--force']);
    $operator = $this->v1->artisan(['app:platform-admin', 'owner@demo.test']); // as the 1.0 install guide grants operators
    $file = new Process(['docker', 'exec', '-u', 'www-data', $this->v1->container('app'), 'sh', '-c', 'mkdir -p storage/app/private/fsa-test-j09 && printf adopted > storage/app/private/fsa-test-j09/file.txt']);
    $file->run();
    $GLOBALS['fsaJ09Before'] = V1Install::data($this->v1->container('postgres'));

    expect($up->getExitCode())->toBe(0, $up->getErrorOutput())
        ->and($seed->getExitCode())->toBe(0, $seed->getErrorOutput().$seed->getOutput())
        ->and($load->getExitCode())->toBe(0, $load->getErrorOutput().$load->getOutput())
        ->and($operator->getExitCode())->toBe(0, $operator->getErrorOutput().$operator->getOutput())
        ->and($file->getExitCode())->toBe(0, $file->getErrorOutput())
        ->and(j09Get($this->v1->port, '/up')['status'])->toBe(200)
        ->and($GLOBALS['fsaJ09Before']['submissions'])->toBeGreaterThanOrEqual(25)
        ->and($GLOBALS['fsaJ09Before']['heads'])->not->toBeEmpty();
});

it('adopts it with install.sh --adopt while writes go on until 1.0 stops: every acknowledged write is in 2.0', function (): void {
    $before = $GLOBALS['fsaJ09Before'];
    $started = microtime(true);
    $adopt = $this->journey->installProcess($this->dir, ['--adopt', $this->v1->checkout, '--yes', '--json']);
    $adopt->start();
    $acked = 0;
    while ($adopt->isRunning()) {
        $write = $this->v1->artisan(['app:seed-load', 'demo', '2', '--chunk=2', '--force'], 120);
        if (! $write->isSuccessful()) {
            break; // 1.0's app is stopped: no write is acknowledged after this point
        }
        $acked += 2;
    }
    $adopt->wait();
    $json = InstanceJourney::json($adopt);
    $GLOBALS['fsaJ09Uuid'] = InstanceJourney::uuid($this->dir);
    $GLOBALS['fsaJ09Adopt'] = $json;
    $GLOBALS['fsaJ09Acked'] = $acked;
    $backup = (string) ($json['data']['adopted']['backup_path'] ?? '');
    $manifest = BackupSet::legacyManifest($backup);
    $after = V1Install::data(j09Container((string) $GLOBALS['fsaJ09Uuid'], 'postgres'));
    $this->journey->evidence('J09-adopt.json', ['command' => 'install.sh --dir <work>/fsa-test-j09 --adopt <work>/fsa-test-j09-v1-<id> --yes --json', 'exit_code' => $adopt->getExitCode(),
        'seconds' => round(microtime(true) - $started, 1), 'stdout' => $json, 'acknowledged_writes' => $acked, 'before_adopt' => $before, 'backup_manifest' => $manifest, 'instance' => $after]);

    expect($adopt->getExitCode())->toBe(0, $adopt->getErrorOutput()."\n".$adopt->getOutput())
        ->and($json['data'])->toMatchArray(['mode' => 'local', 'url' => 'http://localhost:'.$this->v1->port, 'port' => $this->v1->port])
        ->and($json['data']['adopted'])->toMatchArray(['project' => $this->v1->project, 'version' => '1.0.0'])
        ->and($acked)->toBeGreaterThan(0)
        ->and($after['submissions'])->toBeGreaterThanOrEqual($before['submissions'] + $acked)
        ->and($after['submissions'])->toBe((int) ($manifest['submissions'] ?? -1))
        ->and($after['users'])->toBe((int) ($manifest['users'] ?? -1))
        ->and($after['users'])->toBe($before['users']);
    foreach ($before['heads'] as $chain => $head) {
        $hash = V1Install::sql(j09Container((string) $GLOBALS['fsaJ09Uuid'], 'postgres'), "SELECT hash FROM activity_log WHERE COALESCE(organization_id, 0) = {$chain} AND chain_seq = {$head['seq']};");
        expect($hash)->toBe($head['hash'], "audit chain {$chain}");
    }
});

it('kept the APP key, forced the safe values, and serves the copied data in 2.0 with intact audit chains', function (): void {
    $uuid = (string) $GLOBALS['fsaJ09Uuid'];
    $app = (string) file_get_contents($this->dir.'/env/app.env');
    $verify = new Process(['docker', 'exec', j09Container($uuid, 'app'), 'php', 'artisan', 'audit:verify']);
    $verify->setTimeout(600);
    $verify->run();
    $file = new Process(['docker', 'exec', j09Container($uuid, 'app'), 'cat', 'storage/app/private/fsa-test-j09/file.txt']);
    $file->run();
    $link = $this->journey->fsa($this->dir, ['login-link', 'owner@demo.test', '--secrets-file', $this->journey->work.'/j09-link.json', '--json']);

    expect($app)->toContain("APP_KEY='{$this->v1->appKey}'")
        ->and($app)->toContain("APP_DEBUG='false'")->and($app)->toContain("SSO_ALLOW_INSECURE_IDP='false'")
        ->and($app)->not->toContain('TRUSTED_PROXIES=\'*\'')
        ->and($verify->getExitCode())->toBe(0, $verify->getOutput().$verify->getErrorOutput())
        ->and($file->getOutput())->toBe('adopted')
        ->and(j09Get($this->v1->port, '/up')['status'])->toBe(200)
        ->and($link->getExitCode())->toBe(0, $link->getOutput());
});

it('passes amcheck on the unique text indexes (emails, slugs) of the copy', function (): void {
    $process = new Process(['docker', 'exec', '-i', j09Container((string) $GLOBALS['fsaJ09Uuid'], 'postgres'), 'psql', '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1', '-U', 'form_schema', '-d', 'form_schema', '-f', '-']);
    $process->setInput(DatabaseProbe::AMCHECK);
    $process->setTimeout(600);
    $process->run();
    preg_match_all('/fsa-amcheck ok (\S+)/', $process->getErrorOutput(), $ok);
    $this->journey->evidence('J09-amcheck.json', ['exit_code' => $process->getExitCode(), 'checked' => $ok[1], 'failed' => substr_count($process->getErrorOutput(), 'fsa-amcheck failed'),
        'adopt_amcheck' => $GLOBALS['fsaJ09Adopt']['data']['adopted']['verified']['amcheck'] ?? null]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getErrorOutput())->not->toContain('fsa-amcheck failed')
        ->and($ok[1])->toContain('users_email_unique')
        ->and(array_filter($ok[1], static fn (string $index): bool => str_contains($index, 'slug')))->not->toBeEmpty()
        ->and(V1Install::sql(j09Container((string) $GLOBALS['fsaJ09Uuid'], 'postgres'), "SELECT count(*) FROM pg_extension WHERE extname = 'amcheck';"))->toBe('0');
});

it('left 1.0\'s volumes untouched and stopped, and its backup restores in a drill', function (): void {
    $volumes = new Process(['docker', 'volume', 'ls', '--filter', 'label=com.docker.compose.project='.$this->v1->project, '--format', '{{.Name}} {{.Label "app.form-schema.instance"}}']);
    $volumes->run();
    $state = new Process(['docker', 'inspect', '-f', '{{.State.Status}}', $this->v1->container('app'), $this->v1->container('postgres')]);
    $state->run();
    $backup = (string) ($GLOBALS['fsaJ09Adopt']['data']['adopted']['backup'] ?? '');
    $drill = $this->journey->fsa($this->dir, ['restore', $backup, '--drill', '--accept-unauthenticated', '--json']);
    $drillJson = InstanceJourney::json($drill);
    $this->journey->evidence('J09-drill.json', ['command' => 'fsa restore '.$backup.' --drill --accept-unauthenticated --json', 'exit_code' => $drill->getExitCode(), 'stdout' => $drillJson]);

    expect(array_map(trim(...), explode("\n", trim($volumes->getOutput()))))->toEqualCanonicalizing([$this->v1->project.'_pg-data', $this->v1->project.'_app-storage'])
        ->and(array_unique(explode("\n", trim($state->getOutput()))))->toBe(['exited'])
        ->and((string) file_get_contents($this->dir.'/backups/'.$backup.'/env'))->not->toContain($this->v1->appKey) // review S1
        ->and($drill->getExitCode())->toBe(0, $drill->getErrorOutput()."\n".$drill->getOutput());
});

it('restores the adopt\'s 1.0 backup in place through the upgrade child: its data, a new epoch, a healthy instance', function (): void {
    $uuid = (string) $GLOBALS['fsaJ09Uuid'];
    $adopted = $GLOBALS['fsaJ09Adopt']['data']['adopted'] ?? [];
    $backup = (string) ($adopted['backup'] ?? '');
    $postgres = j09Container($uuid, 'postgres');
    $epochBefore = V1Install::sql($postgres, 'SELECT coalesce(max(epoch), 1) FROM audit_chain_epochs;'); // no row yet: epoch 1 (adopt starts none)
    $process = $this->journey->fsa($this->dir, ['restore', $backup, '--accept-unauthenticated', '--yes', '--json']);
    $json = InstanceJourney::json($process);
    $postgres = j09Container($uuid, 'postgres');
    $after = V1Install::data($postgres);
    $verify = new Process(['docker', 'exec', j09Container($uuid, 'app'), 'php', 'artisan', 'audit:verify']);
    $verify->setTimeout(600);
    $verify->run();
    $file = new Process(['docker', 'exec', j09Container($uuid, 'app'), 'cat', 'storage/app/private/fsa-test-j09/file.txt']);
    $file->run();
    $journals = glob($this->dir.'/operations/history/*-restore-*.json') ?: [];
    $journal = json_decode((string) file_get_contents((string) end($journals)), true);
    $child = array_values(array_filter($journal['phases'] ?? [], static fn (array $phase): bool => $phase['name'] === 'upgrade_child' && $phase['status'] === 'completed'));
    $checks = ['epoch_before' => $epochBefore, 'epoch' => V1Install::sql($postgres, 'SELECT max(epoch) FROM audit_chain_epochs;'),
        'restored_entries' => V1Install::sql($postgres, "SELECT count(*) FROM activity_log WHERE event = 'instance.restored';"),
        'data' => ['submissions' => $after['submissions'], 'users' => $after['users']], 'file' => $file->getOutput(), 'audit_verify' => $verify->getExitCode(),
        'upgrade_child' => $child[0]['data'] ?? null, 'up' => j09Get($this->v1->port, '/up')['status']];
    $this->journey->evidence('J09-restore-in-place.json', ['command' => 'fsa restore '.$backup.' --accept-unauthenticated --yes --json', 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'checks' => $checks]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data'])->toMatchArray(['backup' => $backup, 'format' => 'legacy', 'authenticated' => false]) // a 1.0 manifest names no version: the child goes by migrations
        ->and($json['data']['epoch'] ?? null)->toBe((int) $checks['epoch'])
        ->and($json['data']['previous_epoch'] ?? null)->toBe((int) $epochBefore)
        ->and((int) $checks['epoch'])->toBe((int) $epochBefore + 1)
        ->and($checks['restored_entries'])->toBe('1')
        ->and($checks['data'])->toBe(['submissions' => $adopted['verified']['counts']['submissions'] ?? -1, 'users' => $adopted['verified']['counts']['users'] ?? -1])
        ->and($checks['file'])->toBe('adopted')
        ->and($verify->getExitCode())->toBe(0, $verify->getOutput().$verify->getErrorOutput())
        ->and($child)->toHaveCount(1)
        ->and($child[0]['data']['migrated'] ?? [])->not->toBeEmpty()
        ->and($checks['up'])->toBe(200);
});

it('uninstalls with a final backup and the writes since adopt, and docker compose up -d brings 1.0 back with its data', function (): void {
    $uuid = (string) $GLOBALS['fsaJ09Uuid'];
    $write = new Process(['docker', 'exec', j09Container($uuid, 'app'), 'php', 'artisan', 'app:seed-load', 'demo', '3', '--chunk=3', '--force']);
    $write->setTimeout(300);
    $write->run();
    $process = $this->journey->fsa($this->dir, ['uninstall', '--yes', '--json']);
    $json = InstanceJourney::json($process);
    $left = InstanceJourney::labelled($uuid);
    $up = $this->v1->compose(['up', '-d', '--wait']);
    $back = $up->isSuccessful() ? V1Install::data($this->v1->container('postgres')) : [];
    $adopted = $GLOBALS['fsaJ09Adopt']['data']['adopted'] ?? [];
    $this->journey->evidence('J09-uninstall.json', ['commands' => ['fsa uninstall --yes --json', 'docker compose up -d --wait (in the checkout)'], 'exit_code' => $process->getExitCode(), 'stdout' => $json, 'labelled_left' => $left, 'v1_after' => $back]);

    expect($write->getExitCode())->toBe(0, $write->getErrorOutput())
        ->and($process->getExitCode())->toBe(0, $process->getErrorOutput()."\n".$process->getOutput())
        ->and($json['data']['final_backup']['dir'] ?? '')->toStartWith('backups/final-')
        ->and(file_exists($this->dir.'/'.($json['data']['final_backup']['dir'] ?? '-').'/app-key.env'))->toBeTrue() // review V2: env/app.env goes with the instance
        ->and($json['data']['adopted']['writes_since_adopt']['rows']['submissions'] ?? null)->toBe(3)
        ->and($json['data']['adopted']['restart_1_0'])->toBe('cd '.$this->v1->checkout.' && docker compose up -d')
        ->and($left)->toBe(['containers' => [], 'volumes' => [], 'networks' => []])
        ->and(is_dir($this->dir.'/backups/'.($adopted['backup'] ?? '-')))->toBeTrue()
        ->and($up->getExitCode())->toBe(0, $up->getErrorOutput())
        ->and($back['submissions'] ?? null)->toBe($adopted['verified']['counts']['submissions'] ?? -1)
        ->and($back['users'] ?? null)->toBe($adopted['verified']['counts']['users'] ?? -1)
        ->and(j09Get($this->v1->port, '/up')['status'])->toBe(200);
});
