<?php

declare(strict_types=1);

/*
 * Per-path coverage gates on top of the global --min=80 (BUILD_PROMPT §12):
 * schema core and tenancy code need at least 95% line coverage.
 * Usage: php scripts/coverage-thresholds.php storage/coverage/clover.xml
 */

$thresholds = [
    'packages/form-schema-php/src/' => 95.0,
    'app/Domain/Forms/Schema/' => 95.0,
    'app/Domain/Identity/Tenancy/' => 95.0,
];

$file = $argv[1] ?? 'storage/coverage/clover.xml';
$xml = @simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "Cannot read {$file}\n");
    exit(2);
}

$root = dirname(__DIR__).'/';
$totals = array_fill_keys(array_keys($thresholds), ['covered' => 0, 'total' => 0]);

foreach ($xml->xpath('//file') ?: [] as $node) {
    $path = str_replace($root, '', (string) $node['name']);
    foreach ($thresholds as $prefix => $_) {
        if (str_starts_with($path, $prefix)) {
            $metrics = $node->metrics;
            $totals[$prefix]['covered'] += (int) $metrics['coveredstatements'];
            $totals[$prefix]['total'] += (int) $metrics['statements'];
        }
    }
}

$failed = false;
foreach ($thresholds as $prefix => $min) {
    ['covered' => $c, 'total' => $t] = $totals[$prefix];
    if ($t === 0) {
        echo "{$prefix}: no code yet\n";

        continue;
    }
    $pct = round($c / $t * 100, 2);
    echo "{$prefix}: {$pct}% (min {$min}%)\n";
    $failed = $failed || $pct < $min;
}

exit($failed ? 1 : 0);
