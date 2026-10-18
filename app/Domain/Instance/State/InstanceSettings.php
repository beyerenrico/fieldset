<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\Compose\ResourceBudget;
use App\Domain\Instance\Runtime\CatcherRoute;

/**
 * Typed reads of the state's settings with their defaults, for the renderers (plan U3). The values were checked
 * against InputGrammar when they were written and again when the state was read.
 */
final readonly class InstanceSettings
{
    public const int DEFAULT_PORT = 8080;

    public const int DEFAULT_MAIL_UI_PORT = 8025;

    public const int DEFAULT_SMTP_PORT = 587;

    public const string DEFAULT_DATABASE = 'form_schema';

    public const string DEFAULT_SENDER_NAME = 'form-schema-app';

    /** Setting `mail_catcher`: the catcher is kept read-only after the switch to SMTP (ADR 0080). */
    public const string CATCHER_KEPT = 'kept';

    /** Implicit TLS (`--smtp-encryption tls`, usually port 465); unset means STARTTLS when the server offers it. */
    public const string SMTP_IMPLICIT_TLS = 'smtps';

    public const int DEFAULT_SMTPS_PORT = 465;

    /** Let's Encrypt production: domain mode's ACME directory unless `--acme-ca` or `--acme-staging` (R16). */
    public const string ACME_PRODUCTION = 'https://acme-v02.api.letsencrypt.org/directory';

    /** Let's Encrypt staging (`--acme-staging`): for trial installs, against the duplicate-certificate limits. */
    public const string ACME_STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';

    private function __construct(private InstanceState $state) {}

    public static function of(InstanceState $state): self
    {
        return new self($state);
    }

    /** The app's loopback port in local mode (R15: 8080 or the next free one, chosen by install). */
    public function port(): int
    {
        return $this->int('port', self::DEFAULT_PORT);
    }

    /** The catcher UI's loopback port in local and proxy modes (KTD13). */
    public function mailUiPort(): int
    {
        return $this->int('mail_ui_port', self::DEFAULT_MAIL_UI_PORT);
    }

    /** Capture mode (E8) until SMTP is configured. */
    public function capturesMail(): bool
    {
        return $this->state->setting('mail', 'capture') !== 'smtp';
    }

    /**
     * SMTP delivers, and the catcher stays with the mail it captured until `fsa config set mail smtp --remove-catcher`
     * (KTD13, ADR 0080): read-only, its SMTP port bound to the container's loopback so nothing reaches it any more.
     */
    public function keepsCatcher(): bool
    {
        return ! $this->capturesMail() && $this->state->setting('mail_catcher') === self::CATCHER_KEPT;
    }

    /** The bundled catcher runs: in capture mode, or kept read-only after the switch to SMTP. */
    public function runsCatcher(): bool
    {
        return $this->capturesMail() || $this->keepsCatcher();
    }

    public function size(): string
    {
        return $this->string('size') ?? ResourceBudget::DEFAULT_SIZE;
    }

    public function dbDatabase(): string
    {
        return $this->string('db_database') ?? self::DEFAULT_DATABASE;
    }

    public function dbUsername(): string
    {
        return $this->string('db_username') ?? self::DEFAULT_DATABASE;
    }

    public function smtpHost(): ?string
    {
        return $this->string('smtp_host');
    }

    public function smtpPort(): int
    {
        return $this->int('smtp_port', self::DEFAULT_SMTP_PORT);
    }

    /** null (STARTTLS when offered) or "smtps" (implicit TLS, port 465). */
    public function smtpScheme(): ?string
    {
        return $this->string('smtp_scheme');
    }

    public function mailFromAddress(): string
    {
        $domain = $this->string('domain');

        return $this->string('mail_from_address') ?? 'forms@'.($domain !== null && ! InputGrammar::isIp($domain) ? $domain : 'localhost');
    }

    public function mailFromName(): string
    {
        return $this->string('mail_from_name') ?? self::DEFAULT_SENDER_NAME;
    }

    /** @return list<string> further host names the app answers on (KTD11; plain names or IPs, no wildcards) */
    public function extraHosts(): array
    {
        return InputGrammar::hostList($this->string('extra_hosts') ?? '') ?? [];
    }

    /** The domain of domain mode (U8); null in the other modes. */
    public function domain(): ?string
    {
        return $this->state->mode() === 'domain' ? $this->string('domain') : null;
    }

    /** The ACME account contact; none unless `--acme-email` (it is shared with the CA). */
    public function acmeEmail(): ?string
    {
        return $this->string('acme_email');
    }

    /** The ACME directory the edge orders certificates from. */
    public function acmeCa(): string
    {
        return $this->string('acme_ca') ?? self::ACME_PRODUCTION;
    }

    /** Whether the edge trusts a custom root for the ACME directory's HTTPS (edge/acme-ca-root.pem, its SHA-256). */
    public function acmeCaRoot(): ?string
    {
        return $this->string('acme_ca_root');
    }

    /** The edge network's subnet (domain mode): the edge has a fixed address in it, the app's only trusted proxy. */
    public function edgeSubnet(): ?string
    {
        return $this->string('edge_subnet');
    }

    /** Where the catcher UI is reached (KTD13): the loopback port, `/_mail/` behind the edge in domain mode, else null. */
    public function catcherUrl(): ?string
    {
        if (! $this->runsCatcher()) {
            return null;
        }
        $domain = $this->domain();

        return $domain !== null ? 'https://'.$domain.CatcherRoute::MAIL_PATH : 'http://localhost:'.$this->mailUiPort();
    }

    /** Proxy mode's setup (R17, ADR 0076): `traefik` or `caddy` on a shared network, or `port`; null in other modes. */
    public function proxy(): ?string
    {
        return $this->state->mode() === 'proxy' ? $this->string('proxy') : null;
    }

    /** The external Docker network the app shares with the proxy (traefik and caddy setups). */
    public function proxyNetwork(): ?string
    {
        return $this->string('proxy_network');
    }

    /**
     * The proxy's container on that network, as resolved at install or by `config set trusted-proxy`: `fsa doctor` and
     * `fsa status` re-resolve it to warn when its address moved, `config set trusted-proxy auto` follows it.
     */
    public function proxyContainer(): ?string
    {
        return $this->string('proxy_container');
    }

    /**
     * Exactly the addresses the app accepts X-Forwarded-* from (TRUSTED_PROXIES): the proxy's own address, never a
     * range a neighbouring container on the shared network could use (R17, KTD10).
     *
     * @return list<string>
     */
    public function trustedProxies(): array
    {
        return InputGrammar::ipList($this->string('trusted_proxies') ?? '') ?? [];
    }

    /** Phone HTTPS of a local install (ADR 0082): `local-ca`, `tunnel`, or null; never set in domain or proxy mode (R61). */
    public function phoneHttps(): ?string
    {
        return $this->state->mode() === 'local' ? $this->string('phone_https') : null;
    }

    /** Local-CA mode: the edge serves https://<LAN address> with the instance's own CA (R19). */
    public function localCa(): bool
    {
        return $this->phoneHttps() === 'local-ca';
    }

    /** A quick tunnel is configured (R20): its service and network are rendered. */
    public function tunnel(): bool
    {
        return $this->phoneHttps() === 'tunnel';
    }

    /** The LAN address the local-CA edge binds 80/443 on and issues its certificate for. */
    public function lanAddress(): ?string
    {
        return $this->string('lan_address');
    }

    /** The tunnel network's subnet: cloudflared has a fixed address in it, the only peer of tunnel requests (KTD12). */
    public function tunnelSubnet(): ?string
    {
        return $this->string('tunnel_subnet');
    }

    /** Whether the edge runs: domain mode, or local mode with the local CA. */
    public function rendersEdge(): bool
    {
        return $this->state->mode() === 'domain' || $this->localCa();
    }

    /** The app publishes its own loopback port: local mode, and proxy mode's `port` setup. */
    public function publishesAppPort(): bool
    {
        return $this->state->mode() === 'local' || $this->proxy() === 'port';
    }

    /** Proxy mode on a shared Docker network: the app joins it with the proxy's labels and publishes nothing. */
    public function joinsProxyNetwork(): bool
    {
        return in_array($this->proxy(), ['traefik', 'caddy'], true) && $this->proxyNetwork() !== null;
    }

    /** The loopback URL in local mode; other modes publish no app port of their own. */
    public function localUrl(): ?string
    {
        return $this->state->mode() === 'local' ? 'http://localhost:'.$this->port() : null;
    }

    /** The URL in the state (domain, proxy, `config set url`), else the local URL. */
    public function instanceUrl(): string
    {
        return $this->state->url() ?? $this->localUrl()
            ?? throw new InvalidState("The instance state has no URL for mode [{$this->state->mode()}].");
    }

    private function int(string $key, int $default): int
    {
        $value = $this->state->setting($key);

        return is_int($value) ? $value : $default;
    }

    private function string(string $key): ?string
    {
        $value = $this->state->setting($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
