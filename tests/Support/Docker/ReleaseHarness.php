<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use App\Domain\Instance\Release\ReleaseInputs;
use App\Domain\Instance\Release\ReleaseVersion;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The local release harness (plan KTD23, U7; ADR 0064, ADR 0067): a `registry:3` and a static release server
 * from tests/Install/Harness/compose.harness.yaml, a buildx builder on `network=host`, and a cosign key pair
 * generated per run. `publish()` mirrors the release workflow without accounts: build and push the app image
 * with SBOM and provenance, sign it, mirror every third-party pin by digest, generate the manifest with
 * `release:manifest`, sign it with `sign-blob --bundle`, and lay it out under dist/ like GitHub Releases.
 * Image and install.sh are harness builds (`FSA_BUILD=harness`, InstallScriptBuild; ADR 0070): they accept the
 * run's local key as FSA_TRUST_KEY and the plain-http release server.
 *
 * Everything Docker-side is named `fsa-test-harness-<id>` and removed by stop(). Per-run files live in
 * storage/fsa-test-harness/<id>/ (deleted by stop()); downloaded blobs and the build cache are kept in
 * storage/fsa-test-cache/ so repeat runs stay offline and fast (delete that directory to reclaim the space).
 */
final class ReleaseHarness
{
    public const string TEST_VERSION = '2.0.0-alpha.1-test';

    public const string MANIFEST = 'release-manifest.json';

    public const string STACK_FILE = 'compose.stack.yaml';

    /** Pulled images to untag on stop(). @var list<string> */
    private array $pulled = [];

    private bool $builder = false;

    /** The external network of proxy mode's Traefik, once startProxy() created it. */
    private ?string $proxyNetwork = null;

    private static ?string $native = null;

    private function __construct(
        public readonly string $project,
        public readonly string $directory,
        private readonly int $registryPort,
        private readonly int $releasePort,
        public readonly Cosign $cosign,
        private readonly OciRegistry $oci,
    ) {}

    /** Starts the registry and the release server, plus the registry forwarder when Docker needs it. */
    public static function start(): self
    {
        $id = bin2hex(random_bytes(3));
        $directory = TestImage::repository()."/storage/fsa-test-harness/{$id}";
        mkdir("{$directory}/dist", 0755, true);
        $project = "fsa-test-harness-{$id}";

        $compose = self::compose($project, $directory, []);

        try {
            self::mustRun([...$compose, 'up', '-d', '--wait', 'registry', 'release-server'], 600);
            $harness = new self(
                $project, $directory, self::publishedPort($compose, 'registry', 5000), self::publishedPort($compose, 'release-server', 8080),
                Cosign::generate("{$directory}/keys"), new OciRegistry(TestImage::repository().'/storage/fsa-test-cache/oci'),
            );
            $harness->connectHostNetwork();
        } catch (Throwable $exception) {
            self::tearDown($project, $directory);

            throw $exception;
        }

        return $harness;
    }

    /** The registry address for the host, the Docker daemon and host-network containers. */
    public function registry(): string
    {
        return "127.0.0.1:{$this->registryPort}";
    }

    /** Stands in for https://github.com/beyerenrico/form-schema-app/releases (FSA_RELEASE_URL). */
    public function releaseUrl(): string
    {
        return "http://127.0.0.1:{$this->releasePort}";
    }

    public function distDirectory(): string
    {
        return "{$this->directory}/dist";
    }

    /**
     * The version the shared harness publishes and the journeys install: FSA_RELEASE_VERSION when set (validated),
     * else TEST_VERSION. LocalPreReleaseTest publishes its own version (FSA_PRERELEASE_VERSION).
     */
    public static function version(): string
    {
        $version = getenv('FSA_RELEASE_VERSION');

        return is_string($version) && $version !== '' ? (string) ReleaseVersion::parse($version) : self::TEST_VERSION;
    }

    /** Native platform, or FSA_TEST_PLATFORM (emulation), or the comma list FSA_HARNESS_PLATFORMS. @return list<string> */
    public static function platforms(): array
    {
        $list = getenv('FSA_HARNESS_PLATFORMS');
        if (is_string($list) && $list !== '') {
            return array_values(array_filter(array_map(trim(...), explode(',', $list))));
        }

        return [TestImage::platform() ?? self::nativePlatform()];
    }

    /** The Docker engine's own platform: linux/arm64 or linux/amd64. */
    public static function nativePlatform(): string
    {
        if (self::$native === null) {
            $architecture = trim(self::mustRun(['docker', 'info', '--format', '{{.Architecture}}'])->getOutput());
            self::$native = 'linux/'.(in_array($architecture, ['aarch64', 'arm64'], true) ? 'arm64' : 'amd64');
        }

        return self::$native;
    }

    /**
     * DOCKER_DEFAULT_PLATFORM for a release of one platform that is not the engine's (FSA_TEST_PLATFORM or a single
     * FSA_HARNESS_PLATFORMS entry): install.sh, the shim and fsa then pull and run it under emulation (§15.6).
     *
     * @param  list<string>  $platforms
     * @return array<string, string>
     */
    public static function platformEnvironment(array $platforms): array
    {
        return count($platforms) === 1 && $platforms[0] !== self::nativePlatform() ? ['DOCKER_DEFAULT_PLATFORM' => $platforms[0]] : [];
    }

    /**
     * Builds, signs and serves a release.
     *
     * @param  list<string>|null  $platforms
     */
    public function publish(string $version = self::TEST_VERSION, ?array $platforms = null): HarnessRelease
    {
        $release = ReleaseVersion::parse($version);
        $platforms ??= self::platforms();
        $inputs = ReleaseInputs::fromFile(TestImage::repository().'/distribution/release.json');

        $appDigest = $this->buildApp($release, $platforms, $inputs);
        $appReference = "{$this->registry()}/{$inputs->appRepository}@{$appDigest}";
        $this->cosign->signImage($appReference);
        foreach ($inputs->images as $pin) {
            $this->oci->mirror($pin, $this->registry(), $platforms);
        }

        $directory = "{$this->distDirectory()}/download/{$release->tag()}";
        $this->writeAssets($release, $platforms, $appDigest, $directory);
        if (! $release->isPrerelease()) {
            self::mustRun(['mkdir', '-p', $this->latestDirectory()]);
            self::mustRun(['cp', '-R', $directory.'/.', $this->latestDirectory()]);
        }

        return new HarnessRelease($release, $appDigest, $appReference, $directory, "{$this->releaseUrl()}/download/{$release->tag()}", $platforms);
    }

    /**
     * A release whose app image is the base release's with a few changes (J07, plan U18): a target that adds a migration,
     * one whose health check fails, one whose `fsa` crashes. The image is built `FROM` the base digest in the harness
     * registry with `$instructions` (Dockerfile lines; `$files` are written next to the Dockerfile first) and the
     * version in APP_VERSION and the OCI label, then signed and published with its own manifest like publish().
     *
     * @param  array<string, string>  $files  context path => contents
     */
    public function publishDerived(string $version, HarnessRelease $base, string $instructions, array $files = []): HarnessRelease
    {
        $release = ReleaseVersion::parse($version);
        $inputs = ReleaseInputs::fromFile(TestImage::repository().'/distribution/release.json');
        $context = "{$this->directory}/derived/{$release}";
        mkdir($context, 0755, true);
        foreach ($files as $path => $contents) {
            @mkdir(dirname("{$context}/{$path}"), 0755, true);
            file_put_contents("{$context}/{$path}", $contents);
        }
        file_put_contents("{$context}/Dockerfile", "FROM {$base->appReference}\n{$instructions}\nENV APP_VERSION={$release}\nLABEL org.opencontainers.image.version=\"{$release}\"\n");
        $metadata = "{$context}/build-metadata.json";
        $this->ensureBuilder();
        self::mustRun(['docker', 'buildx', 'build', '--builder', $this->project, '--progress=plain', '--platform', implode(',', $base->platforms), '--sbom=true', '--provenance=mode=max', // an index, as release:manifest requires
            '-t', "{$this->registry()}/{$inputs->appRepository}:{$release}", '--push', '--metadata-file', $metadata, $context], 1800);
        $digest = json_decode((string) file_get_contents($metadata), true)['containerimage.digest'] ?? throw new RuntimeException("No image digest in {$metadata}.");
        $this->cosign->signImage("{$this->registry()}/{$inputs->appRepository}@{$digest}");
        $directory = "{$this->distDirectory()}/download/{$release->tag()}";
        $this->writeAssets($release, $base->platforms, $digest, $directory);

        return new HarnessRelease($release, $digest, "{$this->registry()}/{$inputs->appRepository}@{$digest}", $directory, "{$this->releaseUrl()}/download/{$release->tag()}", $base->platforms);
    }

    /**
     * The release server as a container on Docker's default bridge reaches it (`fsa upgrade` downloads the target's
     * manifest from inside its ops container, ADR 0086): the server joins the bridge once, and its address there serves.
     */
    public function containerReleaseUrl(): string
    {
        $compose = self::compose($this->project, $this->directory, []);
        $container = trim(self::mustRun([...$compose, 'ps', '-q', 'release-server'])->getOutput());
        $address = trim(self::mustRun(['docker', 'inspect', '-f', '{{with index .NetworkSettings.Networks "bridge"}}{{.IPAddress}}{{end}}', $container])->getOutput());
        if ($address === '') {
            self::mustRun(['docker', 'network', 'connect', 'bridge', $container]);
            $address = trim(self::mustRun(['docker', 'inspect', '-f', '{{with index .NetworkSettings.Networks "bridge"}}{{.IPAddress}}{{end}}', $container])->getOutput());
        }

        return "http://{$address}:8080";
    }

    /** Where a stable release is also served, like GitHub's latest/download (pre-releases never land here). */
    public function latestDirectory(): string
    {
        return "{$this->distDirectory()}/latest/download";
    }

    /**
     * Tags in a repository of the harness registry (cosign's `sha256-…` signature tags included).
     *
     * @return list<string>
     */
    public function tags(string $repository): array
    {
        return $this->oci->tags($this->registry(), $repository);
    }

    /** `docker pull` from the harness registry; the reference is untagged again on stop(). */
    public function pull(string $reference): Process
    {
        $process = new Process(['docker', 'pull', '--quiet', $reference]);
        $process->setTimeout(900);
        $process->run();
        if ($process->isSuccessful()) {
            $this->pulled[] = $reference;
        }

        return $process;
    }

    /**
     * Proxy mode's Traefik on an external network of its own (J3): creates `fsa-test-proxy-<id>` and starts the
     * `traefik` service of the proxy profile on it. Idempotent.
     *
     * @return array{network: string, container: string}
     */
    public function startProxy(): array
    {
        $network = $this->proxyNetwork ?? str_replace('fsa-test-harness-', 'fsa-test-proxy-', $this->project);
        if ($this->proxyNetwork === null) {
            self::mustRun(['docker', 'network', 'create', '--label', 'app.form-schema.test=harness', $network]);
            $this->proxyNetwork = $network;
        }
        $compose = self::compose($this->project, $this->directory, ['FSA_HARNESS_PROXY_NETWORK' => $network]);
        self::mustRun([...$compose, '--profile', 'proxy', 'up', '-d', '--wait', 'traefik'], 600);
        $container = trim(self::mustRun([...$compose, '--profile', 'proxy', 'ps', '--format', '{{.Name}}', 'traefik'])->getOutput());

        return ['network' => $network, 'container' => $container];
    }

    /** Stops Traefik and removes its network (after every container left it). */
    public function stopProxy(): void
    {
        if ($this->proxyNetwork === null) {
            return;
        }
        $compose = self::compose($this->project, $this->directory, ['FSA_HARNESS_PROXY_NETWORK' => $this->proxyNetwork]);
        new Process([...$compose, '--profile', 'proxy', 'rm', '--force', '--stop', 'traefik'])->setTimeout(300)->run();
        new Process(['docker', 'network', 'rm', $this->proxyNetwork])->setTimeout(120)->run();
        $this->proxyNetwork = null;
    }

    /** Removes the compose project (containers, network, volume), the builder, pulled references and run files. */
    public function stop(): void
    {
        $this->stopProxy();

        foreach (array_unique($this->pulled) as $reference) {
            new Process(['docker', 'image', 'rm', $reference])->run();
        }

        if ($this->builder) {
            new Process(['docker', 'buildx', 'rm', '--force', $this->project])->setTimeout(120)->run();
        }

        self::tearDown($this->project, $this->directory);
    }

    public static function logFile(string $name): string
    {
        return TestImage::logFile($name);
    }

    /**
     * The harness compose command with extra variables, for the substitutes behind profiles (AcmeHarness).
     *
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    public function composeCommand(array $environment = []): array
    {
        return self::compose($this->project, $this->directory, $environment);
    }

    /**
     * @param  list<string>  $platforms
     */
    private function buildApp(ReleaseVersion $release, array $platforms, ReleaseInputs $inputs): string
    {
        $this->ensureBuilder();
        $cache = TestImage::repository().'/storage/fsa-test-cache/buildx';
        $metadata = "{$this->directory}/build-metadata.json";
        $tags = array_merge(...array_map(fn (string $tag): array => ['-t', "{$this->registry()}/{$inputs->appRepository}:{$tag}"], $release->imageTags()));
        $log = self::logFile("{$this->project}-build.log");
        $process = new Process([
            'docker', 'buildx', 'build', '--builder', $this->project, '--progress=plain', '--platform', implode(',', $platforms),
            '--sbom=true', '--provenance=mode=max', '-f', 'docker/app/Dockerfile',
            '--build-arg', "APP_VERSION={$release}", '--build-arg', 'APP_REVISION='.TestImage::revision(), '--build-arg', 'FSA_BUILD=harness', ...$tags,
            ...(is_file("{$cache}/index.json") ? ['--cache-from', "type=local,src={$cache}"] : []),
            '--cache-to', "type=local,dest={$cache},mode=max", '--push', '--metadata-file', $metadata, '.',
        ], TestImage::repository());
        $process->setTimeout(3600);
        file_put_contents($log, '');
        $process->run(static function (string $type, string $output) use ($log): void {
            file_put_contents($log, $output, FILE_APPEND);
        });
        if (! $process->isSuccessful()) {
            $tail = implode("\n", array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -40));
            throw new RuntimeException("The harness build failed (full log: {$log}):\n{$tail}");
        }

        $digest = json_decode((string) file_get_contents($metadata), true)['containerimage.digest'] ?? null;

        return is_string($digest) ? $digest : throw new RuntimeException("No image digest in {$metadata}.");
    }

    private function ensureBuilder(): void
    {
        if (! $this->builder) {
            self::mustRun(['docker', 'buildx', 'create', '--name', $this->project, '--driver', 'docker-container', '--driver-opt', 'network=host', '--bootstrap'], 600);
            $this->builder = true;
        }
    }

    /**
     * The manifest and the stack file (via `release:manifest`, as CI runs it), the manifest's bundle, the assets and
     * SHA256SUMS.
     *
     * @param  list<string>  $platforms
     */
    private function writeAssets(ReleaseVersion $release, array $platforms, string $appDigest, string $directory): void
    {
        mkdir($directory, 0755, true);
        $assets = [];
        $assets[] = InstallScriptBuild::writeHarness("{$directory}/install.sh");
        copy(TestImage::repository().'/distribution/shim.sh', $assets[] = "{$directory}/shim.sh");

        // The stack file is rendered from the manifest's pins, as the release workflow does (ADR 0076).
        self::mustRun([
            PHP_BINARY, 'artisan', 'release:manifest', (string) $release, "--app-digest={$appDigest}", "--mirror={$this->registry()}",
            ...array_map(static fn (string $platform): string => "--platform={$platform}", $platforms),
            ...array_map(static fn (string $asset): string => "--asset={$asset}", $assets),
            "--stack-file={$directory}/".self::STACK_FILE, "--output={$directory}/".self::MANIFEST,
        ], 300);
        // R39 (ADR 0091), as the release workflow runs it: the app image's pg_dump is not older than the manifest's Postgres.
        self::mustRun([
            PHP_BINARY, 'artisan', 'release:check-pg-dump', "{$directory}/".self::MANIFEST, "--mirror={$this->registry()}",
            ...array_map(static fn (string $platform): string => "--platform={$platform}", $platforms),
        ], 600);

        foreach (["{$directory}/".self::MANIFEST, ...array_filter($assets, static fn (string $asset): bool => str_ends_with($asset, 'install.sh'))] as $file) {
            $this->cosign->signBlob($file);
        }

        $sums = '';
        foreach (glob("{$directory}/*") ?: [] as $file) {
            $sums .= hash_file('sha256', $file).'  '.basename($file)."\n";
        }

        file_put_contents("{$directory}/SHA256SUMS", $sums);
    }

    /**
     * Docker Desktop does not publish ports inside its VM: start the forwarder when a host-network container
     * cannot reach the registry (see compose.harness.yaml).
     */
    private function connectHostNetwork(): void
    {
        if ($this->hostNetworkReaches()) {
            return;
        }

        $compose = self::compose($this->project, $this->directory, []);
        $container = trim(self::mustRun([...$compose, 'ps', '-q', 'registry'])->getOutput());
        $address = trim(self::mustRun(['docker', 'inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', $container])->getOutput());
        self::mustRun([...self::compose($this->project, $this->directory, [
            'FSA_HARNESS_REGISTRY_PORT' => (string) $this->registryPort, 'FSA_HARNESS_REGISTRY_TARGET' => "{$address}:5000",
        ]), '--profile', 'forwarder', 'up', '-d', 'registry-forwarder'], 300);

        for ($attempt = 0; $attempt < 20 && ! $this->hostNetworkReaches(); $attempt++) {
            usleep(500_000);
        }

        if (! $this->hostNetworkReaches()) {
            throw new RuntimeException("The harness registry is not reachable from the host network at {$this->registry()}.");
        }
    }

    private function hostNetworkReaches(): bool
    {
        $probe = new Process(['docker', 'run', '--rm', '--network', 'host', '--entrypoint', 'wget', $this->forwarderImage(),
            '-q', '-T', '3', '-O', '/dev/null', "http://{$this->registry()}/v2/"]);
        $probe->setTimeout(120);
        $probe->run();

        return $probe->isSuccessful();
    }

    private function forwarderImage(): string
    {
        $compose = (string) file_get_contents(TestImage::repository().'/tests/Install/Harness/compose.harness.yaml');
        preg_match('/^\s+image:\s+(docker\.io\/library\/caddy:\S+)$/m', $compose, $match);

        return $match[1] ?? throw new RuntimeException('No Caddy image in compose.harness.yaml.');
    }

    private static function tearDown(string $project, string $directory): void
    {
        $compose = self::compose($project, $directory, []);
        new Process([...$compose, '--profile', 'forwarder', '--profile', 'acme', '--profile', 'proxy', 'down', '--volumes', '--remove-orphans', '--timeout', '5'])->setTimeout(300)->run();
        new Process(['rm', '-rf', $directory])->run();
    }

    /**
     * @param  list<string>  $compose
     */
    private static function publishedPort(array $compose, string $service, int $port): int
    {
        $output = trim(self::mustRun([...$compose, 'port', $service, (string) $port])->getOutput());

        return (int) substr($output, (int) strrpos($output, ':') + 1);
    }

    /**
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    private static function compose(string $project, string $directory, array $environment): array
    {
        $variables = ['FSA_HARNESS_DIST' => "{$directory}/dist", ...$environment];

        return ['env', ...array_map(static fn (string $key, string $value): string => "{$key}={$value}", array_keys($variables), $variables),
            'docker', 'compose', '-p', $project, '-f', TestImage::repository().'/tests/Install/Harness/compose.harness.yaml'];
    }

    /**
     * @param  list<string>  $command
     */
    private static function mustRun(array $command, int $timeout = 120): Process
    {
        $process = new Process($command, TestImage::repository());
        $process->setTimeout($timeout);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf("`%s` failed:\n%s%s", implode(' ', $command), $process->getErrorOutput(), $process->getOutput()));
        }

        return $process;
    }
}
