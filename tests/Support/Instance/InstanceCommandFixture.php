<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\ImageInfo;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\Doctor\DoctorProbes;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\Release\ManifestVerifier;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Tests\Support\Release\ReleaseFixtures;

/**
 * A marked instance directory with a stored release, scripted Docker and probes, for the `fieldset:*` command tests
 * (plan U5). Docker answers like a healthy host: port probes succeed unless a port is in `$takenPorts`, cosign
 * verifies, one-off artisan commands print the JSON documents of the app side, the smoke test passes.
 */
final class InstanceCommandFixture
{
    public const string VERSION = '2.0.0-alpha.1';

    public const string MIRROR = 'registry.test:5000';

    public const string LINK = 'http://localhost:8080/login-link#owner-token-0123456789abcdef0123456789abcdef';

    public const string ADMIN_LINK = 'http://localhost:8080/login-link#admin-token-fedcba9876543210fedcba9876543210';

    /** The Message-ID of the install smoke test's mail (`instance:mail-test --install`), as the catcher lists it. */
    public const string MESSAGE_ID = '0123456789abcdef0123456789abcdef@localhost';

    public InstanceDirectory $directory;

    public RecordingDockerGateway $docker;

    public FakeDoctorProbes $probes;

    /** @var list<int> */
    public array $takenPorts = [];

    public ?ProcessOutcome $smokeOutcome = null;

    /** The catcher API's answer to the smoke test's lookup (null: it lists the test mail). */
    public ?ProcessOutcome $catcherOutcome = null;

    /** @var array<string, ProcessOutcome> artisan command (app:install, migrate …) => outcome */
    public array $oneOffs = [];

    /** The backup service's one-off (`fieldset backup-daemon --once|--seal`); null: a successful run (backupRun()). */
    public ?ProcessOutcome $backupOutcome = null;

    /** stdin of the last backup one-off (`--include-secrets`). */
    public ?string $backupInput = null;

    /** The backup service's `--clean` one-off (uninstall); null: it succeeds after running $onClean. */
    public ?ProcessOutcome $cleanOutcome = null;

    /** What the service's root removes for `--clean` (tests emulate it; null: nothing). */
    public ?Closure $onClean = null;

    public string $manifestPath;

    public function __construct(?string $path = null)
    {
        $this->directory = InstanceDirectory::initialize($path ?? TemporaryInstance::path());
        $releases = $this->directory->ensureDirectory('releases/'.self::VERSION);
        [$this->manifestPath] = ReleaseFixtures::writeSignedManifest($releases, self::VERSION);
        $this->docker = RecordingDockerGateway::bind();
        $this->docker->withImage(ManifestVerifier::COSIGN_IMAGE, new ImageInfo('sha256:'.str_repeat('c', 64), [ManifestVerifier::COSIGN_IMAGE]));
        $this->docker->withImage(self::appImage(), new ImageInfo('sha256:'.str_repeat('a', 64), [self::appImage()]));
        $this->docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => $this->answerRun($spec));
        $this->docker->respondToComposeRun(fn (array $command, ?string $input = null): ProcessOutcome => $this->answerOneOff($command, $input));
        $this->probes = new FakeDoctorProbes;
        app()->instance(DoctorProbes::class, $this->probes);
        $this->facts();
        Sleep::fake(); // the edge's certificate wait pauses between attempts (SmokeTest); the test case resets it
    }

    public static function appImage(): string
    {
        return self::MIRROR.'/beyerenrico/fieldset@'.ReleaseFixtures::APP_DIGEST;
    }

    /** @param  array<string, mixed>  $overrides  HostFacts constructor arguments */
    public function facts(array $overrides = []): HostFacts
    {
        $facts = new HostFacts(...[
            'tty' => false, 'uid' => posix_getuid(), 'gid' => posix_getgid(), 'os' => 'linux',
            'instanceDir' => $this->directory->root, 'launcher' => 'shim', 'runtime' => DockerRuntime::Rootful,
            'containerUid' => posix_getuid(), 'releaseManifest' => $this->manifestPath, 'registry' => self::MIRROR,
            ...$overrides,
        ]);
        app()->instance(HostFacts::class, $facts);

        return $facts;
    }

    /**
     * Runs a fieldset command with --json and returns the exit code and the envelope.
     *
     * @param  array<string, mixed>  $parameters
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function run(string $command, array $parameters = []): array
    {
        $code = Artisan::call($command, ['--json' => true, '--dir' => $this->directory->root, ...$parameters]);
        $output = trim(Artisan::output());
        $lines = explode("\n", $output);

        return [$code, json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR)];
    }

    /** @return array<string, mixed> the flags of a complete first install */
    public static function installFlags(array $overrides = []): array
    {
        return ['--org' => 'Acme Forms', '--owner-email' => 'Owner@Example.com', '--owner-name' => 'Olivia Owner', '--locale' => 'en', ...$overrides];
    }

    public function state(): InstanceState
    {
        return $this->directory->state();
    }

    /** Pretends the stack runs: one running, healthy container per compose service. */
    public function withRunningStack(): self
    {
        $state = $this->state();
        foreach (['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit', 'backup'] as $index => $service) {
            $this->docker->withRunningContainer(str_repeat((string) ($index + 1), 12), '2026-10-18T10:00:00Z', [
                Labels::COMPOSE_PROJECT => $state->project(), Labels::COMPOSE_SERVICE => $service,
                Labels::INSTANCE => $state->uuid(), Labels::ROLE => $service,
            ], $state->project().'-'.$service.'-1');
        }

        return $this;
    }

    /** @return list<array<string, mixed>> the composeRun calls whose artisan command is $name */
    public function oneOffCalls(string $name): array
    {
        return array_values(array_filter($this->docker->called('composeRun'), static fn (array $call): bool => ($call['command'][2] ?? null) === $name));
    }

    /** @return list<ContainerSpec> */
    public function runSpecs(?string $entrypoint = null): array
    {
        $specs = array_map(static fn (array $call): ContainerSpec => $call['spec'], $this->docker->called('runContainer'));

        return array_values(array_filter($specs, static fn (ContainerSpec $spec): bool => $entrypoint === null || $spec->entrypoint === $entrypoint));
    }

    /**
     * The install smoke test's lookup of its test mail in the catcher's API (InstallSmoke): the list holds it. Null for
     * any other container, so responders of other tests can answer it first and go on as before.
     */
    public static function catcherLookup(ContainerSpec $spec): ?ProcessOutcome
    {
        $url = $spec->command[array_key_last($spec->command)] ?? '';

        return $spec->entrypoint === 'curl' && str_starts_with($url, 'http://mailpit:8025/') && str_contains($url, '/api/v1/messages')
            ? new ProcessOutcome(0, json_encode(['messages' => [['ID' => 'a1', 'MessageID' => self::MESSAGE_ID, 'Subject' => 'Fieldset: test mail']]], JSON_THROW_ON_ERROR))
            : null;
    }

    /** @param  array<string, mixed>  $document */
    public static function oneOffJson(array $document): ProcessOutcome
    {
        return new ProcessOutcome($document['status'] === 'ok' ? 0 : 1, json_encode([
            'schema_version' => 1, 'command' => 'app', 'changed' => false, 'data' => [], 'secrets' => [], 'warnings' => [], 'errors' => [], ...$document,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    private function answerRun(ContainerSpec $spec): ?ProcessOutcome
    {
        if ($spec->entrypoint === 'true') {
            $port = (int) explode(':', $spec->ports[0] ?? '')[1];

            return in_array($port, $this->takenPorts, true)
                ? new ProcessOutcome(125, '', "docker: Error response from daemon: Bind for 127.0.0.1:{$port} failed: port is already allocated.")
                : new ProcessOutcome(0);
        }
        $catcher = self::catcherLookup($spec);
        if ($catcher instanceof ProcessOutcome) {
            return $this->catcherOutcome ?? $catcher;
        }
        if ($spec->entrypoint === 'curl') {
            return $this->smokeOutcome ?? new ProcessOutcome(0, "200 http://app:8080/up\n200 http://app:8080/admin/login\n");
        }

        return null;
    }

    /**
     * The JSON document of a successful `fieldset backup-daemon --once` (BackupRun's recorded run).
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function backupRun(string $kind = 'manual', array $overrides = []): ProcessOutcome
    {
        $name = $kind.'-20261019T120000Z';

        return self::oneOffJson(['command' => 'backup-daemon', 'status' => 'ok', 'changed' => true, 'data' => [
            'kind' => $kind, 'status' => 'succeeded', 'started_at' => '2026-10-19T12:00:00Z', 'finished_at' => '2026-10-19T12:00:09Z',
            'backup' => $name, 'bytes' => 52_431, 'encrypted' => false, 'missing_files' => 0, 'free_bytes' => 20 * 1024 ** 3, 'pruned' => [],
            'result' => ['backup' => $name, 'kind' => $kind, 'created_at' => '2026-10-19T12:00:00Z', 'bytes' => 52_431, 'encrypted' => false, 'includes_secrets' => false,
                'files' => ['referenced' => 3, 'missing' => 0, 'missing_sample' => []], 'app_key_fingerprints' => ['0123-4567-89ab-cdef'], 'warnings' => []],
            'recorded' => true, ...$overrides,
        ]]);
    }

    /** @param  list<string>  $command */
    private function answerOneOff(array $command, ?string $input = null): ProcessOutcome
    {
        if (($command[0] ?? null) === 'fieldset' && ($command[1] ?? null) === 'backup-daemon' && in_array('--clean', $command, true)) {
            if (! $this->cleanOutcome instanceof ProcessOutcome && $this->onClean instanceof Closure) {
                ($this->onClean)();
            }

            return $this->cleanOutcome ?? self::oneOffJson(['command' => 'backup-daemon', 'status' => 'ok', 'changed' => true, 'data' => ['removed' => [], 'left' => []]]);
        }
        if (($command[0] ?? null) === 'fieldset' && ($command[1] ?? null) === 'backup-daemon') {
            $this->backupInput = $input;

            return $this->backupOutcome ?? self::backupRun(str_starts_with((string) ($command[3] ?? ''), '--kind=') ? substr((string) $command[3], 7) : 'manual');
        }
        $name = $command[2] ?? '';

        return $this->oneOffs[$name] ?? match ($name) {
            'app:install' => self::oneOffJson(['command' => 'app:install', 'status' => 'ok', 'changed' => true,
                'data' => ['organization' => ['id' => 1, 'name' => 'Acme Forms', 'slug' => 'acme-forms'], 'owner' => ['id' => 1, 'email' => 'owner@example.com', 'name' => 'Olivia Owner', 'operator' => true, 'password_set' => false]],
                'secrets' => [['name' => 'sign_in_link', 'value' => self::LINK, 'expires_at' => '2026-10-18T11:00:00Z']]]),
            'identity:login-link' => self::oneOffJson(['command' => 'identity:login-link', 'status' => 'ok', 'changed' => true,
                'data' => ['email' => 'owner@example.com', 'user_id' => 1, 'kind' => 'admin', 'link_id' => 2, 'expires_at' => '2026-10-18T10:15:00Z'],
                'secrets' => [['name' => 'sign_in_link', 'value' => self::ADMIN_LINK, 'expires_at' => '2026-10-18T10:15:00Z']]]),
            'instance:url-impact' => self::oneOffJson(['command' => 'instance:url-impact', 'status' => 'ok',
                'data' => ['sso_organizations' => [], 'links' => ['invitations' => 0, 'document_links' => 0, 'login_links' => 0, 'data_exports' => 0]]]),
            'instance:config-changed' => self::oneOffJson(['command' => 'instance:config-changed', 'status' => 'ok', 'changed' => true,
                'data' => ['event' => 'instance.config_changed', 'activity_id' => 7]]),
            'identity:login-links' => self::oneOffJson(['command' => 'identity:login-links', 'status' => 'ok',
                'data' => ['links' => [['id' => 1, 'email' => 'owner@example.com', 'kind' => 'owner', 'state' => 'unused']], 'count' => 1]]),
            'instance:mail-test' => self::oneOffJson(['command' => 'instance:mail-test', 'status' => 'ok', 'changed' => true,
                'data' => ['to' => 'owner@example.com', 'mailer' => 'smtp', ...(in_array('--install', $command, true) ? ['message_id' => self::MESSAGE_ID] : [])]]),
            'instance:pdf-test' => self::oneOffJson(['command' => 'instance:pdf-test', 'status' => 'ok', 'data' => ['bytes' => 1834]]),
            'instance:backup-role' => self::oneOffJson(['command' => 'instance:backup-role', 'status' => 'ok', 'changed' => true,
                'data' => ['role' => 'fieldset_backup', 'created' => true, 'granted' => ['pg_read_all_data', 'connect', 'insert:backup_runs']]]),
            'instance:mail-captured' => self::oneOffJson(['command' => 'instance:mail-captured', 'status' => 'ok',
                'data' => ['invitations' => 2, 'password_resets' => 1]]),
            'instance:operator-email' => self::oneOffJson(['command' => 'instance:operator-email', 'status' => 'ok', 'changed' => true,
                'data' => ['email' => 'new-ops@example.com', 'user_id' => 2, 'created' => true, 'granted' => true, 'revoked' => [], 'password_set' => false, 'login_url' => 'http://localhost:8080/platform/login'],
                'secrets' => [['name' => 'sign_in_link', 'value' => self::LINK, 'expires_at' => '2026-10-18T11:00:00Z']]]),
            // The default-password check (DefaultPasswordGuard, ExposureCheck): no account signs in with `password`.
            'app:demo-passwords' => in_array('--check', $command, true) ? self::oneOffJson(['command' => 'app:demo-passwords', 'status' => 'ok',
                'data' => ['checked' => 1, 'count' => 0, 'users' => [], 'organizations' => ['total' => 1, 'non_demo' => 1]]]) : new ProcessOutcome(0, "Migrated.\n"),
            default => new ProcessOutcome(0, "Migrated.\n"),
        };
    }
}
