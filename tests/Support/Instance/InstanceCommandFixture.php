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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Tests\Support\Release\ReleaseFixtures;

/**
 * A marked instance directory with a stored release, scripted Docker and probes, for the `fsa:*` command tests
 * (plan U5). Docker answers like a healthy host: port probes succeed unless a port is in `$takenPorts`, cosign
 * verifies, one-off artisan commands print the JSON documents of the app side, the smoke test passes.
 */
final class InstanceCommandFixture
{
    public const string VERSION = '2.0.0-alpha.1';

    public const string MIRROR = 'registry.test:5000';

    public const string LINK = 'http://localhost:8080/login-link#owner-token-0123456789abcdef0123456789abcdef';

    public const string ADMIN_LINK = 'http://localhost:8080/login-link#admin-token-fedcba9876543210fedcba9876543210';

    public InstanceDirectory $directory;

    public RecordingDockerGateway $docker;

    public FakeDoctorProbes $probes;

    /** @var list<int> */
    public array $takenPorts = [];

    public ?ProcessOutcome $smokeOutcome = null;

    /** @var array<string, ProcessOutcome> artisan command (app:install, migrate …) => outcome */
    public array $oneOffs = [];

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
        $this->docker->respondToComposeRun(fn (array $command): ProcessOutcome => $this->answerOneOff($command));
        $this->probes = new FakeDoctorProbes;
        app()->instance(DoctorProbes::class, $this->probes);
        $this->facts();
        Sleep::fake(); // the edge's certificate wait pauses between attempts (SmokeTest); the test case resets it
    }

    public static function appImage(): string
    {
        return self::MIRROR.'/beyerenrico/form-schema-app@'.ReleaseFixtures::APP_DIGEST;
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
     * Runs an fsa command with --json and returns the exit code and the envelope.
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
        foreach (['app', 'queue', 'scheduler', 'postgres', 'redis', 'gotenberg', 'mailpit'] as $index => $service) {
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
        if ($spec->entrypoint === 'curl') {
            return $this->smokeOutcome ?? new ProcessOutcome(0, "200 http://app:8080/up\n200 http://app:8080/admin/login\n");
        }

        return null;
    }

    /** @param  list<string>  $command */
    private function answerOneOff(array $command): ProcessOutcome
    {
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
            default => new ProcessOutcome(0, "Migrated.\n"),
        };
    }
}
