<?php

declare(strict_types=1);

namespace App\Domain\Instance\Install;

use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * The `installed` entry in the state's history: written by the last install phase, it marks a finished instance
 * and records the inputs it was installed with, so a repeated `fsa install` can tell "same flags, nothing to do"
 * (R13, AE3) from "different flags, use config set" (exit 2).
 *
 * - Only inputs that were applied are recorded: when `app:install` found an organization already there, the app
 *   flags of this run changed nothing and are left out (an absent key never conflicts).
 * - The owner's e-mail and name are personal: they are recorded as keyed digests (RecordDigest), never as values.
 */
final class InstallRecord
{
    public const string EVENT = 'installed';

    /** Inputs recorded only as digests (state history, archived journals). */
    public const array PERSONAL = ['owner_email', 'owner_name'];

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

    /**
     * @param  bool  $appFlagsApplied  false when app:install answered `already_installed` (nothing applied)
     * @return array<string, string|int|null>
     */
    public static function entry(InstallRequest $request, InstanceState $state, ?RecordDigest $digest, bool $appFlagsApplied = true): array
    {
        $app = $appFlagsApplied ? [
            'organization' => $request->organization(),
            'owner_email' => $digest?->digest($request->ownerEmail()),
            'owner_name' => $digest?->digest($request->ownerName()),
            'locale' => $request->locale(),
        ] : [];

        return [
            ...array_filter($app, static fn (?string $value): bool => $value !== null),
            'version' => $state->version(),
            'port' => InstanceSettings::of($state)->port(),
        ];
    }

    /**
     * What a finished instance was installed with, keyed like InstallRequest, for comparing a repeated install.
     * App flags that were not recorded are absent (never a conflict); personal ones are digests.
     *
     * @return array<string, string|int|null>
     */
    public static function applied(InstanceState $state): array
    {
        $recorded = self::recorded($state) ?? [];
        $app = [];
        foreach (InstallRequest::APP_FLAGS as $key) {
            if (array_key_exists($key, $recorded)) {
                $app[$key] = is_string($recorded[$key]) || is_int($recorded[$key]) ? $recorded[$key] : null;
            }
        }
        $domain = $state->setting('domain');

        return [
            ...$app,
            'version' => $state->version(),
            'port' => InstanceSettings::of($state)->port(),
            'domain' => is_string($domain) ? $domain : null,
        ];
    }

    /**
     * Compares one recorded input with an explicit flag: personal inputs through their digest.
     *
     * @return Closure(string, string|int|null, string|int): bool true when equal
     */
    public static function comparator(?RecordDigest $digest): Closure
    {
        return static function (string $key, string|int|null $recorded, string|int $given) use ($digest): bool {
            if (in_array($key, self::PERSONAL, true) && is_string($recorded) && str_starts_with($recorded, RecordDigest::PREFIX)) {
                return $digest instanceof RecordDigest && $digest->matches($recorded, (string) $given);
            }

            return (string) $recorded === (string) $given;
        };
    }
}
