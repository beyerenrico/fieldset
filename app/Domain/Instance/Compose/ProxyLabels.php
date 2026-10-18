<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\State\InstanceSettings;

/**
 * Proxy mode on a shared Docker network (R17, ADR 0076): the app joins the proxy's external network and carries
 * the labels the proxy routes by. Only the app does; Postgres, Valkey, Gotenberg and Mailpit stay on the
 * instance's own networks.
 *
 * - Traefik (docker provider): a router named after the compose project (unique on one Traefik), `Host()` from
 *   `--url`, `tls=true` for an https URL (certificates come from the entrypoint's resolver or Traefik's default),
 *   the app's port 8080, and `traefik.docker.network`, so Traefik reaches the app over the shared network and not
 *   over one of the instance's own.
 * - caddy-docker-proxy: the URL's origin as the site address (automatic HTTPS for https) and
 *   `reverse_proxy {{upstreams 8080}}`; `caddy_ingress_network` names the shared network.
 *
 * The values come from the validated state (InputGrammar), so no label carries user text beyond a host name.
 */
final class ProxyLabels
{
    /** The compose key of the shared external network (its real name is the `proxy_network` setting). */
    public const string NETWORK = 'proxy';

    public const int APP_PORT = 8080;

    /** @return array<string, string> */
    public static function for(RenderContext $context): array
    {
        $settings = $context->settings;
        if (! $settings->joinsProxyNetwork()) {
            return [];
        }

        return match ($settings->proxy()) {
            'traefik' => self::traefik($context->state->project(), $settings),
            default => self::caddy($settings),
        };
    }

    /**
     * The top-level declaration of the shared network: external, so Compose never creates, labels or removes it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function network(InstanceSettings $settings): array
    {
        return $settings->joinsProxyNetwork()
            ? [self::NETWORK => ['external' => true, 'name' => (string) $settings->proxyNetwork()]]
            : [];
    }

    /** @return array<string, string> */
    private static function traefik(string $router, InstanceSettings $settings): array
    {
        $url = $settings->instanceUrl();
        $prefix = "traefik.http.routers.{$router}";

        return [
            'traefik.enable' => 'true',
            'traefik.docker.network' => (string) $settings->proxyNetwork(),
            "{$prefix}.rule" => 'Host(`'.parse_url($url, PHP_URL_HOST).'`)',
            "{$prefix}.service" => $router,
            ...(str_starts_with($url, 'https://') ? ["{$prefix}.tls" => 'true'] : []),
            "traefik.http.services.{$router}.loadbalancer.server.port" => (string) self::APP_PORT,
        ];
    }

    /** @return array<string, string> */
    private static function caddy(InstanceSettings $settings): array
    {
        return [
            'caddy' => rtrim($settings->instanceUrl(), '/'),
            'caddy.reverse_proxy' => '{{upstreams '.self::APP_PORT.'}}',
            'caddy_ingress_network' => (string) $settings->proxyNetwork(),
        ];
    }
}
