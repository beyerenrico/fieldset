<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Config\Appliers\MailValues;
use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\Restore\RestoreStatus;
use App\Domain\Instance\Restore\RestoreStorage;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSecrets;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fieldset config show` (R31, §10 privacy, ADR 0077): every setting as it is in effect, read from the state alone (no
 * Docker call, so it also answers while the engine is down): mode, URL, ports, domain and ACME inputs, proxy setup,
 * trusted proxies, mail as it stands, size. Secrets appear only by name with `[redacted]`. `keys` lists what
 * `fieldset config set` takes, with the current value, the modes it applies in, whether it is settable now and the key to
 * use instead (ADR 0078), and `outbound_contacts` what each opt-in feature contacts: nothing by default.
 *
 * `--catcher-login` (ADR 0080) adds the catcher UI's generated password as the one-time secret `catcher_password`
 * (stdout `secrets`, or --secrets-file): the loopback modes need it to open the UI, domain mode never does (the edge
 * signs in for an operator with a /platform session and MFA, R66).
 */
final readonly class ConfigShow
{
    /** Where releases come from; contacted only by an upgrade (§6.5, install.sh's FIELDSET_RELEASE_URL default). */
    public const string RELEASES_URL = 'https://github.com/beyerenrico/form-schema-app/releases';

    /** Secret env values by service and key => the name `config show` lists. */
    private const array SECRETS = [
        'app' => ['APP_KEY' => 'app_key', 'DB_PASSWORD' => 'database_password', 'REDIS_PASSWORD' => 'valkey_password', 'MAIL_USERNAME' => 'smtp_username', 'MAIL_PASSWORD' => 'smtp_password'],
        'mailpit' => ['MP_UI_AUTH' => 'catcher_password'],
        'backup' => ['DB_PASSWORD' => 'backup_database_password', 'BACKUP_HMAC_KEY' => 'backup_hmac_key'],
    ];

    public function __construct(private InstanceDirectory $directory) {}

    public function build(): CommandResult
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        if (! $state instanceof InstanceState) {
            throw new InvalidInput('The instance has no state yet.', 'not_installed', 'Run `fieldset install` first.');
        }
        $settings = InstanceSettings::of($state);

        return CommandResult::ok('config', [
            'dir' => $this->directory->root,
            'project' => $state->project(),
            'version' => $state->version(),
            'mode' => $state->mode(),
            'url' => $settings->instanceUrl(),
            'settings' => $this->settings($state, $settings),
            'secrets' => $this->secrets(),
            'keys' => $this->keys($state, $settings),
            'outbound_contacts' => $this->contacts($state, $settings),
        ]);
    }

    /**
     * The catcher UI's login for the loopback modes: user `fieldset` in data, the generated password as a one-time secret.
     *
     * @throws InvalidInput exit 2 without a catcher, or in domain mode, where the edge signs in for a /platform session
     */
    public function withCatcherLogin(CommandResult $result, FieldsetContext $context): CommandResult
    {
        $state = $this->directory->state();
        $settings = InstanceSettings::of($state);
        if (! $settings->runsCatcher()) {
            throw new InvalidInput('The mail catcher is removed: there is no catcher login.', 'no_catcher', '`fieldset config set mail capture` brings it back.');
        }
        if ($state->mode() === 'domain') {
            throw new InvalidInput('In domain mode the catcher needs no password: the edge signs in for you.', 'catcher_login_not_needed', "Open {$settings->catcherUrl()} signed in to /platform with MFA.");
        }
        $auth = new EnvFiles($this->directory)->read('mailpit')['MP_UI_AUTH'] ?? '';
        $prefix = InstanceSecrets::CATCHER_USER.':';
        if (! str_starts_with($auth, $prefix) || strlen($auth) === strlen($prefix)) {
            throw new InvalidInput('The catcher\'s credentials are missing from env/mailpit.env.', 'no_catcher', 'Run `fieldset install` again to render them.');
        }

        return $result
            ->withData(['catcher_login' => ['url' => $settings->catcherUrl(), 'user' => InstanceSecrets::CATCHER_USER, 'secret' => 'catcher_password']])
            ->withSecret($context->secret('catcher_password', substr($auth, strlen($prefix))));
    }

    /** @return array<string, mixed> */
    private function settings(InstanceState $state, InstanceSettings $settings): array
    {
        $domain = $state->mode() === 'domain';

        return [
            'port' => $settings->publishesAppPort() ? $settings->port() : null,
            'mail_ui_port' => $settings->runsCatcher() && ! $domain ? $settings->mailUiPort() : null,
            'domain' => $settings->domain(),
            'acme' => $domain ? ['email' => $settings->acmeEmail(), 'ca' => $settings->acmeCa(), 'ca_root_sha256' => $settings->acmeCaRoot(), 'staging' => $settings->acmeCa() === InstanceSettings::ACME_STAGING] : null,
            'edge_subnet' => $settings->edgeSubnet(),
            'proxy' => $settings->proxy() === null ? null : ['setup' => $settings->proxy(), 'network' => $settings->proxyNetwork(), 'container' => $settings->proxyContainer()],
            'trusted_proxies' => match (true) {
                $settings->rendersEdge() => $settings->edgeSubnet() === null ? [] : [EdgeNetwork::edgeAddress($settings->edgeSubnet())],
                $state->mode() === 'proxy' => $settings->trustedProxies(),
                default => [],
            },
            'phone_https' => ['mode' => $settings->phoneHttps(), 'lan_address' => $settings->localCa() ? $settings->lanAddress() : null, 'tunnel_subnet' => $settings->tunnelSubnet()],
            'extra_hosts' => $settings->extraHosts(),
            'mail' => [
                'mode' => $settings->capturesMail() ? 'capture' : 'smtp',
                'catcher' => $settings->capturesMail() ? 'active' : ($settings->keepsCatcher() ? 'kept' : 'removed'),
                'catcher_url' => $settings->catcherUrl(),
                'smtp' => $settings->capturesMail() ? null : ['host' => $settings->smtpHost(), 'port' => $settings->smtpPort(), 'scheme' => $settings->smtpScheme(), 'encryption' => MailValues::encryption($settings->smtpScheme())],
                'from_address' => $settings->mailFromAddress(),
                'from_name' => $settings->mailFromName(),
            ],
            'size' => $settings->size(),
            'database' => $settings->dbDatabase(),
            // Restore (ADR 0085): the storage volume the stack mounts, webhooks held after a move, the last restore.
            'storage_volume' => RestoreStorage::current($state),
            'webhooks' => $settings->webhooksPaused() ? 'paused' : 'on',
            'last_restore' => RestoreStatus::last($state),
            'backup' => [
                'schedule' => $settings->backupSchedule(), 'time_zone' => $settings->timeZone(), 'retention' => $settings->backupRetention(),
                'encryption' => ['enabled' => $state->recipients() !== [], 'recipients' => $state->recipients()],
                'directory' => $this->directory->root.'/'.InstanceDirectory::BACKUPS_DIR,
            ],
        ];
    }

    /** @return array<string, string> secret names present in the env files, values always redacted */
    private function secrets(): array
    {
        $env = new EnvFiles($this->directory);
        $names = [];
        foreach (self::SECRETS as $service => $keys) {
            $values = $env->read($service);
            foreach ($keys as $key => $name) {
                if (($values[$key] ?? '') !== '') {
                    $names[$name] = SecretRedactor::REPLACEMENT;
                }
            }
        }

        return $names;
    }

    /** @return list<array<string, mixed>> */
    private function keys(InstanceState $state, InstanceSettings $settings): array
    {
        $values = [
            'domain' => $settings->domain(), 'url' => $settings->instanceUrl(), 'port' => $settings->publishesAppPort() ? $settings->port() : null,
            'trusted-proxy' => $state->mode() === 'proxy' ? implode(',', $settings->trustedProxies()) : null, 'mode' => $state->mode(),
            'acme-email' => $settings->acmeEmail(), 'acme-ca' => $state->mode() === 'domain' ? $settings->acmeCa() : null,
            'mail' => $settings->capturesMail() ? 'capture' : 'smtp',
            'operator-email' => null, // operators live in the app's database (ADR 0080)
            'phone-https' => $settings->phoneHttps() ?? ($state->mode() === 'local' ? 'off' : null), 'lan-address' => $settings->localCa() ? $settings->lanAddress() : null,
            'backup-schedule' => $settings->backupSchedule(), 'backup-retention' => (string) $settings->backupRetention(),
            'backup-encryption' => $state->recipients() === [] ? 'off' : 'on',
            'webhooks' => $settings->webhooksPaused() ? 'paused' : 'on',
            'update-check' => $settings->updateManifestUrl() === null ? 'off' : 'on',
        ];
        $keys = [];
        foreach (ConfigKeys::keys() as $key) {
            $keys[] = ['key' => $key, 'value' => $values[$key] ?? null, 'available' => true, ...ConfigKeys::availability($key, $state), 'changes_url' => in_array($key, ConfigKeys::URL_KEYS, true)];
        }

        return $keys;
    }

    /**
     * Opt-in features and what each contacts (§10: no outbound calls by default). The registries are those of the
     * pinned images (a mirror when install used FIELDSET_REGISTRY).
     *
     * @return array{default: string, features: list<array<string, mixed>>}
     */
    private function contacts(InstanceState $state, InstanceSettings $settings): array
    {
        $registries = array_values(array_unique(array_map(static fn (string $image): string => explode('/', $image, 2)[0], array_values($state->digests()))));
        $updates = $settings->updateManifestUrl();
        $features = [
            ['feature' => 'acme', 'active' => $state->mode() === 'domain', 'contacts' => $state->mode() === 'domain' ? [$settings->acmeCa()] : [],
                'purpose' => 'Domain mode: the edge orders and renews certificates from this ACME directory (and its challenges reach port 80/443).'],
            ['feature' => 'smtp', 'active' => ! $settings->capturesMail(), 'contacts' => $settings->capturesMail() ? [] : [(string) $settings->smtpHost()],
                'purpose' => 'Real mail delivery through this server (fieldset config set mail smtp); capture mode keeps every mail in the bundled catcher.'],
            ['feature' => 'upgrades', 'active' => false, 'contacts' => [...$registries, self::RELEASES_URL],
                'purpose' => 'Only when you run `fieldset upgrade`: the release manifest and the pinned images.'],
            ['feature' => 'tunnel', 'active' => $settings->tunnel(), 'contacts' => $settings->tunnel() ? ['Cloudflare (trycloudflare.com quick tunnel)'] : [],
                'purpose' => 'Only while `fieldset tunnel start` runs one (4 hours unless renewed): cloudflared connects out to Cloudflare, which serves the instance under a random *.trycloudflare.com name.'],
            ['feature' => 'update_check', 'active' => $updates !== null, 'contacts' => $updates === null ? [] : [$updates],
                'purpose' => 'Off by default (`fieldset config set update-check on`): once a day the app reads the newest release manifest, with no instance identifier, and /platform shows whether an update is available.'],
        ];

        return [
            'default' => 'none: without these features neither fieldset nor the instance calls out (identity providers and SMTP servers that organization admins configure for their own organization are contacted as configured)',
            'features' => $features,
        ];
    }
}
