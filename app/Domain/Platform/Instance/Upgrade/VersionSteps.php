<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Upgrade;

use Illuminate\Database\Connection;
use InvalidArgumentException;

/**
 * The release's version steps (R36, plan U18, ADR 0086), in order. 2.0.0 ships none: its data changes are migrations.
 * A later release lists its steps in STEPS; the child runs those whose `since()` is newer than the version the instance
 * comes from (all of them when that is unknown, e.g. a restored 1.0 backup), which is safe because every step is
 * idempotent.
 */
class VersionSteps
{
    /** @var list<class-string<VersionStep>> */
    public const array STEPS = [];

    /** @param  list<VersionStep>|null  $steps */
    public function __construct(private ?array $steps = null) {}

    /** @return list<string> the names of the steps that ran */
    public function run(Connection $db, ?string $from): array
    {
        $ran = [];
        foreach ($this->steps() as $step) {
            if ($from !== null && self::compare($step->since(), $from) <= 0) {
                continue;
            }
            $step->run($db);
            $ran[] = $step->name();
        }

        return $ran;
    }

    /** @return list<VersionStep> */
    private function steps(): array
    {
        return $this->steps ?? array_map(static fn (string $class): VersionStep => app($class), self::STEPS);
    }

    /** Semver order of two release versions (pre-releases below their release), enough for `since` decisions. */
    private static function compare(string $a, string $b): int
    {
        $parse = static function (string $version): array {
            if (preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-(.+))?$/D', $version, $match) !== 1) {
                throw new InvalidArgumentException("Not a release version: {$version}.");
            }

            return [[(int) $match[1], (int) $match[2], (int) $match[3]], $match[4] ?? null];
        };
        [$coreA, $preA] = $parse($a);
        [$coreB, $preB] = $parse($b);
        $core = $coreA <=> $coreB;
        if ($core !== 0 || $preA === $preB) {
            return $core;
        }

        return $preA === null ? 1 : ($preB === null ? -1 : version_compare(str_replace('-', '.', $preA), str_replace('-', '.', $preB)));
    }
}
