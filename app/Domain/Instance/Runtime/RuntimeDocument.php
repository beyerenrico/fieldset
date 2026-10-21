<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Strict grammar of runtime.json (KTD11), the only channel from ops to app. The app may not load ops code (KTD24),
 * so this mirrors the relevant rules of the ops-side `State\InputGrammar` instead of importing it. Unknown keys
 * are ignored (a newer minor version may add some); a different major version is refused.
 *
 * - instance_url, local_url: http(s) URLs with a host, optional port and plain path; no userinfo, query, fragment.
 * - extra_hosts: RFC 1123 host names or IP addresses, never a wildcard or a trailing dot.
 * - tunnel.hostname: `^[a-z0-9-]+\.trycloudflare\.com$` with an expiry and cloudflared's address (tunnel.peer).
 * - gate: `raised` is required, so a file without a gate fails closed.
 * - webhooks.paused: optional (missing = not paused); true holds webhook steps after a move (ADR 0085).
 * - instance, updates: optional display and update-check blocks (plan U20, ADR 0089). A malformed one counts as
 *   absent instead of failing the file: the page says "unknown" and the update check stays off.
 */
final class RuntimeDocument
{
    public const int SCHEMA_MAJOR = 1;

    public const string TUNNEL_HOST = '/^[a-z0-9-]+\.trycloudflare\.com$/D';

    public const string SMOKE_TOKEN = '/^[A-Za-z0-9_-]{32,128}$/D';

    private const string LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    private const string OPERATION = '/^[a-z][a-z0-9_-]{0,63}$/D';

    private const string TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D';

    private const string URL_PATH = '/^(?:\/[A-Za-z0-9._~%-]*)*$/D';

    private const string UNSAFE_URL = '/[\x00-\x20\x7F"\'`\\\\$?#<>]/';

    private const int MAX_HOSTS = 20;

    /** @throws InvalidRuntimeState */
    public static function parse(mixed $document): RuntimeState
    {
        if (! is_array($document) || ($document !== [] && array_is_list($document))) { // `{}` decodes to []
            throw new InvalidRuntimeState('runtime.json is not a JSON object');
        }
        self::schemaVersion($document['schema_version'] ?? null);
        $local = $document['local_url'] ?? null;

        return new RuntimeState(
            self::url($document['instance_url'] ?? null, 'instance_url'),
            $local === null ? null : self::url($local, 'local_url'),
            self::hosts($document['extra_hosts'] ?? []),
            self::tunnel($document['tunnel'] ?? null),
            self::gate($document['gate'] ?? null),
            self::webhooksPaused($document['webhooks'] ?? null),
            InstanceFacts::fromDocument($document['instance'] ?? null),
            self::updateManifestUrl($document['updates'] ?? null),
        );
    }

    /** Whether a value is an http(s) URL of the runtime grammar (no userinfo, query, fragment or unsafe character). */
    public static function isUrl(mixed $value): bool
    {
        try {
            self::url($value, 'url');

            return true;
        } catch (InvalidRuntimeState) {
            return false;
        }
    }

    /** RFC 1123 host name (no wildcard, no trailing dot) or an IPv4/IPv6 address. */
    public static function isHost(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > 253) {
            return false;
        }
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        if (preg_match('/^[0-9.]+$/D', $value) === 1) {
            return false;
        }

        return array_all(explode('.', strtolower($value)), static fn (string $label): bool => preg_match(self::LABEL, $label) === 1);
    }

    private static function schemaVersion(mixed $version): void
    {
        if (! is_string($version) || preg_match('/^(\d{1,4})\.\d{1,4}$/D', $version, $match) !== 1) {
            throw new InvalidRuntimeState('schema_version is missing or malformed');
        }
        if ((int) $match[1] !== self::SCHEMA_MAJOR) {
            throw new InvalidRuntimeState("schema_version {$version} is not supported");
        }
    }

    private static function url(mixed $value, string $field): string
    {
        $parts = is_string($value) && strlen($value) <= 2048 && preg_match(self::UNSAFE_URL, $value) !== 1 ? parse_url($value) : false;
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidRuntimeState("{$field} is not an http(s) URL");
        }
        $host = $parts['host'] ?? '';
        $hostOk = str_starts_with($host, '[')
            ? filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            : self::isHost($host) && ! str_contains($host, ':');
        $port = $parts['port'] ?? 443;
        if (! $hostOk || $port < 1 || $port > 65535 || preg_match(self::URL_PATH, $parts['path'] ?? '') !== 1
            || ! str_starts_with((string) $value, $parts['scheme'].'://'.$host)) {
            throw new InvalidRuntimeState("{$field} is not an http(s) URL");
        }

        return rtrim((string) $value, '/');
    }

    /** @return list<string> */
    private static function hosts(mixed $hosts): array
    {
        if (! is_array($hosts) || ! array_is_list($hosts) || count($hosts) > self::MAX_HOSTS || ! array_all($hosts, self::isHost(...))) {
            throw new InvalidRuntimeState('extra_hosts must list host names or IP addresses without wildcards');
        }

        return array_values(array_unique(array_map(HostName::normalize(...), $hosts)));
    }

    private static function tunnel(mixed $tunnel): TunnelState
    {
        if ($tunnel === null) {
            return TunnelState::none();
        }
        if (! is_array($tunnel)) {
            throw new InvalidRuntimeState('tunnel must be an object');
        }
        $hostname = $tunnel['hostname'] ?? null;
        if ($hostname !== null && (! is_string($hostname) || preg_match(self::TUNNEL_HOST, $hostname) !== 1)) {
            throw new InvalidRuntimeState('tunnel.hostname must be a trycloudflare.com host');
        }
        $expiresAt = self::timestamp($tunnel['expires_at'] ?? null, 'tunnel.expires_at');
        if ($hostname !== null && ! $expiresAt instanceof CarbonImmutable) {
            throw new InvalidRuntimeState('tunnel.expires_at is required with a tunnel hostname');
        }
        $peer = $tunnel['peer'] ?? null;
        if ($peer !== null && (! is_string($peer) || filter_var($peer, FILTER_VALIDATE_IP) === false)) {
            throw new InvalidRuntimeState('tunnel.peer must be an IP address');
        }
        // Without the peer, a forged X-Forwarded-Host from a trusted cloudflared would escape the path policy.
        if ($hostname !== null && $peer === null) {
            throw new InvalidRuntimeState('tunnel.peer is required with a tunnel hostname');
        }

        return new TunnelState($hostname, $expiresAt, $peer, self::flag($tunnel['admin'] ?? false, 'tunnel.admin'));
    }

    private static function gate(mixed $gate): GateState
    {
        if (! is_array($gate) || ! is_bool($gate['raised'] ?? null)) {
            throw new InvalidRuntimeState('gate.raised is missing');
        }
        $operation = $gate['operation'] ?? null;
        if ($operation !== null && (! is_string($operation) || preg_match(self::OPERATION, $operation) !== 1)) {
            throw new InvalidRuntimeState('gate.operation must be a plain identifier');
        }
        $token = $gate['smoke_token'] ?? null;
        if ($token !== null && (! is_string($token) || preg_match(self::SMOKE_TOKEN, $token) !== 1)) {
            throw new InvalidRuntimeState('gate.smoke_token is malformed');
        }

        return new GateState($gate['raised'], $operation, self::timestamp($gate['since'] ?? null, 'gate.since'), $token);
    }

    /** `webhooks.paused` (U17, ADR 0085): written by `fieldset install --from-backup` until the operator confirms the move. */
    private static function webhooksPaused(mixed $webhooks): bool
    {
        if ($webhooks === null) {
            return false;
        }
        if (! is_array($webhooks) || ! array_key_exists('paused', $webhooks)) {
            throw new InvalidRuntimeState('webhooks.paused is missing');
        }

        return self::flag($webhooks['paused'], 'webhooks.paused');
    }

    /**
     * `updates` (plan U20, E15, ADR 0089): the release manifest URL the daily update check reads, only when the check is
     * on (`fieldset config set update-check on`); anything else, a malformed block included, keeps the check off.
     */
    private static function updateManifestUrl(mixed $updates): ?string
    {
        if (! is_array($updates) || ($updates['check'] ?? null) !== true) {
            return null;
        }
        $url = $updates['manifest_url'] ?? null;

        return self::isUrl($url) && is_string($url) ? $url : null;
    }

    private static function timestamp(mixed $value, string $field): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || preg_match(self::TIMESTAMP, $value) !== 1) {
            throw new InvalidRuntimeState("{$field} must be an ISO 8601 time");
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new InvalidRuntimeState("{$field} must be an ISO 8601 time");
        }
    }

    private static function flag(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new InvalidRuntimeState("{$field} must be true or false");
        }

        return $value;
    }
}
