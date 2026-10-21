<?php

declare(strict_types=1);

namespace App\Domain\Instance\Docker;

/**
 * Label keys of resources `fsa` creates (R63, KTD6). Our own keys use the `app.fieldset.*` prefix, because
 * Compose reserves `com.docker.compose.*`, which we only read.
 */
final class Labels
{
    public const string INSTANCE = 'app.fieldset.instance';

    public const string ROLE = 'app.fieldset.role';

    public const string COMPOSE_PROJECT = 'com.docker.compose.project';

    public const string COMPOSE_SERVICE = 'com.docker.compose.service';

    /** @return array<string, string> */
    public static function forInstance(string $uuid, ?string $role = null): array
    {
        return $role === null ? [self::INSTANCE => $uuid] : [self::INSTANCE => $uuid, self::ROLE => $role];
    }

    /**
     * Labels as Docker's list formats print them ("a=b,c=d") or as inspect returns them (an object).
     *
     * @return array<string, string>
     */
    public static function parse(mixed $labels): array
    {
        if (is_array($labels)) {
            $parsed = [];
            foreach ($labels as $key => $value) {
                $parsed[(string) $key] = is_scalar($value) ? (string) $value : '';
            }

            return $parsed;
        }
        if (! is_string($labels) || $labels === '') {
            return [];
        }
        $parsed = [];
        foreach (explode(',', $labels) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ($key !== '') {
                $parsed[$key] = $value;
            }
        }

        return $parsed;
    }

    /**
     * Whether a resource's labels match a filter (a null value means "label present").
     *
     * @param  array<string, string>  $labels
     * @param  array<string, string|null>  $filter
     */
    public static function matches(array $labels, array $filter): bool
    {
        return array_all($filter, fn (?string $value, $key): bool => array_key_exists($key, $labels) && ($value === null || $labels[$key] === $value));
    }
}
