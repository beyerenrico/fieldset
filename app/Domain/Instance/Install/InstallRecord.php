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

        $proxy = array_intersect_key($request->toContext(), array_flip(InstallRequest::PROXY_FLAGS));

        return [
            ...array_filter($app, static fn (?string $value): bool => $value !== null),
            'version' => $state->version(),
            'port' => InstanceSettings::of($state)->port(),
            ...array_filter($proxy, static fn (string|int|null $value): bool => $value !== null),
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
        $setting = static fn (string $key): ?string => is_string($state->setting($key)) ? $state->setting($key) : null;
        $proxy = [];
        foreach (InstallRequest::PROXY_FLAGS as $key) {
            $proxy[$key] = is_string($recorded[$key] ?? null) ? $recorded[$key] : null;
        }
        // `fsa config set` (ADR 0077) may have changed mode, URL, network or trusted proxy since: compare with the state
        // as it is now. --trusted-proxy is compared with the proxy the instance trusts (comparator(), ADR 0078).
        $settings = InstanceSettings::of($state);
        $proxy = [
            ...$proxy, 'proxy' => $settings->proxy(), 'url' => $settings->proxy() === null ? null : $state->url(),
            'proxy_network' => $settings->proxy() === null ? null : $settings->proxyNetwork(),
            'trusted_proxy' => $settings->joinsProxyNetwork() ? $settings->proxyContainer() : null,
        ];

        return [
            ...$app,
            'version' => $state->version(),
            'port' => InstanceSettings::of($state)->port(),
            'domain' => $setting('domain'),
            ...array_combine(InstallRequest::ACME_FLAGS, array_map($setting, InstallRequest::ACME_FLAGS)),
            ...$proxy,
        ];
    }

    /**
     * Compares one recorded input with an explicit flag: personal inputs through their digest; --trusted-proxy through
     * `$trusted`, which knows the trusted proxy by container name, ID prefix or address (install reports the name,
     * `config set trusted-proxy` takes any of them, ADR 0078).
     *
     * @param  (Closure(string): bool)|null  $trusted
     * @return Closure(string, string|int|null, string|int): bool true when equal
     */
    public static function comparator(?RecordDigest $digest, ?Closure $trusted = null): Closure
    {
        return static function (string $key, string|int|null $recorded, string|int $given) use ($digest, $trusted): bool {
            if (in_array($key, self::PERSONAL, true) && is_string($recorded) && str_starts_with($recorded, RecordDigest::PREFIX)) {
                return $digest instanceof RecordDigest && $digest->matches($recorded, (string) $given);
            }
            if ($key === 'trusted_proxy' && $trusted instanceof Closure) {
                return $trusted((string) $given);
            }

            return (string) $recorded === (string) $given;
        };
    }
}
