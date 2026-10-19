<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Release\ReleaseException;
use App\Domain\Instance\Release\ReleaseManifest;
use App\Domain\Instance\Release\ReleaseVersion;

/**
 * Whether the installed version may move to a target release (R33, §7 exit 7, plan U18, ADR 0086), decided before
 * anything changes: upgrades only move forward (an older `--to` is `fsa rollback`'s job), a major is crossed only with
 * `--major`, and the installed version must be at least the target manifest's oldest upgrade source; each refusal names
 * the way that works instead. The installed version itself is a no-op.
 */
final class UpgradePath
{
    /**
     * @return bool false when the instance already runs the target (nothing to do)
     *
     * @throws InstanceException exit 7
     */
    public static function assess(?string $installed, ReleaseManifest $target, bool $major): bool
    {
        $from = self::parse($installed);
        $to = $target->version;
        $order = $from->compare($to);
        if ($order === 0) {
            return false;
        }
        if ($order > 0) {
            throw new InstanceException("{$to} is older than the installed {$from}: upgrades only move forward.", ExitCode::Incompatible, 'downgrade_refused',
                '`fsa rollback` returns to the version before the last upgrade (from its safety backup); an older release needs a backup made by it (`fsa install --from-backup`). Nothing was changed.');
        }
        if ($to->major !== $from->major && ! $major) {
            throw new InstanceException("{$to} is a new major version ({$from->major} → {$to->major}).", ExitCode::Incompatible, 'major_upgrade',
                "Read the release notes ({$target->notesUrl}), then run `fsa upgrade --to {$to} --major`. Nothing was changed.");
        }
        if ($from->compare($target->upgradesFrom) < 0) {
            throw new InstanceException("{$to} upgrades only from {$target->upgradesFrom} or newer; this instance runs {$from}.", ExitCode::Incompatible, 'upgrade_path',
                "Upgrade in two steps: `fsa upgrade --to {$target->upgradesFrom}`, then `fsa upgrade --to {$to}`. Nothing was changed.", false, ['path' => [(string) $from, (string) $target->upgradesFrom, (string) $to]]);
        }

        return true;
    }

    /** @throws InstanceException exit 7 when the installed version is unknown or unreadable */
    private static function parse(?string $installed): ReleaseVersion
    {
        try {
            return ReleaseVersion::parse((string) $installed);
        } catch (ReleaseException) {
            throw new InstanceException('The installed version ('.($installed ?? 'unknown').') is no release this fsa can upgrade.', ExitCode::Incompatible, 'unsupported_source', 'Reinstall from a release (`fsa backup`, then `fsa install --from-backup` into a new directory). Nothing was changed.');
        }
    }
}
