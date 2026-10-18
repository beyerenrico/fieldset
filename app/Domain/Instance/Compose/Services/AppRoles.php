<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose\Services;

use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Compose\ProxyLabels;
use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\ServiceDefinition;
use App\Domain\Instance\Compose\ServiceEnvironments;
use App\Domain\Instance\Compose\ServiceSpec;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\RuntimeWriter;

/**
 * app, queue and scheduler: one image (ADR 0020), as in compose.yaml. All three mount the runtime directory
 * read-only (KTD11); one-offs run the app service, so they get its mounts and env. None sets RUN_MIGRATIONS:
 * migrations run only in supervised one-offs (KTD6).
 *
 * Mode extension points: `ports()` publishes the loopback port in local mode and in proxy mode's `port` setup;
 * on a shared proxy network (U9, ProxyLabels) only the app joins that external network, with the proxy's labels;
 * domain mode (U8) publishes nothing because the edge does.
 */
final readonly class AppRoles implements ServiceDefinition
{
    public const string APP = 'app';

    public const string QUEUE = 'queue';

    public const string SCHEDULER = 'scheduler';

    /** Uploads and generated files; uninstall's final backup archives it. */
    public const string STORAGE_VOLUME = 'app-storage';

    public const string STORAGE_PATH = '/var/www/html/storage/app';

    public function specs(RenderContext $context): array
    {
        $env = new ServiceEnvironments($context);
        $volumes = [self::STORAGE_VOLUME => []];

        return [
            new ServiceSpec(self::APP, [
                ...$this->base($context, ['default', 'renderer', ...array_keys(ProxyLabels::network($context->settings))]),
                ...$this->ports($context),
                ...$this->proxyLabels($context),
                'healthcheck' => ['test' => ['CMD', 'curl', '-fsS', 'http://localhost:8080/up'], 'interval' => '15s', 'timeout' => '5s', 'retries' => 5],
            ], $env->appRole(self::APP), $volumes, ProxyLabels::network($context->settings)),
            new ServiceSpec(self::QUEUE, [
                ...$this->base($context, ['default', 'renderer']),
                'command' => ['php', 'artisan', 'horizon'],
                'healthcheck' => ['test' => ['CMD', 'php', 'artisan', 'horizon:status'], 'interval' => '30s', 'timeout' => '10s', 'retries' => 3],
            ], $env->appRole(self::QUEUE), $volumes),
            new ServiceSpec(self::SCHEDULER, [
                ...$this->base($context, ['default']),
                'command' => ['php', 'artisan', 'schedule:work'],
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
