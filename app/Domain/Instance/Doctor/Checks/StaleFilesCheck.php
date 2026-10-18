<?php

declare(strict_types=1);

namespace App\Domain\Instance\Doctor\Checks;

use App\Domain\Instance\Doctor\CheckResult;
use App\Domain\Instance\Doctor\DoctorCheck;
use App\Domain\Instance\Doctor\DoctorContext;
use App\Domain\Instance\HostFacts;

/**
 * Removes nonce and browser hand-over files that interrupted fsa runs left in the instance directory (ADR 0070).
 * Files of the current run and anything younger than an hour (another run may still need it) stay.
 */
final readonly class StaleFilesCheck implements DoctorCheck
{
    public const int MAX_AGE_SECONDS = 3600;

    public function key(): string
    {
        return 'stale_files';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $files = $context->directory->files();
        $own = array_filter([$context->facts->nonce, $context->facts->openUrlFile]);
        $removed = [];
        foreach ($files->list('') as $name) {
            if (in_array($name, $own, true) || (preg_match(HostFacts::NONCE_PATTERN, $name) !== 1 && preg_match(HostFacts::OPEN_URL_PATTERN, $name) !== 1)) {
                continue;
            }
            $modified = $files->modifiedAt($name);
            if ($modified !== null && $context->probes->now() - $modified > self::MAX_AGE_SECONDS) {
                $files->delete($name);
                $removed[] = $name;
            }
        }

        return CheckResult::ok($this->key(), $removed === [] ? 'No stale hand-over files.' : 'Removed '.count($removed).' stale hand-over file(s) of interrupted runs.', ['removed' => $removed]);
    }
}
