<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose\Services;

use App\Domain\Instance\Compose\CaddyfileRenderer;
use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Compose\EdgeSite;
use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Compose\LocalCaSite;
use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\ServiceDefinition;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Compose\ServiceSpec;
use App\Domain\Instance\State\InvalidState;

/**
 * The edge in domain mode (E6, plan KTD10, R16; ADR 0052, ADR 0075): the official Caddy image, pinned by digest,
 * terminating TLS with automatic HTTPS and proxying to the app. Only it publishes ports: 80, 443 and 443/udp.
 *
 * - Non-root (a fixed UID) with every capability dropped except NET_BIND_SERVICE, no new privileges, a read-only root
 *   file system with tmpfs for /tmp and Caddy's config directory (config autosave is off anyway).
 * - Certificates, ACME account keys and locks live on the labelled volume `edge-data` at /data/caddy, which the image
 *   creates 1777, so a new volume is writable for the non-root UID. Its name is fixed, so the certificates survive
 *   re-renders, `config set` and mode switches; only uninstall removes it.
 * - The generated Caddyfile sits in the instance's `edge/` directory (0755, file 0644, no secret), mounted read-only
 *   as a directory so an atomic rewrite is seen; its hash, with the custom ACME CA root's, is a label, so a changed
 *   Caddyfile or root recreates the edge.
 * - A fixed address on the edge network, which the app trusts as its only proxy (ServiceEnvironments).
 * - Starts once the app is healthy; its healthcheck asks a loopback-only site inside the container.
 *
 * Local-CA mode (R19, ADR 0082) uses the same service in local mode: Caddy's internal CA instead of ACME, and 80 and
 * 443/tcp published on the LAN address only (never 0.0.0.0). The CA's key lives on the same certificate volume
 * (`pki/`), which `fsa config set phone-https off` deletes (LocalCaStore).
 */
final readonly class EdgeService implements ServiceDefinition
{
    public const string NAME = 'edge';

    /** Instance-directory folder with the Caddyfile and the optional custom ACME CA root. */
    public const string DIRECTORY = 'edge';

    public const string CERTIFICATE_VOLUME = 'edge-data';

    public const string DATA_PATH = '/data/caddy';

    /** A fixed non-root UID/GID (the image's /data/caddy and /config/caddy are 1777). */
    public const string USER = '65532:65532';

    /** Hash of the rendered Caddyfile: Compose recreates the edge when it changes. */
    public const string CONFIG_LABEL = 'app.form-schema.edge-config';

    public function specs(RenderContext $context): array
    {
        if (! $context->settings->rendersEdge()) {
            return [];
        }
        $localCa = $context->settings->localCa();
        $subnet = $context->settings->edgeSubnet() ?? throw new InvalidState('The edge needs the setting [edge_subnet].');
        $caddyfile = new CaddyfileRenderer()->render($localCa ? LocalCaSite::fromSettings($context->settings) : EdgeSite::fromSettings($context->settings));

        return [new ServiceSpec(self::NAME, [
            'image' => $context->image(ImageRole::Caddy),
            'command' => ['caddy', 'run', '--config', CaddyfileRenderer::CONTAINER_DIR.'/'.CaddyfileRenderer::FILE, '--adapter', 'caddyfile'],
            'user' => self::USER,
            'read_only' => true,
            'tmpfs' => ['/tmp', '/config/caddy'],
            'cap_drop' => ['ALL'],
            'cap_add' => ['NET_BIND_SERVICE'],
            'security_opt' => ['no-new-privileges:true'],
            'ports' => $localCa ? $this->lanPorts((string) $context->settings->lanAddress()) : [$this->port(80, 'tcp'), $this->port(443, 'tcp'), $this->port(443, 'udp')],
            'volumes' => [
                ['type' => 'volume', 'source' => self::CERTIFICATE_VOLUME, 'target' => self::DATA_PATH],
                ['type' => 'bind', 'source' => './'.self::DIRECTORY, 'target' => CaddyfileRenderer::CONTAINER_DIR, 'read_only' => true, 'bind' => ['create_host_path' => false]],
            ],
            'networks' => [EdgeNetwork::NAME => ['ipv4_address' => EdgeNetwork::edgeAddress($subnet)]],
            'depends_on' => [AppRoles::APP => ['condition' => 'service_healthy']],
            'labels' => [self::CONFIG_LABEL => self::configHash($caddyfile, $context->settings->acmeCaRoot())],
            'healthcheck' => [
                'test' => ['CMD', 'wget', '-q', '-O', '/dev/null', 'http://127.0.0.1:'.CaddyfileRenderer::HEALTH_PORT.CaddyfileRenderer::HEALTH_PATH],
                'interval' => '30s', 'timeout' => '5s', 'retries' => 3, 'start_period' => '10s',
            ],
        ], new ServiceEnvironments($context)->edge(),
            volumes: [self::CERTIFICATE_VOLUME => ['name' => self::volumeName($context->state->project())]],
            networks: [EdgeNetwork::NAME => ['ipam' => ['config' => [EdgeNetwork::ipam($subnet)]]]],
            files: [self::DIRECTORY.'/'.CaddyfileRenderer::FILE => $caddyfile],
        )];
    }

    /**
     * What the edge reads at start: the Caddyfile and, when configured, the custom ACME CA root next to it (its
     * SHA-256 from the state). The Caddyfile only names the root's path, so without its hash a root-only change would
     * never reach the running edge (ADR 0079). Without a root the label stays the Caddyfile's plain hash.
     */
    public static function configHash(string $caddyfile, ?string $acmeCaRoot): string
    {
        return hash('sha256', $acmeCaRoot === null ? $caddyfile : $caddyfile."\nacme-ca-root sha256 ".$acmeCaRoot);
    }

    /** The certificate volume's Docker name: fixed per instance, so nothing renames it. */
    public static function volumeName(string $project): string
    {
        return $project.'_'.self::CERTIFICATE_VOLUME;
    }

    /**
     * Local-CA mode: 80 and 443/tcp on the LAN address only (R19); no HTTP/3.
     *
     * @return list<array{target: int, published: string, host_ip: string, protocol: string}>
     */
    private function lanPorts(string $address): array
    {
        return array_map(static fn (int $port): array => ['target' => $port, 'published' => (string) $port, 'host_ip' => $address, 'protocol' => 'tcp'], [80, 443]);
    }

    /** @return array{target: int, published: string, protocol: string} published on every host address */
    private function port(int $port, string $protocol): array
    {
        return ['target' => $port, 'published' => (string) $port, 'protocol' => $protocol];
    }
}
