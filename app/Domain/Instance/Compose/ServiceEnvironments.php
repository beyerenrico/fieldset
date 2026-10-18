<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use App\Domain\Instance\Runtime\CatcherRoute;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InvalidState;
use App\Domain\Instance\State\RuntimeWriter;

/**
 * The env split (KTD7): what each service's env file holds. Only the app roles (app, queue, scheduler, and the
 * one-offs, which run the app service) get APP_KEY and the full database credentials; Postgres gets its own
 * superuser credentials and Valkey and Mailpit only their own password. The edge gets only the catcher's Basic
 * credential it injects towards Mailpit (domain mode, capture mail; ADR 0075). Gotenberg, and later the tunnel, get
 * no secret at all. The backup service (U16) gets the read-only dump role, a key derived from APP_KEY
 * and the age recipients: `backup()` defines that env now, the service itself is rendered in U16.
 */
final readonly class ServiceEnvironments
{
    /** Read-only dump role (`pg_read_all_data`) the backup service connects as; U16 creates it in a one-off. */
    public const string BACKUP_DB_ROLE = 'fsa_backup';

    /** HKDF info of the backup HMAC key (R67): changing it breaks verification of every existing backup. */
    public const string BACKUP_HMAC_INFO = 'form-schema-app backup hmac v1';

    public const string MAILPIT_HOST = 'mailpit';

    public const int MAILPIT_SMTP_PORT = 1025;

    public const string MAILPIT_DATABASE = '/data/mailpit.db';

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
            // Domain mode: the UI lives under /_mail/ behind the edge (LEARNINGS 103: no prefix stripping).
            ...($this->context->mode() === 'domain' ? ['MP_WEBROOT' => CatcherRoute::MAIL_PATH] : []),
        ];
    }

    /**
     * The edge (ADR 0075): in capture mode only the `Authorization` value it injects towards Mailpit, so the browser
     * meets one auth layer (the /platform session through forward_auth) while Mailpit keeps its own credentials
     * (R66). Nothing else: the Caddyfile carries the rest and holds no secret.
     *
     * @return array<string, string>
     */
    public function edge(): array
    {
        if (! $this->context->settings->capturesMail()) {
            return [];
        }
        $credentials = InstanceSecrets::CATCHER_USER.':'.$this->context->secrets->catcherPassword;

        return [CaddyfileRenderer::AUTHORIZATION_ENV => 'Basic '.base64_encode($credentials)];
    }

    /** @return array<string, string> the backup service's env (KTD7, KTD15), rendered with the service in U16 */
    public function backup(): array
    {
        return [
            ...$this->database($this->context->secrets->backupDbPassword, self::BACKUP_DB_ROLE),
            'BACKUP_HMAC_KEY' => $this->backupHmacKey(),
            'BACKUP_AGE_RECIPIENTS' => implode(',', $this->context->state->recipients()),
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
     * could send from: the edge's fixed address in domain mode (KTD10, ADR 0075), the proxy's own address in proxy
     * mode (R17, ADR 0076), none in local mode.
     *
     * @return array<string, string>
     */
    private function trustedProxies(): array
    {
        $subnet = $this->context->settings->edgeSubnet();
        $proxies = match ($this->context->mode()) {
            'domain' => $subnet === null ? [] : [EdgeNetwork::edgeAddress($subnet)],
            'proxy' => $this->context->settings->trustedProxies(),
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

    /** @return array<string, string> capture mode: SMTP to the bundled Mailpit (KTD13); else the configured server */
    private function mail(): array
    {
        $settings = $this->context->settings;
        $sender = ['MAIL_FROM_ADDRESS' => $settings->mailFromAddress(), 'MAIL_FROM_NAME' => $settings->mailFromName()];
        if ($settings->capturesMail()) {
            return ['MAIL_MAILER' => 'smtp', 'MAIL_HOST' => self::MAILPIT_HOST, 'MAIL_PORT' => (string) self::MAILPIT_SMTP_PORT, ...$sender];
        }
        $secrets = $this->context->secrets;
        $host = $settings->smtpHost() ?? throw new InvalidState('SMTP mail is configured without a server (setting smtp_host).');

        return array_filter([
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => $host,
            'MAIL_PORT' => (string) $settings->smtpPort(),
            'MAIL_SCHEME' => (string) $settings->smtpScheme(),
            'MAIL_USERNAME' => (string) $secrets->smtpUsername,
            'MAIL_PASSWORD' => (string) $secrets->smtpPassword,
            ...$sender,
        ], static fn (string $value): bool => $value !== '');
    }
}
