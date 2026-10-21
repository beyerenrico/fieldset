<?php

declare(strict_types=1);

namespace App\Domain\Instance\Backup;

use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The settings a backup carries (`settings.json`, R38, KTD18, ADR 0084): those that belong to the instance wherever it
 * runs, the URL, domain and ACME account, mail (never its credentials, which live only in env files), the backup policy
 * and its recipients, the size and the database names. Host-bound settings (ports, LAN address, phone HTTPS, the edge
 * and tunnel subnets, the proxy wiring, a custom ACME root file) are re-derived on the target host, never restored.
 */
final readonly class PortableSettings
{
    public const array KEYS = [
        'domain', 'acme_email', 'acme_ca', 'extra_hosts',
        'mail', 'mail_catcher', 'smtp_host', 'smtp_port', 'smtp_scheme', 'mail_from_address', 'mail_from_name',
        'backup_schedule', 'backup_retention', 'time_zone', 'size', 'db_database', 'db_username',
    ];

    /**
     * `staging/backup/instance.json`: the instance's identity and its portable settings, rendered by fieldset for the backup
     * service (ComposeRenderer::write), which copies them into every backup.
     */
    public static function document(InstanceState $state): string
    {
        $document = [
            'schema_version' => '1.0',
            'instance' => ['uuid' => $state->uuid(), 'project' => $state->project(), 'version' => $state->version(), 'mode' => $state->mode()],
            ...self::of($state),
        ];

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /** @return array{mode: string, url: string, recipients: list<string>, settings: array<string, mixed>} */
    public static function of(InstanceState $state): array
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $value = $state->setting($key);
            if ($value !== null) {
                $settings[$key] = $value;
            }
        }

        return ['mode' => $state->mode(), 'url' => InstanceSettings::of($state)->instanceUrl(), 'recipients' => $state->recipients(), 'settings' => $settings];
    }
}
