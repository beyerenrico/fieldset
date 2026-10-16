<?php

declare(strict_types=1);

/*
 * Fails when a Composer dependency (including dev) uses a license that is not OSI-approved and
 * compatible with AGPL-3.0 (BUILD_PROMPT §11 License hygiene).
 */

const ALLOWED = [
    'MIT', 'MIT-0', 'BSD-2-Clause', 'BSD-3-Clause', '0BSD', 'ISC', 'Apache-2.0', 'MPL-2.0',
    'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0-only', 'LGPL-3.0-or-later', 'GPL-3.0-or-later',
    'GPL-3.0-only', 'AGPL-3.0-only', 'AGPL-3.0-or-later', 'Unlicense', 'CC0-1.0', 'PHP-3.01', 'Zlib',
];

$json = shell_exec('composer licenses --format=json 2>/dev/null');
$data = json_decode((string) $json, true);

if (! is_array($data) || ! isset($data['dependencies'])) {
    fwrite(STDERR, "Could not read composer licenses.\n");
    exit(2);
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
    exit(1);
}

echo 'Composer licenses OK ('.count($data['dependencies'])." packages)\n";
