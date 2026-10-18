<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Compose\EdgeNetwork;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\Output\SecretRedactor;
use App\Domain\Instance\State\EnvFiles;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * `fsa config show` (R31, §10 privacy, ADR 0077): every setting as it is in effect, read from the state alone (no
 * Docker call, so it also answers while the engine is down): mode, URL, ports, domain and ACME inputs, proxy setup,
 * trusted proxies, mail as it stands, size. Secrets appear only by name with `[redacted]`. `keys` lists what
 * `fsa config set` takes, with the current value, the modes it applies in, whether it is settable now and the key to
 * use instead (ADR 0078), and `outbound_contacts` what each opt-in feature contacts: nothing by default.
 */
final readonly class ConfigShow
{
    /** Where releases come from; contacted only by an upgrade (§6.5, install.sh's FSA_RELEASE_URL default). */
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
            throw new InvalidInput('The instance has no state yet.', 'not_installed', 'Run `fsa install` first.');
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

    /** @return array<string, mixed> */
    private function settings(InstanceState $state, InstanceSettings $settings): array
    {
        $domain = $state->mode() === 'domain';

        return [
            'port' => $settings->publishesAppPort() ? $settings->port() : null,
            'mail_ui_port' => $settings->capturesMail() && ! $domain ? $settings->mailUiPort() : null,
            'domain' => $settings->domain(),
            'acme' => $domain ? ['email' => $settings->acmeEmail(), 'ca' => $settings->acmeCa(), 'ca_root_sha256' => $settings->acmeCaRoot(), 'staging' => $settings->acmeCa() === InstanceSettings::ACME_STAGING] : null,
            'edge_subnet' => $settings->edgeSubnet(),
            'proxy' => $settings->proxy() === null ? null : ['setup' => $settings->proxy(), 'network' => $settings->proxyNetwork(), 'container' => $settings->proxyContainer()],
            'trusted_proxies' => match ($state->mode()) {
                'domain' => $settings->edgeSubnet() === null ? [] : [EdgeNetwork::edgeAddress($settings->edgeSubnet())],
                'proxy' => $settings->trustedProxies(),
                default => [],
            },
            'extra_hosts' => $settings->extraHosts(),
            'mail' => [
                'mode' => $settings->capturesMail() ? 'capture' : 'smtp',
                'catcher_url' => $settings->catcherUrl(),
                'smtp' => $settings->capturesMail() ? null : ['host' => $settings->smtpHost(), 'port' => $settings->smtpPort(), 'scheme' => $settings->smtpScheme()],
                'from_address' => $settings->mailFromAddress(),
                'from_name' => $settings->mailFromName(),
            ],
            'size' => $settings->size(),
            'database' => $settings->dbDatabase(),
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
        ];
        $keys = [];
        foreach (array_keys(ConfigKeys::APPLIERS) as $key) {
            $keys[] = ['key' => $key, 'value' => $values[$key] ?? null, 'available' => true, ...ConfigKeys::availability($key, $state), 'changes_url' => in_array($key, ConfigKeys::URL_KEYS, true)];
        }
        foreach (ConfigKeys::LATER as $key => $release) {
            $keys[] = ['key' => $key, 'value' => null, 'available' => false, 'modes' => [], 'settable_now' => false, 'use_instead' => null, 'arrives_with' => $release];
        }

        return $keys;
    }

    /**
     * Opt-in features and what each contacts (§10: no outbound calls by default). The registries are those of the
     * pinned images (a mirror when install used FSA_REGISTRY).
     *
     * @return array{default: string, features: list<array<string, mixed>>}
     */
    private function contacts(InstanceState $state, InstanceSettings $settings): array
    {
        $registries = array_values(array_unique(array_map(static fn (string $image): string => explode('/', $image, 2)[0], array_values($state->digests()))));
        $features = [
            ['feature' => 'acme', 'active' => $state->mode() === 'domain', 'contacts' => $state->mode() === 'domain' ? [$settings->acmeCa()] : [],
                'purpose' => 'Domain mode: the edge orders and renews certificates from this ACME directory (and its challenges reach port 80/443).'],
            ['feature' => 'smtp', 'active' => ! $settings->capturesMail(), 'contacts' => $settings->capturesMail() ? [] : [(string) $settings->smtpHost()],
                'purpose' => 'Real mail delivery through this server; capture mode keeps every mail in the bundled catcher (fsa config set mail arrives with 2.0.0-beta.1).'],
            ['feature' => 'upgrades', 'active' => false, 'contacts' => [...$registries, self::RELEASES_URL],
                'purpose' => 'Only when you run an upgrade (fsa upgrade arrives with 2.0.0-rc.1): the release manifest and the pinned images.'],
            ['feature' => 'update_check', 'active' => false, 'contacts' => [],
                'purpose' => 'Off by default; arrives with 2.0.0 and then contacts the release list only when switched on.'],
        ];

        return [
            'default' => 'none: without these features neither fsa nor the instance calls out (identity providers and SMTP servers that organization admins configure for their own organization are contacted as configured)',
            'features' => $features,
        ];
    }
}
