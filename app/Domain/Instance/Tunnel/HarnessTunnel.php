<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Release\BuildFlavor;

/**
 * The fake tunnel of the release-harness journeys (J6, §11 "a fake tunnel container"; ADR 0082), like FIELDSET_TEST_DNS
 * (ADR 0075): `FIELDSET_TEST_TUNNEL=image=<fieldset-test-… image>[;ttl=<seconds>]` replaces cloudflared's image with one the
 * harness built (it answers `/quicktunnel` with a `*.trycloudflare.com` name and forwards to the app) and shortens the
 * 4-hour lifetime so a journey can watch it expire. Only release-harness images honour it (BuildFlavor is read from the
 * image, never from the environment); a production image ignores the variable. Anything malformed is ignored as a
 * whole. The image must be a local `fieldset-test-` name, so the variable can never point an instance at another registry.
 */
final class HarnessTunnel
{
    public const string ENV = 'FIELDSET_TEST_TUNNEL';

    private const string IMAGE = '/^fieldset-test-[a-z0-9][a-z0-9-]{0,62}:[a-z0-9][a-z0-9._-]{0,63}$/D';

    /** The fake tunnel image, or null (production builds, unset, malformed). */
    public static function image(?string $value = null, ?BuildFlavor $flavor = null): ?string
    {
        $image = self::parse($value, $flavor)['image'] ?? null;

        return is_string($image) ? $image : null;
    }

    /** The tunnel's lifetime in seconds for the journey (10 s to 4 h), or null for the 4-hour default. */
    public static function ttl(?string $value = null, ?BuildFlavor $flavor = null): ?int
    {
        $ttl = self::parse($value, $flavor)['ttl'] ?? null;

        return is_int($ttl) ? $ttl : null;
    }

    /** @return array{image?: string, ttl?: int} */
    private static function parse(?string $value, ?BuildFlavor $flavor): array
    {
        $value ??= is_string(getenv(self::ENV)) ? (string) getenv(self::ENV) : '';
        if ($value === '' || ($flavor ?? BuildFlavor::current()) !== BuildFlavor::Harness) {
            return [];
        }
        $parsed = [];
        foreach (array_filter(explode(';', $value), static fn (string $entry): bool => trim($entry) !== '') as $entry) {
            [$key, $item] = array_map(trim(...), array_pad(explode('=', $entry, 2), 2, ''));
            if ($key === 'image' && preg_match(self::IMAGE, $item) === 1) {
                $parsed['image'] = $item;
            } elseif ($key === 'ttl' && ctype_digit($item) && (int) $item >= 10 && (int) $item <= TunnelClock::LIFETIME_SECONDS) {
                $parsed['ttl'] = (int) $item;
            } else {
                return [];
            }
        }

        return $parsed;
    }
}
