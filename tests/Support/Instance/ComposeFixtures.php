<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Compose\ImageRole;
use App\Domain\Instance\Compose\RenderContext;
use App\Domain\Instance\Compose\RenderedProject;
use App\Domain\Instance\Compose\ServiceDefinition;
use App\Domain\Instance\Compose\ServiceSpec;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceState;

/** Instance states, secrets and rendered-document helpers for the compose renderer tests (plan U3). */
final class ComposeFixtures
{
    public const string UUID = '3f1c2b9e-8a4d-4c1e-9b7a-2d5e6f708192';

    public const string APP_KEY = 'base64:QXBwS2V5U2VjcmV0VmFsdWVGb3JUZXN0czEyMzQ1Njc=';

    public const string DB_PASSWORD = 'db0secret0password0value0000000000000000000000';

    public const string VALKEY_PASSWORD = 'valkey0secret0value000000000000000000000000000';

    public const string CATCHER_PASSWORD = 'catcher0secret0value00000000000000000000000000';

    public const string BACKUP_DB_PASSWORD = 'backup0dump0role0secret0000000000000000000000';

    public const string SMTP_PASSWORD = "smtp 'pass' \$word";

    /** @return array<string, string> one pinned digest per image role */
    public static function digests(): array
    {
        $digests = [];
        foreach (ImageRole::cases() as $index => $role) {
            $digests[$role->value] = 'registry.test:5000/fsa/'.$role->value.'@sha256:'.str_repeat(dechex($index + 1), 64);
        }

        return $digests;
    }

    /** @param  array<string, string|int|bool|null>  $settings */
    public static function state(array $settings = [], string $path = '/srv/fsa'): InstanceState
    {
        $state = InstanceState::create(self::UUID, $path, 1000, 1000, 1000)->withRelease('2.0.0', self::digests());

        return $state->withSettings($settings);
    }

    /** The proxy's address on the shared network in proxyState(). */
    public const string PROXY_ADDRESS = '172.20.0.5';

    /**
     * A proxy-mode state (ADR 0076): traefik or caddy on the shared network `edge-net` with the proxy's address, or
     * the `port` setup.
     *
     * @param  array<string, string|int|bool|null>  $settings
     */
    public static function proxyState(string $setup = 'traefik', string $url = 'https://forms.example.com', array $settings = []): InstanceState
    {
        $network = $setup === 'port' ? [] : ['proxy_network' => 'edge-net', 'proxy_container' => 'edge-proxy-1'];

        return self::state()->withMode('proxy')->withUrl($url)
            ->withSettings(['proxy' => $setup, 'trusted_proxies' => self::PROXY_ADDRESS, ...$network, ...$settings]);
    }

    public static function secrets(): InstanceSecrets
    {
        return new InstanceSecrets(self::APP_KEY, self::DB_PASSWORD, self::VALKEY_PASSWORD, self::CATCHER_PASSWORD, self::BACKUP_DB_PASSWORD);
    }

    /** @param  array<string, string|int|bool|null>  $settings */
    public static function render(array $settings = [], string $path = '/srv/fsa'): RenderedProject
    {
        return (new ComposeRenderer)->render(self::state($settings, $path), self::secrets());
    }

    /** A renderer with the default services plus extra specs, as later units (edge, tunnel, backup) add them. */
    public static function rendererWith(ServiceSpec ...$specs): ComposeRenderer
    {
        $extra = new readonly class($specs) implements ServiceDefinition
        {
            /** @param  list<ServiceSpec>  $specs */
            public function __construct(private array $specs) {}

            public function specs(RenderContext $context): array
            {
                return $this->specs;
            }
        };

        return new ComposeRenderer([...ComposeRenderer::defaultDefinitions(), $extra]);
    }

    /** @return array<string, string|int|bool|null> */
    public static function smtpSettings(): array
    {
        return ['mail' => 'smtp', 'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'mail_from_address' => 'forms@example.com'];
    }

    /** @return list<string> every secret value the fixtures use */
    public static function secretValues(): array
    {
        return [self::APP_KEY, self::DB_PASSWORD, self::VALKEY_PASSWORD, self::CATCHER_PASSWORD, self::BACKUP_DB_PASSWORD, self::SMTP_PASSWORD];
    }

    /**
     * Every label map in a compose document: services, volumes and networks.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, array<string, string>> "services.app" => labels
     */
    public static function labelsByResource(array $document): array
    {
        $labels = [];
        foreach (['services', 'volumes', 'networks'] as $kind) {
            foreach ($document[$kind] ?? [] as $name => $definition) {
                $labels["{$kind}.{$name}"] = is_array($definition) ? ($definition['labels'] ?? []) : [];
            }
        }

        return $labels;
    }
}
