<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Qpdf;

/**
 * Read access to the `qpdf` object table of qpdf JSON v2: references ("12 0 R"), names ("/Tx"),
 * strings ("u:text" / "b:hex") and streams (`{stream: {dict, data?}}`).
 */
final readonly class QpdfObjects
{
    private const int MAX_DEPTH = 32;

    /**
     * @param  array<string, mixed>  $objects  `obj:N G R` => {value|stream}
     * @param  array<string, string>  $streamData  `N G R` => decoded stream bytes
     */
    public function __construct(private array $objects, private array $streamData = []) {}

    public static function isRef(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d+ \d+ R$/', $value) === 1;
    }

    /** Resolves a reference to its value (a stream resolves to its dictionary). */
    public function resolve(mixed $value): mixed
    {
        for ($i = 0; $i < self::MAX_DEPTH && self::isRef($value); $i++) {
            $object = $this->objects['obj:'.$value] ?? null;
            if (! is_array($object)) {
                return null;
            }
            $value = array_key_exists('value', $object) ? $object['value'] : ($object['stream']['dict'] ?? null);
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function dict(mixed $value): array
    {
        $resolved = $this->resolve($value);

        return is_array($resolved) && ! array_is_list($resolved) ? $resolved : [];
    }

    /** @return list<mixed> */
    public function array(mixed $value): array
    {
        $resolved = $this->resolve($value);

        return is_array($resolved) && array_is_list($resolved) ? $resolved : [];
    }

    /** @return array<string, mixed> */
    public function trailer(): array
    {
        $trailer = $this->objects['trailer']['value'] ?? null;

        return is_array($trailer) ? $trailer : [];
    }

    public function isStream(mixed $ref): bool
    {
        return self::isRef($ref) && isset($this->objects['obj:'.$ref]['stream']);
    }

    /** Decoded stream bytes, when the stream was fetched in the second qpdf pass. */
    public function streamData(string $ref): ?string
    {
        return $this->streamData[$ref] ?? null;
    }

    /** A PDF text string (or a JavaScript stream) as UTF-8. */
    public function text(mixed $value): ?string
    {
        if (is_string($value) && self::isRef($value)) {
            return $this->isStream($value) ? self::bytesToUtf8($this->streamData($value)) : $this->text($this->resolve($value));
        }

        if (! is_string($value)) {
            return null;
        }

        return match (true) {
            str_starts_with($value, 'u:') => substr($value, 2),
            str_starts_with($value, 'b:') => self::bytesToUtf8((string) hex2bin(strlen($value) % 2 === 0 ? substr($value, 2) : substr($value, 2).'0')),
            default => null,
        };
    }

    public function name(mixed $value): ?string
    {
        $value = $this->resolve($value);

        return is_string($value) && str_starts_with($value, '/') ? substr($value, 1) : null;
    }

    public function number(mixed $value): ?float
    {
        $value = $this->resolve($value);

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * The dictionary and its /Parent chain, nearest first (cycle-safe).
     *
     * @return list<array<string, mixed>>
     */
    public function chain(string $ref): array
    {
        $chain = [];
        $seen = [];
        $current = $ref;
        while (self::isRef($current) && ! isset($seen[$current]) && count($chain) < self::MAX_DEPTH) {
            $seen[$current] = true;
            $dict = $this->dict($current);
            $chain[] = $dict;
            $current = $dict['/Parent'] ?? null;
        }

        return $chain;
    }

    /**
     * First value of an (inheritable) key along a /Parent chain.
     *
     * @param  list<array<string, mixed>>  $chain
     */
    public static function inherited(array $chain, string $key): mixed
    {
        foreach ($chain as $dict) {
            if (array_key_exists($key, $dict)) {
                return $dict[$key];
            }
        }

        return null;
    }

    private static function bytesToUtf8(?string $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return $bytes;
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }
}
