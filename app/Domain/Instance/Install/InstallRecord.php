<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;

/**
 * The `installed` entry in the state's history: written by the last install phase, it marks a finished instance
 * and records the inputs it was installed with, so a repeated `fsa install` can tell "same flags, nothing to do"
 * (R13, AE3) from "different flags, use config set" (exit 2).
 */
final class InstallRecord
{
    public const string EVENT = 'installed';

    public static function isInstalled(InstanceState $state): bool
    {
        return self::recorded($state) !== null;
    }

    /** @return array<string, mixed>|null the last `installed` history entry */
    public static function recorded(InstanceState $state): ?array
    {
        $entries = array_values(array_filter($state->history(), static fn (array $entry): bool => ($entry['event'] ?? null) === self::EVENT));

        return $entries === [] ? null : $entries[array_key_last($entries)];
    }

    /** @return array<string, string|int|null> */
    public static function entry(InstallRequest $request, InstanceState $state): array
    {
        return [
            'organization' => $request->organization(),
            'owner_email' => $request->ownerEmail(),
            'owner_name' => $request->ownerName(),
            'locale' => $request->locale(),
            'version' => $state->version(),
            'port' => InstanceSettings::of($state)->port(),
        ];
    }

    /**
     * What a finished instance was installed with, keyed like InstallRequest, for comparing a repeated install.
     *
     * @return array<string, string|int|null>
     */
    public static function applied(InstanceState $state): array
    {
        $recorded = self::recorded($state) ?? [];
        $value = static fn (string $key): string|int|null => is_string($recorded[$key] ?? null) || is_int($recorded[$key] ?? null) ? $recorded[$key] : null;
        $domain = $state->setting('domain');

        return [
            ...array_combine(InstallRequest::APP_FLAGS, array_map($value, InstallRequest::APP_FLAGS)),
            'version' => $state->version(),
            'port' => InstanceSettings::of($state)->port(),
            'domain' => is_string($domain) ? $domain : null,
        ];
    }
}
