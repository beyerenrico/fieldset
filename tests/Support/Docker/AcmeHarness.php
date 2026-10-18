<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use App\Domain\Instance\Compose\EdgeNetwork;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Pebble and challtestsrv for domain mode (plan U8, J2; ADR 0064, ADR 0075): the `acme` profile of the release
 * harness, on its own network with a fixed subnet, so challtestsrv can answer every A query with the edge's address
 * there. `connectEdge()` attaches an instance's edge to that network at that address: the edge then resolves
 * `pebble` and Pebble reaches the edge for HTTP-01. Everything is part of the `fsa-test-harness-*` project.
 *
 * - `httpsRoot` is the minica CA of Pebble's ACME endpoint, copied out of the image: the edge's `acme_ca_root`.
 * - `issuingRoot()` is the root Pebble signs certificates with; Pebble generates it on every start, so it is read
 *   from the management API each time (chain verification in J2).
 */
final readonly class AcmeHarness
{
    public const string DIRECTORY_URL = 'https://pebble:14000/dir';

    private function __construct(
        private ReleaseHarness $harness,
        public string $network,
        public string $edgeAddress,
        public string $httpsRoot,
        private int $managementPort,
        private array $environment,
    ) {}

    public static function start(ReleaseHarness $harness): self
    {
        $subnet = self::freeSubnet();
        $base = substr($subnet, 0, (int) strrpos($subnet, '.'));
        $environment = [
            'FSA_HARNESS_ACME_SUBNET' => $subnet, 'FSA_HARNESS_DNS_IP' => "{$base}.10",
            'FSA_HARNESS_PEBBLE_IP' => "{$base}.11", 'FSA_HARNESS_EDGE_IP' => "{$base}.50",
        ];
        $compose = [...$harness->composeCommand($environment), '--profile', 'acme'];
        self::mustRun([...$compose, 'up', '-d', '--wait', 'challtestsrv', 'pebble'], 600);
        $container = trim(self::mustRun([...$compose, 'ps', '-q', 'pebble'])->getOutput());
        $root = $harness->directory.'/pebble-https-root.pem';
        self::mustRun(['docker', 'cp', "{$container}:/test/certs/pebble.minica.pem", $root]);
        $port = trim(self::mustRun([...$compose, 'port', 'pebble', '15000'])->getOutput());

        return new self($harness, $harness->project.'_acme', "{$base}.50", $root, (int) substr($port, (int) strrpos($port, ':') + 1), $environment);
    }

    /** The root Pebble issues certificates from in this run (PEM), from its management API over HTTPS. */
    public function issuingRoot(): string
    {
        $context = stream_context_create(['ssl' => ['cafile' => $this->httpsRoot, 'verify_peer' => true, 'peer_name' => 'localhost']]);
        $pem = @file_get_contents("https://127.0.0.1:{$this->managementPort}/roots/0", false, $context);

        return is_string($pem) && str_contains($pem, 'BEGIN CERTIFICATE') ? $pem : throw new RuntimeException('Pebble did not return its issuing root.');
    }

    /** Attaches a container (the edge, created or running) to the acme network at the address challtestsrv answers. */
    public function connectEdge(string $container): void
    {
        self::mustRun(['docker', 'network', 'connect', '--ip', $this->edgeAddress, $this->network, $container]);
    }

    /** Pebble's and challtestsrv's logs, for a failed journey. */
    public function logs(): string
    {
        $process = new Process([...$this->harness->composeCommand($this->environment), '--profile', 'acme', 'logs', '--no-color', '--tail', '40', 'pebble', 'challtestsrv']);
        $process->setTimeout(60);
        $process->run();

        return $process->getOutput();
    }

    /** Removes Pebble, challtestsrv and the acme network (the harness's own teardown also covers the profile). */
    public function stop(): void
    {
        new Process([...$this->harness->composeCommand($this->environment), '--profile', 'acme', 'rm', '--force', '--stop', '--volumes', 'challtestsrv', 'pebble'])->setTimeout(120)->run();
        new Process(['docker', 'network', 'rm', $this->network])->setTimeout(60)->run();
    }

    /** A /24 in 10.214.0.0/16 that no existing Docker network overlaps. */
    private static function freeSubnet(): string
    {
        $ids = array_filter(explode("\n", trim(self::mustRun(['docker', 'network', 'ls', '--quiet'])->getOutput())));
        $subnets = $ids === [] ? '' : trim(self::mustRun(['docker', 'network', 'inspect', '--format', '{{range .IPAM.Config}}{{.Subnet}} {{end}}', ...$ids])->getOutput());
        $taken = $subnets === '' ? [] : (preg_split('/\s+/', $subnets) ?: []);
        foreach (range(random_int(0, 200), 255) as $octet) {
            $candidate = "10.214.{$octet}.0/24";
            if (! array_any($taken, static fn (string $subnet): bool => EdgeNetwork::overlaps($candidate, $subnet))) {
                return $candidate;
            }
        }

        throw new RuntimeException('No free /24 for the harness acme network in 10.214.0.0/16.');
    }

    /** @param  list<string>  $command */
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
