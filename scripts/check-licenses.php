<?php

declare(strict_types=1);

/*
 * Fails when a Composer dependency (including dev), or third-party software that the app image adds
 * (docker/app/binaries.json, ADR 0071), uses a license that is not OSI-approved and compatible with AGPL-3.0
 * (BUILD_PROMPT §11 License hygiene). `--binaries-only [<inventory>]` checks only the image inventory.
 */

const ALLOWED = [
    'MIT', 'MIT-0', 'BSD-2-Clause', 'BSD-3-Clause', '0BSD', 'ISC', 'Apache-2.0', 'MPL-2.0',
    'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0-only', 'LGPL-3.0-or-later', 'GPL-2.0-or-later',
    'GPL-3.0-or-later', 'GPL-3.0-only', 'AGPL-3.0-only', 'AGPL-3.0-or-later', 'Unlicense', 'CC0-1.0', 'PHP-3.01',
    'Zlib', 'PostgreSQL',
];

/** A license expression passes when any `OR` alternative is allowed. */
function licenseAllowed(string $expression): bool
{
    foreach (preg_split('/\s+OR\s+/', trim($expression, ' ()')) ?: [] as $alternative) {
        if (in_array($alternative, ALLOWED, true)) {
            return true;
        }
    }

    return false;
}

function checkComposer(): int
{
    $data = json_decode((string) shell_exec('composer licenses --format=json 2>/dev/null'), true);
    if (! is_array($data) || ! isset($data['dependencies'])) {
        fwrite(STDERR, "Could not read composer licenses.\n");

        return 2;
    }

    $violations = [];
    foreach ($data['dependencies'] as $package => $info) {
        $licenses = $info['license'] ?? [];
        // A dual-licensed package is fine when any of its licenses is allowed.
        if ($licenses === [] || array_intersect($licenses, ALLOWED) === []) {
            $violations[] = sprintf('%s %s: %s', $package, $info['version'] ?? '?', implode(' OR ', $licenses) ?: 'none');
        }
    }

    if ($violations !== []) {
        fwrite(STDERR, "Disallowed Composer licenses:\n  ".implode("\n  ", $violations)."\n");

        return 1;
    }

    echo 'Composer licenses OK ('.count($data['dependencies'])." packages)\n";

    return 0;
}

function checkBinaries(string $inventory): int
{
    $data = is_file($inventory) ? json_decode((string) file_get_contents($inventory), true) : null;
    $binaries = is_array($data) ? ($data['binaries'] ?? null) : null;
    if (! is_array($binaries) || $binaries === []) {
        fwrite(STDERR, "Could not read the image binaries from {$inventory}.\n");

        return 2;
    }

    $violations = [];
    foreach ($binaries as $index => $binary) {
        $missing = array_filter(['name', 'version', 'source', 'license'], fn (string $key): bool => ! is_string($binary[$key] ?? null) || $binary[$key] === '');
        if ($missing !== [] || ($binary['paths'] ?? []) === [] || ($binary['license_files'] ?? []) === []) {
            $violations[] = sprintf('entry %d (%s): needs name, version, source, license, paths and license_files', $index, $binary['name'] ?? '?');
        } elseif (! licenseAllowed($binary['license'])) {
            $violations[] = sprintf('%s %s: %s', $binary['name'], $binary['version'], $binary['license']);
        }
    }

    if ($violations !== []) {
        fwrite(STDERR, "Disallowed or incomplete image binary licenses:\n  ".implode("\n  ", $violations)."\n");

        return 1;
    }

    echo 'Image binary licenses OK ('.count($binaries)." components)\n";

    return 0;
}

$binariesOnly = ($argv[1] ?? null) === '--binaries-only';
$inventory = $binariesOnly && isset($argv[2]) ? $argv[2] : dirname(__DIR__).'/docker/app/binaries.json';
$status = $binariesOnly ? 0 : checkComposer();

exit(max($status, checkBinaries($inventory)));
