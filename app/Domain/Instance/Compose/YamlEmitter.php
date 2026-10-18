<?php

declare(strict_types=1);

namespace App\Domain\Instance\Compose;

use stdClass;

/**
 * Block-style YAML for the stack file (ADR 0076), which people read and edit in Portainer and Coolify. Every
 * string is written as a JSON string literal, which is a valid YAML double-quoted scalar, so no value can change
 * the structure (the same reason the instance compose file is JSON, ADR 0069). Keys are plain only when they
 * cannot be read as anything else. An empty `stdClass` is `{}`, an empty array `[]`.
 */
final class YamlEmitter
{
    private const string PLAIN_KEY = '/^[A-Za-z_][A-Za-z0-9_.\/-]*$/D';

    /** Keys YAML 1.1 parsers read as booleans or null. */
    private const array RESERVED = ['y', 'n', 'yes', 'no', 'on', 'off', 'true', 'false', 'null'];

    /** @param  array<array-key, mixed>  $document */
    public static function emit(array $document): string
    {
        return self::block($document, 0);
    }

    /** @param  array<array-key, mixed>  $value */
    private static function block(array $value, int $depth): string
    {
        $pad = str_repeat('  ', $depth);
        $out = '';
        $list = array_is_list($value);
        foreach ($value as $key => $item) {
            $lead = $list ? $pad.'- ' : $pad.self::key((string) $key).':';
            if (is_array($item) && $item !== []) {
                $nested = self::block($item, $depth + 1);
                $out .= $list ? $lead.ltrim($nested, ' ') : $lead."\n".$nested;

                continue;
            }
            $out .= ($list ? $lead : $lead.' ').self::scalar($item)."\n";
        }

        return $out;
    }

    private static function key(string $key): string
    {
        return preg_match(self::PLAIN_KEY, $key) === 1 && ! in_array(strtolower($key), self::RESERVED, true)
            ? $key
            : self::scalar($key);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            $value instanceof stdClass, is_array($value) => $value instanceof stdClass ? '{}' : '[]',
            default => json_encode((string) (is_scalar($value) ? $value : ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        };
    }
}
