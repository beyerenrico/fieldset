<?php

declare(strict_types=1);

namespace App\Domain\Forms\Import\Mapping;

use Illuminate\Support\Str;

/** Element keys: the field name in snake_case, made unique within one scope (root or a repeater row). */
final class KeyAllocator
{
    private const int MAX_LENGTH = 64;

    /** @var array<string, true> */
    private array $used = [];

    public function allocate(string $name): string
    {
        $base = self::snake($name);
        $key = $base;
        for ($i = 2; isset($this->used[$key]); $i++) {
            $suffix = '_'.$i;
            $key = rtrim(substr($base, 0, self::MAX_LENGTH - strlen($suffix)), '_').$suffix;
        }
        $this->used[$key] = true;

        return $key;
    }

    /** "CustomerName" → "customer_name", "Row1Desc" → "row1_desc", "Größe (cm)" → "grosse_cm". */
    public static function snake(string $name): string
    {
        $key = Str::ascii($name);
        $key = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key);
        $key = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $key);
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_');

        if ($key === '') {
            return 'field';
        }
        if (preg_match('/^[a-z]/', $key) !== 1) {
            $key = 'field_'.$key;
        }

        return rtrim(substr($key, 0, self::MAX_LENGTH), '_');
    }
}
