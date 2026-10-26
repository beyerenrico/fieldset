<?php

declare(strict_types=1);

namespace App\Domain\Instance\Lifecycle;

use App\Domain\Instance\HostFacts;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\ContainerIdentity;
use App\Domain\Instance\Operations\InstanceLock;
use App\Domain\Instance\Operations\LockHandle;
use App\Domain\Instance\Operations\LockHeld;
use App\Domain\Instance\Output\ProgressReporter;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\SafeFiles;
use App\Domain\Instance\State\UnsafePath;
use Throwable;

/**
 * Newer images refresh an older `fieldset` script (2.1.1 U15, KTD7, R38). Only install.sh writes `<dir>/fieldset`, so
 * without this no shim change would reach an upgraded instance. After a command the shim started (FieldsetCommand),
 * when the shim's version (FIELDSET_LAUNCHER_VERSION, none before 2.1.1) is lower than this image's distribution/shim.sh,
 * the instance's script becomes the image's:
 *
 * - under the instance lock, taken without waiting and without taking over a stale lock: while another operation holds
 *   it nothing happens and a later command refreshes; read-only commands refresh too, as the write is atomic;
 * - only while the script on disk is still older (another command may have refreshed it meanwhile) and a regular file;
 * - as a temporary file in the instance directory, mode 0700 and the old script's owner set before any byte, fsynced
 *   and renamed over the old one (SafeFiles::writeAtomically): the shim still running keeps reading its own file, and
 *   the next command finds the old or the new script, never a partial one. The ops user writes as the host user (IDs pass
 *   through) or as container root that is the host user; a script of another owner it cannot give back stays;
 * - with one progress line (stderr; an NDJSON event with --json) that the next command uses it. A failure keeps the old
 *   script, warns, and never changes the command's result.
 *
 * A launcher with the image's version or a newer one (after a rollback) changes nothing. Neither does install.sh (it
 * writes the script itself), a command started without the shim, an unmarked directory, nor `uninstall`.
 */
final readonly class ScriptRefresh
{
    public const string OPERATION = 'script-refresh';

    public const string PHASE = 'launcher';

    /**
     * @param  string|null  $bundledPath  this image's shim (default: distribution/shim.sh)
     * @param  int|null  $effectiveUid  the ops user (default: this process's)
     */
    public function __construct(
        private InstanceDirectory $directory,
        private InstanceLock $lock,
        private ContainerIdentity $identity,
        private ?string $bundledPath = null,
        private ?int $effectiveUid = null,
    ) {}

    /** Replaces an older script with the image's (true) or leaves it, after warning when that failed (false). */
    public function afterCommand(HostFacts $facts, ProgressReporter $progress): bool
    {
        try {
            $bundled = $this->wanted($facts);
            if (! $bundled instanceof LauncherScript) {
                return false;
            }
            $handle = $this->lock->acquire($this->identity->holder('fieldset', $facts->callerUid()), self::OPERATION, mayTakeOver: false);
        } catch (LockHeld) {
            return false;
        } catch (Throwable $e) {
            return $this->warn($progress, $e);
        }

        try {
            $replaced = $this->replace($bundled);
        } catch (Throwable $e) {
            return $this->warn($progress, $e);
        } finally {
            $this->release($handle);
        }
        if ($replaced) {
            $progress->phase(self::PHASE, "Updated {$this->path()} to the fieldset script of this release; the next command uses it.");
        }

        return $replaced;
    }

    /** The image's script when the shim started this command and its script looks older than the image's (checked again under the lock). */
    private function wanted(HostFacts $facts): ?LauncherScript
    {
        if ($facts->launcher !== 'shim' || ! $facts->launched() || ! InstanceDirectory::isMarked($this->directory->root)) {
            return null;
        }
        $bundled = LauncherScript::bundled($this->bundledPath);
        if (! $bundled instanceof LauncherScript || ($facts->launcherVersion ?? 0) >= $bundled->version) {
            return null;
        }
        $path = $this->path();
        if (! file_exists($path) && ! is_link($path)) {
            return null; // no script to refresh: install writes it
        }
        $current = LauncherScript::read($path);

        return $current instanceof LauncherScript && $current->version >= $bundled->version ? null : $bundled;
    }

    /** @throws UnsafePath|InstanceException when the script cannot be replaced safely */
    private function replace(LauncherScript $bundled): bool
    {
        $path = $this->path();
        $current = LauncherScript::read($path) ?? throw new UnsafePath($path, 'it is not a regular file');
        if ($current->version >= $bundled->version) {
            return false;
        }
        $stat = @lstat($path) ?: throw new UnsafePath($path, 'its owner cannot be read');
        $euid = $this->effectiveUid ?? (function_exists('posix_geteuid') ? posix_geteuid() : 0);
        if ($euid !== 0 && $euid !== $stat['uid']) {
            throw new InstanceException("{$path} belongs to UID {$stat['uid']}, and fieldset runs as UID {$euid}.");
        }
        SafeFiles::writeAtomically($path, $bundled->contents, 0700, [$stat['uid'], $stat['gid']]);

        return true;
    }

    private function warn(ProgressReporter $progress, Throwable $e): bool
    {
        $progress->warn(self::PHASE, "Kept the old fieldset script, as it could not be updated to the one of this release: {$e->getMessage()} A later command tries again.");

        return false;
    }

    private function release(LockHandle $handle): void
    {
        try {
            $this->lock->release($handle);
        } catch (Throwable) {
            // left to the next state-changing command, which takes over the lock of this exited container (InstanceLock)
        }
    }

    private function path(): string
    {
        return $this->directory->path(LauncherScript::FILE);
    }
}
