<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\Backup\BackupConfig;
use App\Domain\Instance\Backup\KeyFingerprint;
use App\Domain\Instance\Runtime\CatcherRoute;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InvalidState;
use App\Domain\Instance\State\RuntimeWriter;
use App\Domain\Platform\Instance\BackupRole;

/**
 * The env split (KTD7): what each service's env file holds. Only the app roles (app, queue, scheduler, and the
 * one-offs, which run the app service) get APP_KEY and the full database credentials; Postgres gets its own
 * superuser credentials and Valkey and Mailpit only their own password. The edge gets only the catcher's Basic
 * credential it injects towards Mailpit (domain mode, capture mail; ADR 0075). Gotenberg, and later the tunnel, get
 * no secret at all. The backup service (U16, ADR 0084) gets the read-only dump role, a key derived from APP_KEY (never
 * APP_KEY), the fingerprint of APP_KEY, the age recipients (public keys) and its schedule; the instance's identity and
 * portable settings reach it through `backups/.instance.json` (ComposeRenderer::write), so a URL or mail change does
 * not recreate it.
 */
final readonly class ServiceEnvironments
{
    /** Read-only dump role (`pg_read_all_data`, insert into backup_runs) the backup service connects as (ADR 0084). */
    public const string BACKUP_DB_ROLE = BackupRole::NAME;

    /** HKDF info of the backup HMAC key (R67): changing it breaks verification of every existing backup. */
    public const string BACKUP_HMAC_INFO = 'form-schema-app backup hmac v1';

    public const string MAILPIT_HOST = 'mailpit';

    public const int MAILPIT_SMTP_PORT = 1025;

    public const string MAILPIT_DATABASE = '/data/mailpit.db';

    /** The kept catcher's SMTP listener (ADR 0080): the container's own loopback, unreachable from the stack. */
    public const string MAILPIT_KEPT_SMTP_BIND = '127.0.0.1:1025';

    /** Tells the app it runs in capture mode (config `mail.capture`): the admin banner and the health check read it. */
    public const string CAPTURE_ENV = 'FSA_MAIL_CAPTURE';

    public function __construct(private RenderContext $context) {}

    /** @return array<string, string> app, queue and scheduler share this; the role adds its sizing */
    public function appRole(string $role): array
    {
        $budget = $this->context->budget;

        return [
            ...$this->application(),
            ...$this->database($this->context->secrets->dbPassword, $this->context->settings->dbUsername()),
            ...$this->cache(),
            ...$this->mail(),
            ...$this->trustedProxies(),
            ...match ($role) {
                'app' => ['FRANKENPHP_CONFIG' => 'num_threads '.$budget->frankenphpThreads()],
                'queue' => ['HORIZON_MAX_PROCESSES' => (string) $budget->horizonProcesses(), 'HORIZON_EXPORTS_PROCESSES' => (string) $budget->horizonExportsProcesses()],
                default => [],
            },
        ];
    }

    /** @return array<string, string> */
    public function postgres(): array
    {
        $settings = $this->context->settings;

        return ['POSTGRES_DB' => $settings->dbDatabase(), 'POSTGRES_USER' => $settings->dbUsername(), 'POSTGRES_PASSWORD' => $this->context->secrets->dbPassword];
    }

    /**
     * The official image's entrypoint appends VALKEY_EXTRA_FLAGS to the server's arguments (verified on
     * valkey/valkey:8-alpine), so the password stays out of the compose file; Valkey then hides its arguments
     * behind its process title. valkey-cli 8 reads REDISCLI_AUTH, later versions VALKEYCLI_AUTH.
     *
     * @return array<string, string>
     */
    public function valkey(): array
    {
        $password = $this->context->secrets->valkeyPassword;

        return ['VALKEY_EXTRA_FLAGS' => '--requirepass '.$password, 'REDISCLI_AUTH' => $password, 'VALKEYCLI_AUTH' => $password];
    }

    /** @return array<string, string> */
    public function gotenberg(): array
    {
        $budget = $this->context->budget;

        return ['CHROMIUM_MAX_CONCURRENCY' => (string) $budget->gotenbergConcurrency(), 'CHROMIUM_MAX_QUEUE_SIZE' => (string) $budget->gotenbergQueueSize()];
    }

    /** @return array<string, string> */
    public function mailpit(): array
    {
        return [
            'MP_UI_AUTH' => InstanceSecrets::CATCHER_USER.':'.$this->context->secrets->catcherPassword,
            'MP_MAX_MESSAGES' => (string) $this->context->budget->mailpitMaxMessages(),
            'MP_DATABASE' => self::MAILPIT_DATABASE,
            'MP_DISABLE_VERSION_CHECK' => 'true',
            // Kept read-only after the switch to SMTP (ADR 0080): the UI shows what was captured, nothing new arrives.
            ...($this->context->settings->keepsCatcher() ? ['MP_SMTP_BIND_ADDR' => self::MAILPIT_KEPT_SMTP_BIND] : []),
            // Domain mode: the UI lives under /_mail/ behind the edge (LEARNINGS 103: no prefix stripping).
            ...($this->context->mode() === 'domain' ? ['MP_WEBROOT' => CatcherRoute::MAIL_PATH] : []),
        ];
    }

    /**
     * The edge (ADR 0075): in capture mode only the `Authorization` value it injects towards Mailpit, so the browser
     * meets one auth layer (the /platform session through forward_auth) while Mailpit keeps its own credentials
     * (R66). Nothing else: the Caddyfile carries the rest and holds no secret. The local-CA edge (ADR 0082) serves no
     * catcher, so it gets nothing.
     *
     * @return array<string, string>
     */
    public function edge(): array
    {
        if (! $this->context->settings->runsCatcher() || $this->context->mode() !== 'domain') {
            return [];
        }
        $credentials = InstanceSecrets::CATCHER_USER.':'.$this->context->secrets->catcherPassword;

        return [CaddyfileRenderer::AUTHORIZATION_ENV => 'Basic '.base64_encode($credentials)];
    }

    /**
     * The backup service's env (KTD7, KTD15, ADR 0084), rendered from the state: a change of the schedule, the retention,
     * the time zone or the recipients recreates the service with fresh values. It never mounts state.json (a single-file
     * mount would keep showing the file an atomic rename replaced).
     *
     * @return array<string, string>
     */
    public function backup(): array
    {
        $state = $this->context->state;
        $settings = $this->context->settings;

        return [
            ...$this->database($this->context->secrets->backupDbPassword, self::BACKUP_DB_ROLE),
            'BACKUP_HMAC_KEY' => $this->backupHmacKey(),
            'BACKUP_APP_KEY_FINGERPRINTS' => KeyFingerprint::of($this->context->secrets->appKey),
            'BACKUP_AGE_RECIPIENTS' => implode(',', $state->recipients()),
            'BACKUP_SCHEDULE' => $settings->backupSchedule(),
            'BACKUP_TIME_ZONE' => $settings->timeZone(),
            'BACKUP_RETENTION' => (string) $settings->backupRetention(),
            'FSA_INSTANCE_ROOT' => BackupConfig::INSTANCE_ROOT,
            // The in-container owner (KTD2): the host user on rootful Docker, root on rootless Docker and Docker Desktop.
            'FSA_OWNER_UID' => (string) $state->containerUid(),
            'FSA_OWNER_GID' => (string) ($state->containerUid() === 0 ? 0 : $state->hostGid()),
        ];
    }

    /** HKDF-SHA256 of the raw APP_KEY: the backup service can authenticate backups without holding APP_KEY. */
    private function backupHmacKey(): string
    {
        $appKey = $this->context->secrets->appKey;
        $raw = str_starts_with($appKey, 'base64:') ? (string) base64_decode(substr($appKey, 7), true) : $appKey;

        return 'base64:'.base64_encode(hash_hkdf('sha256', $raw, 32, self::BACKUP_HMAC_INFO));
    }

    /** @return array<string, string> */
    private function application(): array
    {
        $url = $this->context->settings->instanceUrl();

        return [
            'APP_NAME' => 'form-schema-app',
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_KEY' => $this->context->secrets->appKey,
            'APP_URL' => $url,
            'APP_ALLOW_REGISTRATION' => 'false',
            'LOG_LEVEL' => 'info',
            // Default only: the runtime middleware sets Secure (and `__Host-`) per request from the scheme it resolves
            // for the request host, so a plain-http loopback request keeps working in an https mode (ADR 0074).
            'SESSION_SECURE_COOKIE' => str_starts_with($url, 'https://') ? 'true' : 'false',
            'SESSION_DRIVER' => 'database',
            'SESSION_LIFETIME' => '43200',
            'SESSION_ENCRYPT' => 'true',
            'GOTENBERG_URL' => 'http://gotenberg:3000',
            'SSO_ALLOW_INSECURE_IDP' => 'false',
            'SSO_DNS_DRIVER' => 'dns',
            // The read-only runtime mount (KTD11): config('instance.runtime_path'), read by App\Domain\Instance\Runtime.
            'FSA_RUNTIME_PATH' => RuntimeWriter::CONTAINER_PATH,
        ];
    }

    /**
     * TRUSTED_PROXIES: exactly one address the app accepts X-Forwarded-* from, never a range a neighbouring container
     * could send from: the edge's fixed address in domain and local-CA mode (KTD10, ADR 0075, ADR 0082), the proxy's own
     * address in proxy mode (R17, ADR 0076), else none. cloudflared is deliberately not trusted (ADR 0082): the runtime
     * pins host and scheme of every request from its address, and a trusted X-Forwarded-Host (its own, or one a client
     * sent through it) would make the host check answer 400 before that.
     *
     * @return array<string, string>
     */
    private function trustedProxies(): array
    {
        $settings = $this->context->settings;
        $subnet = $settings->edgeSubnet();
        $proxies = match (true) {
            $settings->rendersEdge() => $subnet === null ? [] : [EdgeNetwork::edgeAddress($subnet)],
            $this->context->mode() === 'proxy' => $settings->trustedProxies(),
            default => [],
        };

        return $proxies === [] ? [] : ['TRUSTED_PROXIES' => implode(',', $proxies)];
    }

    /** @return array<string, string> */
    private function database(string $password, string $username): array
    {
        return [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'postgres',
            'DB_PORT' => '5432',
            'DB_DATABASE' => $this->context->settings->dbDatabase(),
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => $password,
        ];
    }

    /** @return array<string, string> */
    private function cache(): array
    {
        return [
            'REDIS_HOST' => 'redis',
            'REDIS_PORT' => '6379',
            'REDIS_PASSWORD' => $this->context->secrets->valkeyPassword,
            'CACHE_STORE' => 'redis',
            'QUEUE_CONNECTION' => 'redis',
        ];
    }

    /**
     * Capture mode: SMTP to the bundled Mailpit (KTD13), and FSA_MAIL_CAPTURE for the banner and the health check (ADR
     * 0080); else the configured server: STARTTLS required (MAIL_REQUIRE_TLS, so a server, or an attacker, that hides
     * STARTTLS gets neither the account nor the mail), implicit TLS (MAIL_SCHEME=smtps), or a plain relay
     * (MAIL_SCHEME=smtp, MAIL_AUTO_TLS=false) only when `--smtp-encryption none` asked for it (ADR 0080 amendment).
     *
     * @return array<string, string>
     */
    private function mail(): array
    {
        $settings = $this->context->settings;
        $sender = ['MAIL_FROM_ADDRESS' => $settings->mailFromAddress(), 'MAIL_FROM_NAME' => $settings->mailFromName()];
        if ($settings->capturesMail()) {
            return ['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => self::MAILPIT_HOST, 'MAIL_PORT' => (string) self::MAILPIT_SMTP_PORT, ...$sender, self::CAPTURE_ENV => 'true'];
        }
        $secrets = $this->context->secrets;
        $host = $settings->smtpHost() ?? throw new InvalidState('SMTP mail is configured without a server (setting smtp_host).');

        return array_filter([
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => $host,
            'MAIL_PORT' => (string) $settings->smtpPort(),
            'MAIL_SCHEME' => (string) $settings->smtpScheme(),
            'MAIL_REQUIRE_TLS' => $settings->smtpScheme() === null ? 'true' : '',
            'MAIL_AUTO_TLS' => $settings->smtpScheme() === InstanceSettings::SMTP_PLAIN ? 'false' : '',
            'MAIL_USERNAME' => (string) $secrets->smtpUsername,
            'MAIL_PASSWORD' => (string) $secrets->smtpPassword,
            ...$sender,
        ], static fn (string $value): bool => $value !== '');
    }
}
