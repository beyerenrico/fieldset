<?php

declare(strict_types=1);

/*
 * J10 helper (plan U15, ADR 0083), run on the host next to the journey: pins the instance's containers to two CPUs and
 * records memory/CPU peaks and OOM events (Tests\Support\Docker\EnvelopeMonitor).
 *
 *     php envelope-monitor.php <summary.json> <name-match> <cpuset> <instance-dir> <parent-pid>
 */

use Tests\Support\Docker\EnvelopeMonitor;

require dirname(__DIR__, 3).'/vendor/autoload.php';

if ($argc !== 6) {
    fwrite(STDERR, "usage: envelope-monitor.php <summary.json> <name-match> <cpuset> <instance-dir> <parent-pid>\n");
    exit(2);
}

exit(new EnvelopeMonitor($argv[1], $argv[2], $argv[3], $argv[4], (int) $argv[5])->run());
