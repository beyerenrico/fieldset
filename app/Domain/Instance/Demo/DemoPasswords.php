<?php

declare(strict_types=1);

namespace App\Domain\Instance\Demo;

use App\Domain\Instance\Runtime\InvalidRuntimeState;
use App\Domain\Instance\Runtime\RuntimeDocument;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use App\Domain\Instance\State\RuntimeWriter;
use JsonException;

/**
 * Which demo passwords an instance may have (BUILD_PROMPT_V2 R29, AE2, ADR 0055, ADR 0081): the documented `password`
 * only while the instance is reachable on loopback alone; random passwords, listed once, whenever it is reachable
 * beyond loopback. The reasons (`exposure`):
 *
 * - `domain_mode`, `proxy_mode`: the mode itself (proxy mode counts even behind an internal proxy: fieldset cannot tell);
 * - `local_ca`, `tunnel`: local mode with phone HTTPS (the `phone_https` setting, U14) or a tunnel recorded in the
 *   runtime file (`tunnel.hostname`, also once expired: the old host was public);
 * - `runtime_unreadable`: the runtime file exists but cannot be parsed, so a tunnel cannot be ruled out (fail safe).
 */
final class DemoPasswords
{
    public const string DEFAULT = 'default';

    public const string RANDOM = 'random';

    /** The `phone_https` setting values (U14) and the reason each one gives. */
    private const array PHONE_HTTPS = ['local-ca' => 'local_ca', 'tunnel' => 'tunnel'];

    /** @return list<string> why the instance is reachable beyond loopback; empty for a loopback-only local instance */
    public static function exposure(InstanceState $state, InstanceDirectory $directory): array
    {
        $reasons = match ($state->mode()) {
            'domain' => ['domain_mode'],
            'proxy' => ['proxy_mode'],
            default => [],
        };
        $phone = $state->setting('phone_https');
        if (is_string($phone) && $phone !== '') {
            $reasons[] = self::PHONE_HTTPS[$phone] ?? 'phone_https';
        }
        $tunnel = self::runtimeTunnel($directory);
        if ($tunnel !== null) {
            $reasons[] = $tunnel;
        }

        return array_values(array_unique($reasons));
    }

    /** @param  list<string>  $exposure */
    public static function mode(array $exposure): string
    {
        return $exposure === [] ? self::DEFAULT : self::RANDOM;
    }

    /** `tunnel` when the runtime file records a tunnel host, `runtime_unreadable` when it cannot be parsed, else null. */
    private static function runtimeTunnel(InstanceDirectory $directory): ?string
    {
        $raw = $directory->read(RuntimeWriter::FILE);
        if ($raw === null) {
            return null;
        }

        try {
            $runtime = RuntimeDocument::parse(json_decode($raw, true, 16, JSON_THROW_ON_ERROR));
        } catch (JsonException|InvalidRuntimeState) {
            return 'runtime_unreadable';
        }

        return $runtime->tunnel->hostname === null ? null : 'tunnel';
    }
}
