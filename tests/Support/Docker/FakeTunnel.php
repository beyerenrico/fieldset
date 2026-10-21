<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use App\Domain\Instance\Compose\Services\TunnelService;
use App\Domain\Instance\Compose\TunnelNetwork;
use App\Domain\Instance\Release\ReleaseInputs;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The fake quick tunnel for J6 (BUILD_PROMPT_V2 §11 "a fake tunnel container"; ADR 0082): an image built here from
 * tests/Install/Harness/fake-tunnel on the Caddy pin of distribution/release.json, named `fsa-test-fake-tunnel:<id>`.
 * Passed to fsa as FIELDSET_TEST_TUNNEL (harness images only), it replaces cloudflared in the instance's `tunnel` service:
 * `/quicktunnel` names a random `fsa-test-….trycloudflare.com` host, and port 8443 forwards to the app from the tunnel
 * peer's fixed address with that Host. `request()` is a client of the tunnel: a curl container on the instance's tunnel
 * network, aimed at that port.
 */
final readonly class FakeTunnel
{
    public const int PORT = 8443;

    private function __construct(public string $image) {}

    /** @param  array<string, string>  $platformEnvironment  DOCKER_DEFAULT_PLATFORM of an emulated run */
    public static function build(array $platformEnvironment = []): self
    {
        $image = 'fsa-test-fake-tunnel:'.bin2hex(random_bytes(4));
        $caddy = ReleaseInputs::fromFile(TestImage::repository().'/distribution/release.json')->images['caddy']
            ?? throw new RuntimeException('distribution/release.json pins no Caddy image.');
        $platform = $platformEnvironment['DOCKER_DEFAULT_PLATFORM'] ?? null;
        $process = new Process([
            'docker', 'build', '--quiet', '--build-arg', 'BASE='.$caddy->reference(), ...($platform === null ? [] : ['--platform', $platform]),
            '--label', 'app.fieldset.test=fake-tunnel', '-t', $image, TestImage::repository().'/tests/Install/Harness/fake-tunnel',
        ]);
        $process->setTimeout(600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException("The fake tunnel image did not build:\n".$process->getErrorOutput());
        }

        return new self($image);
    }

    /** FIELDSET_TEST_TUNNEL for the fsa commands: this image, and the tunnel's lifetime in seconds. */
    public function environment(int $ttl): array
    {
        return ['FIELDSET_TEST_TUNNEL' => "image={$this->image};ttl={$ttl}"];
    }

    /**
     * A request through the tunnel: from the tunnel network to the fake's forwarder, which the app sees as coming from
     * the tunnel peer with the tunnel host.
     *
     * @param  array<string, string>  $platformEnvironment
     * @param  list<string>  $headers  extra request headers (`Name: value`), e.g. a forged X-Forwarded-Host
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public static function request(string $project, string $appImage, string $subnet, string $path, array $platformEnvironment = [], array $headers = []): array
    {
        $url = 'http://'.TunnelNetwork::peerAddress($subnet).':'.self::PORT.$path;
        $flags = array_merge(...array_map(static fn (string $header): array => ['--header', $header], $headers));
        $process = new Process(['docker', 'run', '--rm', '--network', $project.'_'.TunnelNetwork::NAME, '--label', 'app.fieldset.test=fake-tunnel-client',
            '--entrypoint', 'curl', $appImage, '--silent', '--show-error', '--max-time', '20', '--include', ...$flags, $url], null, $platformEnvironment);
        $process->setTimeout(120);
        $process->run();
        [$head, $body] = array_pad(explode("\r\n\r\n", $process->getOutput(), 2), 2, '');
        $lines = explode("\r\n", $head);
        preg_match('/^HTTP\/\S+ (\d{3})/', $lines[0] ?? '', $status);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[strtolower(trim($name))] = trim($value);
        }

        return ['status' => (int) ($status[1] ?? 0), 'headers' => $headers, 'body' => $body.$process->getErrorOutput()];
    }

    /** `/quicktunnel` as the fake answers it, from the tunnel network. */
    public static function metricsUrl(string $subnet): string
    {
        return 'http://'.TunnelNetwork::peerAddress($subnet).':'.TunnelService::METRICS_PORT.'/quicktunnel';
    }

    public function remove(): void
    {
        new Process(['docker', 'image', 'rm', '--force', $this->image])->setTimeout(120)->run();
    }
}
