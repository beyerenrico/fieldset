<?php

declare(strict_types=1);

namespace App\Domain\Forms\Support;

use Fieldset\Support\SpecPaths;
use Illuminate\Support\Str;
use stdClass;

/** Builds new definitions and fresh element ids (UUIDv7). */
final class BlankDefinition
{
    public static function make(string $key, string $title, string $locale): stdClass
    {
        $definition = new stdClass;
        $definition->{'$schema'} = SpecPaths::META_SCHEMA_ID;
        $definition->specVersion = '1.0.0';
        $definition->key = $key;
        $definition->title = $title;
        $definition->locale = $locale;
        $definition->settings = (object) ['layout' => 'scroll'];
        $definition->pages = [(object) [
            'id' => self::id(),
            'key' => 'page_1',
            'title' => __('forms.builder.default_page_title'),
            'elements' => [],
        ]];

        return $definition;
    }

    public static function id(): string
    {
        return (string) Str::uuid7();
    }

    /** snake_case key from a human name, e.g. "Prüfprotokoll Aufzug" → "prufprotokoll_aufzug". */
    public static function keyFromName(string $name): string
    {
        $key = Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();

        if ($key === '' || preg_match('/^[a-z]/', $key) !== 1) {
            $key = 'form_'.$key;
        }

        return mb_substr(rtrim($key, '_'), 0, 64);
    }
}
