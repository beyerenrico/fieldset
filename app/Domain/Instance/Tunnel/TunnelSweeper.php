<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Console\FieldsetContext;
use App\Domain\Instance\Install\InstallRecord;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationJournal;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * AE6, KTD12, ADR 0057/0082: nothing runs at the 4-hour mark (only a human command gets the Docker socket), so the app
 * answers the expired tunnel host with 503 on its own and `fieldset status` only reports it. The next mutating `fieldset` command
 * (`config set`, `demo`, `tunnel …`) first removes the tunnel through this sweeper, under the lock and journaled
 * (operation `tunnel`, action `expire`): runtime block, cloudflared and the app's tunnel network go, and the URL is
 * local again. A tunnel whose cloudflared stopped (it never restarts, `restart: "no"`) is removed the same way: its
 * hostname is gone for good.
 *
 * An unfinished `tunnel` journal (a start whose revert failed, a cut-off stop, renew or cleanup) is finished the same
 * way, whatever the state says, unless a live tunnel would go: then the command answers exit 9 naming `fieldset tunnel
 * stop`. Another operation's journal is left to the command, which resumes or names it, so no command is blocked by
 * the cleanup itself.
 */
final readonly class TunnelSweeper
{
    public function __construct(
        private InstanceDirectory $directory,
        private OperationJournal $journal,
        private TunnelStatus $status,
        private TunnelOperation $tunnel,
    ) {}

    /** @return array{0: string, 1: string, 2: string|null}|null the warning for the command's result, null when nothing was swept */
    public function sweep(FieldsetContext $context): ?array
    {
        $state = $this->directory->hasState() ? $this->directory->state() : null;
        $open = $this->journal->current();
        if (! $state instanceof InstanceState || ! InstallRecord::isInstalled($state) || ($open instanceof JournalRecord && $open->operation() !== TunnelOperation::OPERATION)) {
            return null;
        }
        $current = $this->status->of($state);
        if (! $this->due($state, $current, $open)) {
            return null;
        }
        $result = $this->tunnel->expire($context, $current, resume: $open instanceof JournalRecord);
        if ($result->exitCode->value !== 0) {
            $what = $open instanceof JournalRecord ? 'The unfinished `'.OperationUnfinished::cutOff($open).'` could not be finished' : 'The '.$current['state'].' quick tunnel could not be removed';

            return ['tunnel_cleanup_failed', $what.': '.($result->errors[0]->message ?? 'unknown error'), 'Fix the cause, then run `fieldset tunnel stop`.'];
        }
        $url = is_string($result->data['url'] ?? null) ? $result->data['url'] : '';
        if ($open instanceof JournalRecord) {
            return ['tunnel_removed', 'An unfinished `'.OperationUnfinished::cutOff($open)."` was finished: the quick tunnel was removed and the instance URL is {$url} again.", 'Start a new one with `fieldset tunnel start`.'];
        }
        $what = $current['state'] === 'expired' ? "expired at {$current['expires_at']}" : 'stopped running';

        return ['tunnel_removed', 'The quick tunnel'.($current['hostname'] === null ? '' : " {$current['hostname']}")." {$what} and was removed; the instance URL is {$url} again.", 'Start a new one with `fieldset tunnel start`.'];
    }

    /**
     * Runs the command after a sweep and keeps the sweep in its result, also when the command itself fails: the removal
     * happened (`changed`), and the warning says so.
     *
     * @param  array{0: string, 1: string, 2: string|null}|null  $warning  what sweep() returned
     * @param  Closure(): CommandResult  $command
     */
    public static function then(string $name, ?array $warning, Closure $command): CommandResult
    {
        try {
            $result = $command();
        } catch (InstanceException $e) {
            $result = CommandResult::fromException($name, $e, $warning !== null);
        }

        return $warning === null ? $result : $result->withChanged(true)->withWarning(...$warning);
    }

    /**
     * Only a tunnel fieldset started (the state says so) that expired or stopped; a stray runtime block is `fieldset tunnel stop`'s
     * to clear. With an unfinished tunnel journal, anything but a live tunnel.
     *
     * @param  array<string, mixed>  $current  TunnelStatus::of()
     */
    private function due(InstanceState $state, array $current, ?JournalRecord $open): bool
    {
        if ($open instanceof JournalRecord) {
            return $current['state'] !== 'active';
        }

        return InstanceSettings::of($state)->tunnel() && in_array($current['state'], ['expired', 'stopped'], true);
    }
}
