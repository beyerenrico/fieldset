<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use GuzzleHttp\Client;
use Symfony\Component\Process\Process;

/**
 * Drives real installs for the lifecycle journeys (plan U5, J1/J12): install.sh of the harness release, the
 * instance's fsa shim, and Docker queries by the instance label. Instance directories live in the harness
 * workspace (shared with Docker Desktop) and are named `fsa-test-…`; `cleanup()` removes everything labelled
 * with the instance UUID, whatever state a failed test left behind. SSH_CONNECTION keeps the shim from opening
 * a browser on a desktop host. A release built for another platform than the engine's runs emulated: install.sh
 * and the shim get DOCKER_DEFAULT_PLATFORM (HarnessRelease::platformEnvironment()).
 */
final readonly class InstanceJourney
{
    public const string LABEL = 'app.form-schema.instance';

    public function __construct(
        public ReleaseHarness $harness,
        public HarnessRelease $release,
        public string $work,
    ) {
        if (! is_dir($work)) {
            mkdir($work, 0755, true);
        }
    }

    /** @param  list<string>  $flags */
    public function install(string $dir, array $flags, array $env = []): Process
    {
        return $this->run(['/bin/sh', $this->release->directory.'/install.sh', '--dir', $dir, '--version', (string) $this->release->version, ...$flags], $env);
    }

    /**
     * The install as a process not yet started, for journeys that act while it runs (J2 attaches the edge to the
     * harness ACME network as soon as Compose creates it).
     *
     * @param  list<string>  $flags
     */
    public function installProcess(string $dir, array $flags, array $env = []): Process
    {
        return $this->process(['/bin/sh', $this->release->directory.'/install.sh', '--dir', $dir, '--version', (string) $this->release->version, ...$flags], $env);
    }

    /** @param  list<string>  $arguments */
    public function fsa(string $dir, array $arguments, array $env = []): Process
    {
        return $this->run([$dir.'/fsa', ...$arguments], $env);
    }

    /** @return array<string, mixed> the envelope: the last stdout line */
    public static function json(Process $process): array
    {
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $decoded = $lines === [] ? null : json_decode($lines[array_key_last($lines)], true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function uuid(string $dir): ?string
    {
        $state = is_file($dir.'/state.json') ? json_decode((string) file_get_contents($dir.'/state.json'), true) : null;

        return is_array($state) && is_string($state['uuid'] ?? null) ? $state['uuid'] : null;
    }

    /** @return array{containers: list<string>, volumes: list<string>, networks: list<string>} names labelled with the UUID */
    public static function labelled(string $uuid): array
    {
        $filter = '--filter=label='.self::LABEL.'='.$uuid;

        return [
            'containers' => self::lines(['docker', 'ps', '-a', $filter, '--format', '{{.Names}}']),
            'volumes' => self::lines(['docker', 'volume', 'ls', $filter, '--format', '{{.Name}}']),
            'networks' => self::lines(['docker', 'network', 'ls', $filter, '--format', '{{.Name}}']),
        ];
    }

    /**
     * Docker events between two Unix times.
     *
     * @param  list<string>  $filters  `key=value`
     * @return list<string>
     */
    public static function events(int $since, int $until, array $filters, string $format = '{{.Type}} {{.Action}} {{.Actor.Attributes.name}}'): array
    {
        $flags = array_merge(...array_map(static fn (string $filter): array => ['--filter', $filter], $filters));

        return self::lines(['docker', 'events', '--since', (string) $since, '--until', (string) $until, ...$flags, '--format', $format]);
    }

    /** @return array<string, string> container name => start time */
    public static function startTimes(string $uuid): array
    {
        $times = [];
        foreach (self::labelled($uuid)['containers'] as $name) {
            $times[$name] = trim(self::mustRun(['docker', 'inspect', '-f', '{{.State.StartedAt}}', $name])->getOutput());
        }
        ksort($times);

        return $times;
    }

    /** Removes everything labelled with the instance's UUID (or the UUID given) and the directory. */
    public static function cleanup(string $dir, ?string $uuid = null): void
    {
        $uuid ??= self::uuid($dir);
        if ($uuid !== null) {
            $labelled = self::labelled($uuid);
            foreach ($labelled['containers'] as $name) {
                new Process(['docker', 'rm', '-f', '-v', $name])->setTimeout(120)->run();
            }
            foreach ($labelled['networks'] as $name) {
                new Process(['docker', 'network', 'rm', $name])->run();
            }
            foreach ($labelled['volumes'] as $name) {
                new Process(['docker', 'volume', 'rm', '-f', $name])->run();
            }
        }
        new Process(['rm', '-rf', $dir])->run();
    }

    public static function http(int $port): Client
    {
        return new Client(['base_uri' => "http://localhost:{$port}", 'http_errors' => false, 'allow_redirects' => false, 'cookies' => true, 'timeout' => 30]);
    }

    /**
     * Writes a JSON transcript for the milestone evidence, with the workspace path and the registry port replaced and
     * secrets redacted (EvidenceRedactor: cookie values, XSRF tokens, CSP nonces, sign-in links (R50), signatures,
     * tokens), even those of a removed test instance: the evidence is committed.
     *
     * @param  array<string, mixed>  $transcript
     */
    public function evidence(string $name, array $transcript, string $milestone = 'M9'): void
    {
        $directory = TestImage::repository().'/docs/agent/milestones/evidence/'.$milestone;
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $json = json_encode(EvidenceRedactor::transcript($transcript), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $json = str_replace([$this->work, $this->harness->registry(), $this->harness->directory], ['<work>', '<registry>', '<harness>'], $json);
        file_put_contents($directory.'/'.$name, $json."\n");
    }

    /** @param  list<string>  $command */
    private function run(array $command, array $env): Process
    {
        $process = $this->process($command, $env);
        $process->run();

        return $process;
    }

    /** @param  list<string>  $command */
    private function process(array $command, array $env): Process
    {
        $process = new Process($command, $this->work, [
            'FSA_RELEASE_URL' => $this->harness->releaseUrl(),
            'FSA_REGISTRY' => $this->harness->registry(),
            'FSA_TRUST_KEY' => $this->harness->cosign->publicKey(),
            'SSH_CONNECTION' => 'fsa-test 0 fsa-test 0',
            ...$this->release->platformEnvironment(),
            ...$env,
        ]);
        $process->setTimeout(1800);

        return $process;
    }

    /** @return list<string> */
    private static function lines(array $command): array
    {
        return array_values(array_filter(explode("\n", trim(self::mustRun($command)->getOutput()))));
    }

    /** @param  list<string>  $command */
    private static function mustRun(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(300);
        $process->mustRun();

        return $process;
    }
}
