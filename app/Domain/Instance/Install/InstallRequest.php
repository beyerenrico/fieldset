<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
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
 * - `--phone-https local-ca [--lan-address]` (R19, ADR 0082) is consumed by the doctor phase; `--phone-https tunnel`
 *   starts the quick tunnel once the install finished (InstallCommand).
 * - `--smtp-*` (ADR 0080 amendment, InstallMail): the server flags are consumed by the doctor phase, the account (user
 *   name and password, never journaled: `smtp_credentials` is 1) by the state phase.
 */
final readonly class InstallRequest
{
    public const array APP_FLAGS = ['organization', 'owner_email', 'owner_name', 'locale'];

    public const array PREFLIGHT_FLAGS = ['version', 'port', 'domain', ...self::ACME_FLAGS, ...self::PROXY_FLAGS, ...self::PHONE_FLAGS, ...InstallMail::SERVER_FLAGS];

    /** Domain-mode inputs (U8): meaningless, and refused, without --domain. */
    public const array ACME_FLAGS = ['acme_email', 'acme_ca', 'acme_ca_root'];

    public const array PROXY_FLAGS = ['proxy', 'url', 'proxy_network', 'trusted_proxy'];

    /** Phone HTTPS (R19, R20, ADR 0082): `--phone-https local-ca|tunnel`, and the local CA's `--lan-address`. */
    public const array PHONE_FLAGS = ['phone_https', 'lan_address'];

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
        'demo' => '--demo', 'phone_https' => '--phone-https', 'lan_address' => '--lan-address', ...InstallMail::FLAGS,
    ];

    /** Command option => request key (the fsa wrapper passes --version as --release, ADR 0072). */
    private const array OPTIONS = [
        'org' => 'organization', 'owner-email' => 'owner_email', 'owner-name' => 'owner_name', 'locale' => 'locale',
        'release' => 'version', 'port' => 'port', 'domain' => 'domain', 'acme-email' => 'acme_email', 'acme-ca' => 'acme_ca',
        'behind-proxy' => 'proxy', 'url' => 'url', 'proxy-network' => 'proxy_network', 'trusted-proxy' => 'trusted_proxy',
        'phone-https' => 'phone_https', 'lan-address' => 'lan_address', ...InstallMail::OPTIONS,
    ];

    /**
     * @param  array<string, string|int>  $explicit  request key => validated value
     * @param  string|null  $caRoot  the PEM of `--acme-ca-root-file` (its SHA-256 is the `acme_ca_root` value)
     * @param  SmtpCredentials|null  $smtp  the SMTP account given in this run (`smtp_credentials` is 1 then)
     */
    private function __construct(private array $explicit, private ?string $caRoot = null, private ?SmtpCredentials $smtp = null) {}

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
        return new self([...$this->explicit, $key => InstallInputs::validate($key, trim($value))], $this->caRoot, $this->smtp);
    }

    /** `--demo`: the demo organization, forms, workflow and users after the stack started (R29). */
    public function withDemo(): self
    {
        return new self([...$this->explicit, 'demo' => 1], $this->caRoot, $this->smtp);
    }

    public function demo(): bool
    {
        return ($this->explicit['demo'] ?? null) === 1;
    }

    /** The custom ACME CA root (validated PEM, AcmeCaRoot): recorded as its SHA-256, written by the state phase. */
    public function withCaRoot(string $pem): self
    {
        return new self([...$this->explicit, 'acme_ca_root' => hash('sha256', $pem)], $pem, $this->smtp);
    }

    /** `--smtp-username` with the password from stdin or a file (validated by MailValues). */
    public function withSmtpCredentials(SmtpCredentials $credentials): self
    {
        return new self([...$this->explicit, 'smtp_credentials' => 1], $this->caRoot, $credentials);
    }

    /** The SMTP account given in this run; null without one, and on a resume without the flags. */
    public function smtpCredentials(): ?SmtpCredentials
    {
        return $this->smtp;
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

        return new self([...$known, ...$this->explicit], $this->caRoot, $this->smtp);
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

    /** `--phone-https`: local-ca or tunnel; null without phone HTTPS. */
    public function phoneHttps(): ?string
    {
        return $this->optional('phone_https');
    }

    /** `--lan-address` of the local CA; null = the launcher's best candidate. */
    public function lanAddress(): ?string
    {
        return $this->optional('lan_address');
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
        $phone = $this->phoneHttps();
        $conflict = match (true) {
            $proxy !== null && $this->domain() !== null => ['--behind-proxy and --domain exclude each other.', 'Choose one mode: the edge with automatic HTTPS (--domain) or your own proxy (--behind-proxy).'],
            $phone !== null && ($proxy !== null || $this->domain() !== null) => ['--phone-https belongs to local mode: it excludes --domain and --behind-proxy (R61).', 'Domain and proxy mode already serve HTTPS; phones open that URL. Leave --phone-https out, or install in local mode.'],
            $this->lanAddress() !== null && $phone !== 'local-ca' => ['--lan-address belongs to --phone-https local-ca.', 'Add --phone-https local-ca, or leave --lan-address out.'],
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
            'phone_https' => $this->phoneHttps(),
            'lan_address' => $this->lanAddress(),
            ...array_fill_keys(array_keys(InstallMail::FLAGS), null),
            ...array_intersect_key($this->explicit, InstallMail::FLAGS),
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
}
