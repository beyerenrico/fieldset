<?php

declare(strict_types=1);

/** Every translation key exists in both shipped UI languages (D7). */
function flattenKeys(array $array, string $prefix = ''): array
{
    $keys = [];
    foreach ($array as $key => $value) {
        $keys = is_array($value)
            ? [...$keys, ...flattenKeys($value, $prefix.$key.'.')]
            : [...$keys, $prefix.$key];
    }

    return $keys;
}

it('has the same translation files and keys in de and en', function (): void {
    $base = dirname(__DIR__, 2).'/lang';
    $files = fn (string $locale): array => array_map(basename(...), glob("{$base}/{$locale}/*.php"));

    expect($files('de'))->toEqualCanonicalizing($files('en'));

    foreach ($files('en') as $file) {
        $en = flattenKeys(require "{$base}/en/{$file}");
        $de = flattenKeys(require "{$base}/de/{$file}");
        expect($de)->toEqualCanonicalizing($en, "Key mismatch in {$file}");
    }
});

it('has the same keys in the JSON catalogs', function (): void {
    $base = dirname(__DIR__, 2).'/lang';
    if (! is_file("{$base}/en.json")) {
        expect(is_file("{$base}/de.json"))->toBeFalse();

        return;
    }

    $en = array_keys(json_decode((string) file_get_contents("{$base}/en.json"), true));
    $de = array_keys(json_decode((string) file_get_contents("{$base}/de.json"), true));
    expect($de)->toEqualCanonicalizing($en);
});
