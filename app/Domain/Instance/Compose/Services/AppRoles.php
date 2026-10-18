<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose\Services;

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Compose\ProxyLabels;
use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\ServiceDefinition;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Compose\ServiceSpec;
use App\Domain\Instance\Compose\TunnelNetwork;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\RuntimeWriter;

/**
 * app, queue and scheduler: one image (ADR 0020), as in compose.yaml. All three mount the runtime directory
 * read-only (KTD11); one-offs run the app service, so they get its mounts and env. None sets RUN_MIGRATIONS:
 * migrations run only in supervised one-offs (KTD6).
 *
 * Mode extension points: `ports()` publishes the loopback port in local mode and in proxy mode's `port` setup;
 * on a shared proxy network (U9, ProxyLabels) only the app joins that external network, with the proxy's labels;
 * domain mode (U8) publishes nothing because the edge does. Local-CA mode keeps the loopback port and adds the edge;
 * a quick tunnel (U14) adds the tunnel network.
 */
final readonly class AppRoles implements ServiceDefinition
{
    public const string APP = 'app';

    public const string QUEUE = 'queue';

    public const string SCHEDULER = 'scheduler';

    /** FrankenPHP's plain-HTTP port inside the stack (ADR 0052); the edge proxies to it. */
    public const int PORT = 8080;

    /** Uploads and generated files; uninstall's final backup archives it. */
    public const string STORAGE_VOLUME = 'app-storage';

    public const string STORAGE_PATH = '/var/www/html/storage/app';

    /**
     * Seconds Compose waits after SIGTERM before it kills queue and scheduler: above the longest job timeout
     * (config/horizon.php: 60 s on `default`, 90 s on `exports`, tries 1), so a stop for a settings change or an
     * upgrade lets running jobs finish instead of killing them half-way. Horizon and `schedule:work` both stop taking
     * new work on SIGTERM and exit once the running work is done.
     */
    public const int STOP_GRACE_SECONDS = 100;

    public function specs(RenderContext $context): array
    {
        $env = new ServiceEnvironments($context);
        $volumes = [self::STORAGE_VOLUME => []];

        return [
            new ServiceSpec(self::APP, [
                ...$this->base($context, ['default', 'renderer', ...$this->edgeNetwork($context), ...array_keys(ProxyLabels::network($context->settings))]),
                ...$this->ports($context),
                ...$this->proxyLabels($context),
                'healthcheck' => ['test' => ['CMD', 'curl', '-fsS', 'http://localhost:8080/up'], 'interval' => '15s', 'timeout' => '5s', 'retries' => 5],
            ], $env->appRole(self::APP), $volumes, ProxyLabels::network($context->settings)),
            new ServiceSpec(self::QUEUE, [
                ...$this->base($context, ['default', 'renderer']),
                'command' => ['php', 'artisan', 'horizon'],
                'stop_grace_period' => self::STOP_GRACE_SECONDS.'s',
                'healthcheck' => ['test' => ['CMD', 'php', 'artisan', 'horizon:status'], 'interval' => '30s', 'timeout' => '10s', 'retries' => 3],
            ], $env->appRole(self::QUEUE), $volumes),
            new ServiceSpec(self::SCHEDULER, [
                ...$this->base($context, ['default']),
                'command' => ['php', 'artisan', 'schedule:work'],
                'stop_grace_period' => self::STOP_GRACE_SECONDS.'s',
                'healthcheck' => ['disable' => true],
            ], $env->appRole(self::SCHEDULER), $volumes),
        ];
    }

    /**
     * @param  list<string>  $networks
     * @return array<string, mixed>
     */
    private function base(RenderContext $context, array $networks): array
    {
        return [
            'image' => $context->image(ImageRole::App),
            'volumes' => [
                ['type' => 'volume', 'source' => self::STORAGE_VOLUME, 'target' => self::STORAGE_PATH],
                [
                    'type' => 'bind', 'source' => './'.InstanceDirectory::RUNTIME_DIR, 'target' => RuntimeWriter::CONTAINER_PATH,
                    'read_only' => true, 'bind' => ['create_host_path' => false],
                ],
            ],
            'depends_on' => ['postgres' => ['condition' => 'service_healthy'], 'redis' => ['condition' => 'service_healthy']],
            'networks' => $networks,
        ];
    }

    /**
     * Domain and local-CA mode: the app joins the edge network, where the edge's fixed address reaches it; with a quick
     * tunnel it joins the tunnel network, where cloudflared's fixed address reaches it (KTD12, ADR 0082).
     *
     * @return list<string>
     */
    private function edgeNetwork(RenderContext $context): array
    {
        return [
            ...($context->settings->rendersEdge() ? [EdgeNetwork::NAME] : []),
            ...($context->settings->tunnel() && $context->settings->tunnelSubnet() !== null ? [TunnelNetwork::NAME] : []),
        ];
    }

    /** @return array<string, mixed> */
    private function ports(RenderContext $context): array
    {
        return $context->settings->publishesAppPort()
            ? ['ports' => [RenderContext::loopbackPort($context->settings->port(), 8080)]]
            : [];
    }

    /** @return array<string, mixed> Traefik or caddy-docker-proxy labels on a shared proxy network (U9) */
    private function proxyLabels(RenderContext $context): array
    {
        $labels = ProxyLabels::for($context);

        return $labels === [] ? [] : ['labels' => $labels];
    }
}
