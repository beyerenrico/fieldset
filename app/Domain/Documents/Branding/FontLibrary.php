<?php

declare(strict_types=1);

namespace App\Domain\Documents\Branding;

use RuntimeException;

/**
 * The bundled OFL-1.1 fonts (resources/fonts/<key>/, license in OFL.txt next to the files). Taken from
 * the @fontsource packages (inter 5.3.0, source-sans-3 5.3.1, noto-sans 5.3.0, noto-serif 5.3.0):
 * latin and latin-ext subsets, regular and bold. The renderer has no network access, so the faces are
 * inlined as data: URIs (BUILD_PROMPT §8.5).
 */
final class FontLibrary
{
    public const array WEIGHTS = [400, 700];

    private const array SUBSETS = [
        'latin' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
        'latin-ext' => 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF',
    ];

    /** @var array<string, string> */
    private static array $css = [];

    public static function path(string $font, string $subset, int $weight): string
    {
        return resource_path("fonts/{$font}/{$subset}-{$weight}.woff2");
    }

    /** CSS font-family list: the bundled face first, then a generic fallback. */
    public static function family(string $font): string
    {
        $name = Branding::FONTS[$font] ?? Branding::FONTS[Branding::DEFAULT_FONT];

        return "'{$name}', ".($font === 'noto-serif' ? 'serif' : 'sans-serif');
    }

    /**
     * @font-face rules with inlined woff2 data for the given weights.
     *
     * @param  list<int>  $weights
     */
    public static function css(string $font, array $weights = self::WEIGHTS): string
    {
        $font = array_key_exists($font, Branding::FONTS) ? $font : Branding::DEFAULT_FONT;
        $cacheKey = $font.':'.implode(',', $weights);

        return self::$css[$cacheKey] ??= self::build($font, $weights);
    }

    /** @param  list<int>  $weights */
    private static function build(string $font, array $weights): string
    {
        $name = Branding::FONTS[$font];
        $rules = [];
        foreach ($weights as $weight) {
            foreach (self::SUBSETS as $subset => $range) {
                $path = self::path($font, $subset, $weight);
                $bytes = is_file($path) ? file_get_contents($path) : false;
                if ($bytes === false) {
                    throw new RuntimeException("Bundled font file missing: {$path}");
                }
                $rules[] = sprintf(
                    "@font-face{font-family:'%s';font-style:normal;font-weight:%d;src:url(data:font/woff2;base64,%s) format('woff2');unicode-range:%s}",
                    $name, $weight, base64_encode($bytes), $range,
                );
            }
        }

        return implode("\n", $rules);
    }
}
