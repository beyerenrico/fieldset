<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseVersion;
use App\Domain\Instance\State\InputGrammar;
use Closure;

/**
 * The inputs of `fsa install` (§7, R26): the flags given on the command line, validated, plus their defaults.
 * Only explicit flags count when comparing with an earlier run: an omitted flag never conflicts.
 *
 * - App flags (organization, owner, locale) are consumed by the `app_install` phase: corrections are accepted
 *   until it has run.
 * - Preflight flags (version, port, domain, and proxy mode's flags) are consumed by the `doctor` phase.
 * - Proxy mode (R17, ADR 0076): `--behind-proxy traefik|caddy|port` with `--url`; traefik and caddy also take
 *   `--proxy-network` (required) and `--trusted-proxy` (the proxy's container or address on that network).
 * - `--demo` (R29, ADR 0081) is consumed by the `demo` phase; it is recorded as 1 when given.
 */
final readonly class InstallRequest
{
    public const array APP_FLAGS = ['organization', 'owner_email', 'owner_name', 'locale'];

    public const array PREFLIGHT_FLAGS = ['version', 'port', 'domain', ...self::ACME_FLAGS, ...self::PROXY_FLAGS];

    /** Domain-mode inputs (U8): meaningless, and refused, without --domain. */
    public const array ACME_FLAGS = ['acme_email', 'acme_ca', 'acme_ca_root'];

    public const array PROXY_FLAGS = ['proxy', 'url', 'proxy_network', 'trusted_proxy'];

    /** Proxy setups that join the proxy's Docker network (the others publish a loopback port). */
    public const array NETWORK_PROXIES = ['traefik', 'caddy'];

    public const array REQUIRED = ['organization', 'owner_email'];

    public const array LOCALES = ['de', 'en'];

    public const string DEFAULT_LOCALE = 'de';

    /** Request key => command-line flag. */
    public const array FLAGS = [
        'organization' => '--org', 'owner_email' => '--owner-email', 'owner_name' => '--owner-name', 'locale' => '--locale',
        'version' => '--version', 'port' => '--port', 'domain' => '--domain',
        'acme_email' => '--acme-email', 'acme_ca' => '--acme-ca', 'acme_ca_root' => '--acme-ca-root-file',
        'proxy' => '--behind-proxy', 'url' => '--url', 'proxy_network' => '--proxy-network', 'trusted_proxy' => '--trusted-proxy',
        'demo' => '--demo',
    ];

    /** Command option => request key (the fsa wrapper passes --version as --release, ADR 0072). */
    private const array OPTIONS = [
        'org' => 'organization', 'owner-email' => 'owner_email', 'owner-name' => 'owner_name', 'locale' => 'locale',
        'release' => 'version', 'port' => 'port', 'domain' => 'domain', 'acme-email' => 'acme_email', 'acme-ca' => 'acme_ca',
        'behind-proxy' => 'proxy', 'url' => 'url', 'proxy-network' => 'proxy_network', 'trusted-proxy' => 'trusted_proxy',
    ];

    private const int MAX_NAME = 120;

    /**
     * @param  array<string, string|int>  $explicit  request key => validated value
     * @param  string|null  $caRoot  the PEM of `--acme-ca-root-file` (its SHA-256 is the `acme_ca_root` value)
     */
    private function __construct(private array $explicit, private ?string $caRoot = null) {}

    /**
     * @param  array<string, mixed>  $options  command options by name
     *
     * @throws InvalidInput for a malformed value (exit 2)
     */
    public static function fromOptions(array $options): self
    {
        $request = new self([]);
        foreach (self::OPTIONS as $option => $key) {
            $value = $options[$option] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $request = $request->with($key, $value);
            }
        }

        return $request;
    }

    /** @throws InvalidInput */
    public function with(string $key, string $value): self
    {
        return new self([...$this->explicit, $key => $this->validate($key, trim($value))], $this->caRoot);
    }

    /** `--demo`: the demo organization, forms, workflow and users after the stack started (R29). */
    public function withDemo(): self
    {
        return new self([...$this->explicit, 'demo' => 1], $this->caRoot);
    }

    public function demo(): bool
    {
        return ($this->explicit['demo'] ?? null) === 1;
    }

    /** The custom ACME CA root (validated PEM, AcmeCaRoot): recorded as its SHA-256, written by the state phase. */
    public function withCaRoot(string $pem): self
    {
        return new self([...$this->explicit, 'acme_ca_root' => hash('sha256', $pem)], $pem);
    }

    /** The PEM given with --acme-ca-root-file in this run; null on a resume without the flag. */
    public function caRoot(): ?string
    {
        return $this->caRoot;
    }

    /** @return array<string, string> the domain-mode inputs given (or recorded), by request key */
    public function acme(): array
    {
        return array_filter(array_intersect_key($this->explicit, array_flip(self::ACME_FLAGS)), is_string(...));
    }

    /** @return array<string, string|int> */
    public function explicit(): array
    {
        return $this->explicit;
    }

    /** @return list<string> required request keys that are not set */
    public function missing(): array
    {
        return array_values(array_filter(self::REQUIRED, fn (string $key): bool => ! array_key_exists($key, $this->explicit)));
    }

    /**
     * This request over the values an earlier run recorded (resume): what was given then still applies.
     *
     * @param  array<string, mixed>  $recorded
     */
    public function over(array $recorded): self
    {
        $known = array_filter(
            array_intersect_key($recorded, self::FLAGS),
            static fn (mixed $value): bool => is_string($value) || is_int($value),
        );

        return new self([...$known, ...$this->explicit], $this->caRoot);
    }

    /**
     * Explicit flags whose value differs from what was already applied. A key absent from `$applied` was not
     * applied yet and never conflicts; a null value was applied as "not set".
     *
     * @param  array<string, string|int|null>  $applied
     * @param  (Closure(string, string|int|null, string|int): bool)|null  $equals  e.g. digests for personal inputs
     * @return list<string> command-line flags
     */
    public function changedFlags(array $applied, ?Closure $equals = null): array
    {
        $equals ??= static fn (string $key, string|int|null $recorded, string|int $given): bool => (string) $recorded === (string) $given;
        $changed = array_filter(
            $this->explicit,
            static fn (string|int $value, string $key): bool => array_key_exists($key, $applied) && ! $equals($key, $applied[$key], $value),
            ARRAY_FILTER_USE_BOTH,
        );

        return array_values(array_map(static fn (string $key): string => self::FLAGS[$key], array_keys($changed)));
    }

    public function organization(): string
    {
        return $this->string('organization');
    }

    public function ownerEmail(): string
    {
        return $this->string('owner_email');
    }

    /** The owner e-mail when it was given (or recorded on resume), else null. */
    public function ownerEmailIfKnown(): ?string
    {
        return is_string($this->explicit['owner_email'] ?? null) ? $this->explicit['owner_email'] : null;
    }

    /** The owner's name; without --owner-name the part of the email before the @. */
    public function ownerName(): string
    {
        return is_string($this->explicit['owner_name'] ?? null) ? $this->explicit['owner_name'] : explode('@', $this->ownerEmail())[0];
    }

    public function locale(): string
    {
        return is_string($this->explicit['locale'] ?? null) ? $this->explicit['locale'] : self::DEFAULT_LOCALE;
    }

    public function port(): ?int
    {
        return is_int($this->explicit['port'] ?? null) ? $this->explicit['port'] : null;
    }

    public function version(): ?string
    {
        return is_string($this->explicit['version'] ?? null) ? $this->explicit['version'] : null;
    }

    public function domain(): ?string
    {
        return is_string($this->explicit['domain'] ?? null) ? $this->explicit['domain'] : null;
    }

    /** Proxy mode's setup: traefik, caddy or port; null for local mode. */
    public function proxy(): ?string
    {
        return $this->optional('proxy');
    }

    public function url(): ?string
    {
        return $this->optional('url');
    }

    public function proxyNetwork(): ?string
    {
        return $this->optional('proxy_network');
    }

    /** The proxy's container name or ID, or its address, on the shared network; null = find it by its image. */
    public function trustedProxy(): ?string
    {
        return $this->optional('trusted_proxy');
    }

    /**
     * The flags fit together (exit 2 otherwise): proxy mode needs --url, traefik and caddy a network; proxy and
     * domain mode exclude each other (R61); network flags only exist on a shared network, --port only where the app
     * publishes one.
     *
     * @throws InvalidInput
     */
    public function assertConsistent(): void
    {
        $proxy = $this->proxy();
        $onNetwork = in_array($proxy, self::NETWORK_PROXIES, true);
        $conflict = match (true) {
            $proxy !== null && $this->domain() !== null => ['--behind-proxy and --domain exclude each other.', 'Choose one mode: the edge with automatic HTTPS (--domain) or your own proxy (--behind-proxy).'],
            $proxy === null && $this->url() !== null => ['--url belongs to proxy mode.', 'Add --behind-proxy traefik|caddy|port, or leave --url out (local mode uses http://localhost:<port>).'],
            ! $onNetwork && ($this->proxyNetwork() !== null || $this->trustedProxy() !== null) => ['--proxy-network and --trusted-proxy belong to --behind-proxy traefik or caddy.', 'Leave them out, or choose --behind-proxy traefik or caddy.'],
            $onNetwork && $this->port() !== null => ['On a shared proxy network the app publishes no port, so --port does not apply.', 'Leave --port out, or use --behind-proxy port to publish a loopback port.'],
            default => null,
        };
        if ($conflict !== null) {
            throw new InvalidInput($conflict[0], 'invalid_combination', $conflict[1]);
        }
        $missing = array_values(array_filter([
            $proxy !== null && $this->url() === null ? '--url' : null,
            $onNetwork && $this->proxyNetwork() === null ? '--proxy-network' : null,
        ]));
        if ($missing !== []) {
            throw new InstanceException('Proxy mode needs '.implode(' and ', $missing).'.', ExitCode::InvalidInput, 'input_required', 'Pass '.implode(' and ', $missing).', e.g. --url https://forms.example.com --proxy-network <the network your proxy uses>.', false, ['missing' => $missing]);
        }
    }

    /**
     * What the install journal records: the app flags with their defaults, the preflight flags as given.
     *
     * @return array<string, string|int|null>
     */
    public function toContext(): array
    {
        return [
            'organization' => $this->explicit['organization'] ?? null,
            'owner_email' => $this->explicit['owner_email'] ?? null,
            'owner_name' => isset($this->explicit['owner_email']) ? $this->ownerName() : null,
            'locale' => $this->locale(),
            'version' => $this->version(),
            'port' => $this->port(),
            'domain' => $this->domain(),
            ...array_fill_keys(self::ACME_FLAGS, null),
            ...$this->acme(),
            'proxy' => $this->proxy(),
            'url' => $this->url(),
            'proxy_network' => $this->proxyNetwork(),
            'trusted_proxy' => $this->trustedProxy(),
            'demo' => $this->demo() ? 1 : null,
        ];
    }

    private function optional(string $key): ?string
    {
        return is_string($this->explicit[$key] ?? null) ? $this->explicit[$key] : null;
    }

    private function string(string $key): string
    {
        $value = $this->explicit[$key] ?? null;

        return is_string($value) ? $value : throw new InstanceException("The install has no value for {$key}.");
    }

    /** @throws InvalidInput */
    private function validate(string $key, string $value): string|int
    {
        $flag = self::FLAGS[$key] ?? throw new InstanceException("Unknown install input [{$key}].");
        $value = $key === 'url' ? strtolower(rtrim($value, '/')) : $value;
        $valid = match ($key) {
            'organization', 'owner_name' => $value !== '' && mb_strlen($value) <= self::MAX_NAME && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1 && preg_match('//u', $value) === 1,
            'owner_email' => InputGrammar::isEmail(strtolower($value)),
            'locale' => in_array($value, self::LOCALES, true),
            'port' => ctype_digit($value) && InputGrammar::isPort((int) $value),
            'version' => $this->isVersion($value),
            'acme_email' => InputGrammar::isEmail(strtolower($value)),
            'acme_ca' => InputGrammar::isHttpsUrl($value),
            'acme_ca_root' => preg_match('/^[0-9a-f]{64}$/D', $value) === 1,
            'proxy' => in_array($value, InputGrammar::PROXIES, true),
            'url' => $this->isProxyUrl($value),
            'proxy_network' => InputGrammar::isDockerName($value),
            'trusted_proxy' => InputGrammar::isIp($value) || InputGrammar::isDockerName($value),
            default => InputGrammar::isHost(strtolower($value)) && ! InputGrammar::isIp($value) && str_contains($value, '.'),
        };
        if (! $valid) {
            throw new InvalidInput("Invalid value for {$flag}.", 'invalid_flag', $this->hint($key));
        }

        return match ($key) {
            'port' => (int) $value,
            'owner_email', 'domain', 'acme_email' => strtolower($value),
            default => $value,
        };
    }

    /** An http(s) origin: routing under a path prefix is not supported (ADR 0074), so no path but `/`. */
    private function isProxyUrl(string $value): bool
    {
        $path = parse_url($value, PHP_URL_PATH);

        return InputGrammar::isUrl($value) && in_array($path, [null, '', '/'], true);
    }

    private function isVersion(string $value): bool
    {
        try {
            ReleaseVersion::parse($value);

            return true;
        } catch (ReleaseException) {
            return false;
        }
    }

    private function hint(string $key): string
    {
        return match ($key) {
            'organization', 'owner_name' => 'Use at most 120 characters without line breaks.',
            'owner_email' => 'Pass an e-mail address such as owner@example.com.',
            'locale' => 'Use de or en.',
            'port' => 'Use a port number between 1 and 65535.',
            'version' => 'Use a release version such as 2.0.0 or 2.0.0-rc.1.',
            'acme_email' => 'Pass an e-mail address such as ops@example.com.',
            'acme_ca' => 'Pass the https URL of an ACME directory, such as https://acme-v02.api.letsencrypt.org/directory.',
            'acme_ca_root' => 'Pass a PEM file with the CA certificate of the ACME directory.',
            'proxy' => 'Use traefik, caddy (caddy-docker-proxy) or port.',
            'url' => 'Use the public URL your proxy serves, such as https://forms.example.com (no path, query or credentials).',
            'proxy_network' => 'Use the name of the Docker network your proxy is attached to (docker network ls).',
            'trusted_proxy' => 'Use the proxy container\'s name or its IP address on that network.',
            default => 'Use a domain name such as forms.example.com.',
        };
    }
}
