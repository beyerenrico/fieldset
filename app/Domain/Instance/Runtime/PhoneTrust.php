<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Illuminate\Contracts\Config\Repository;

/**
 * What the phone trust page shows in local-CA mode (R19, ADR 0082): the instance's HTTPS address and the local CA's
 * root. Exists only when the runtime state is there, its instance URL is exactly `https://<IPv4 address>` (the edge's
 * certificate is issued for the LAN address) and `local-ca-root.crt` is an acceptable root; otherwise the page and
 * the download answer 404, so local, domain and proxy mode never offer a CA.
 */
final readonly class PhoneTrust
{
    public function __construct(
        public string $instanceUrl,
        public LocalCaRoot $root,
    ) {}

    public static function current(RuntimeStateReader $reader, Repository $config): ?self
    {
        $state = $reader->current();
        if (! $state instanceof RuntimeState || ! self::isLocalCaUrl($state->instanceUrl)) {
            return null;
        }
        $root = LocalCaRoot::fromRuntime($config);

        return $root instanceof LocalCaRoot ? new self($state->instanceUrl, $root) : null;
    }

    /** `https://` and an IPv4 address, without port or path. */
    public static function isLocalCaUrl(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && rtrim($url, '/') === 'https://'.$host;
    }

    public function host(): string
    {
        return HostName::fromUrl($this->instanceUrl);
    }

    /**
     * The Field App on the instance's HTTPS address, with the trailing slash: its service worker's scope is `/app/`,
     * and `/app` itself lies outside it (no service worker would control the page, `serviceWorker.ready` never settles).
     */
    public function fieldAppUrl(): string
    {
        return rtrim($this->instanceUrl, '/').'/app/';
    }

    /**
     * Where the trust check loads an image to learn whether the phone accepts the instance's certificate: the Field
     * App's icon, a real image the edge serves statically (the app's favicon.ico is empty, which an image load reports
     * as an error even over a trusted connection).
     */
    public function probeUrl(): string
    {
        return rtrim($this->instanceUrl, '/').'/app/icons/icon-192.png';
    }
}
