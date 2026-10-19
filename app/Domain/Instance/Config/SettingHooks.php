<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\InstanceException;

/**
 * Optional steps of a SettingApplier beyond render and the smoke test (ADR 0080), run by ConfigSetter:
 *
 * - `verify()` after the standard checks of the verify phase, on the new settings (`config set mail smtp` sends its
 *   test mail there). A failure reverts the change like any failed check.
 * - `completed()` once the workers run on the verified settings, before the audit: clean-up and what the result
 *   reports (the catcher volume, the captured invitations). It never reverts; a problem becomes a warning.
 */
interface SettingHooks
{
    /** Seconds verify() may take at most (added to the verify phase's progress limit). */
    public function verifySeconds(): int;

    /**
     * @return list<string> what was checked
     *
     * @throws InstanceException when the check fails (the change is reverted)
     */
    public function verify(ConfigChange $change, ConfigRequest $request, ComposeProject $project): array;

    /** @return array{data: array<string, mixed>, warnings: list<array{code: string, message: string, hint: string|null}>} */
    public function completed(ConfigChange $change, ConfigRequest $request, ComposeProject $project): array;
}
