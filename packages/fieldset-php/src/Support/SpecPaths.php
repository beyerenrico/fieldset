<?php

declare(strict_types=1);

namespace Fieldset\Support;

use Fieldset\Json\Json;
use RuntimeException;

/**
 * Locates the normative spec files shipped next to the package (spec/form-definition/1.0).
 */
final class SpecPaths
{
    public static function specDirectory(): string
    {
        $source = realpath(__DIR__);
        $package = dirname($source === false ? __DIR__ : $source, 2);

        return $package.'/../../spec/form-definition/1.0';
    }

    public static function metaSchema(): string
    {
        return self::specDirectory().'/meta-schema.json';
    }

    public static function roles(): string
    {
        return self::specDirectory().'/roles.json';
    }

    /**
     * Reads and decodes a JSON file.
     *
     * @throws RuntimeException when the file cannot be read or decoded
     */
    public static function readJson(string $path): mixed
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException(sprintf('Cannot read "%s".', $path));
        }

        try {
            return Json::decode($contents);
        } catch (\JsonException $exception) {
            throw new RuntimeException(sprintf('Invalid JSON in "%s".', $path), 0, $exception);
        }
    }
}
