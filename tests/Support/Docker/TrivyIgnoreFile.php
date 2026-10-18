<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use RuntimeException;

/**
 * Reads a Trivy exception file (distribution/trivy/<service>.trivyignore.yaml, ADR 0071). The project has no YAML
 * parser, so the files keep to one fixed layout and anything else fails the read: a `vulnerabilities:` list whose
 * entries hold `id`, `paths` and/or `purls` (block lists), `expired_at` (YYYY-MM-DD) and a double-quoted
 * `statement`. Trivy itself reads the files in the release scan, which proves both read them the same way.
 */
final readonly class TrivyIgnoreFile
{
    /**
     * @param  list<array{id: string, paths: list<string>, purls: list<string>, expired_at: ?string, statement: ?string}>  $entries
     */
    private function __construct(public string $path, public array $entries) {}

    public static function fromFile(string $path): self
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $entries = [];
        $list = null;
        $started = false;
        foreach ($lines as $index => $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            if (! $started) {
                if ($line !== 'vulnerabilities:') {
                    self::fail($path, $index, $line);
                }

                $started = true;

                continue;
            }

            if (preg_match('/^  - id: (\S+)$/', $line, $match) === 1) {
                $entries[] = ['id' => $match[1], 'paths' => [], 'purls' => [], 'expired_at' => null, 'statement' => null];
                $list = null;

                continue;
            }

            $current = array_key_last($entries) ?? self::fail($path, $index, $line);
            if (preg_match('/^    (paths|purls):$/', $line, $match) === 1) {
                $list = $match[1];
            } elseif ($list !== null && preg_match('/^      - (\S+)$/', $line, $match) === 1) {
                $entries[$current][$list][] = $match[1];
            } elseif (preg_match('/^    expired_at: (\d{4}-\d{2}-\d{2})$/', $line, $match) === 1) {
                [$entries[$current]['expired_at'], $list] = [$match[1], null];
            } elseif (preg_match('/^    statement: "([^"\\\\]+)"$/', $line, $match) === 1) {
                [$entries[$current]['statement'], $list] = [$match[1], null];
            } else {
                self::fail($path, $index, $line);
            }
        }

        return new self($path, $entries);
    }

    private static function fail(string $path, int $index, string $line): never
    {
        throw new RuntimeException(sprintf('%s:%d: unsupported line for the exception file layout: %s', $path, $index + 1, $line));
    }
}
