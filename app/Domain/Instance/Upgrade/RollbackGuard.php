<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Backup\BackupEntry;
use App\Domain\Instance\Backup\BackupStore;
use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;

/**
 * Whether `fsa rollback` can return to the release before the last upgrade (R37, plan U18, ADR 0086), and what it would
 * lose. It needs the previous release the commit kept (version, digests, compose file) and that upgrade's safety backup
 * in `backups/`. It refuses (exit 7) after a restore, a reset, an install from a backup or an earlier rollback since that
 * upgrade: the pre-upgrade backup would silently undo them. The loss is counted by a socket-less one-off: what was
 * written since the safety backup.
 */
final readonly class RollbackGuard
{
    /** History events after which the pre-upgrade backup no longer describes the instance's past. */
    public const array BLOCKING = ['restored', 'reset', 'installed_from_backup', 'rolled_back'];

    /** What `instance:rollback-loss` counts, as a person calls it. */
    private const array LABELS = ['submissions' => 'submissions', 'activity_log' => 'audit entries', 'users' => 'accounts', 'forms' => 'forms', 'mail_messages' => 'mails', 'attachments' => 'attachments'];

    public function __construct(private InstanceDirectory $directory, private DockerGateway $docker, private BackupStore $store) {}

    /**
     * @return array{from: string|null, version: string, digests: array<string, string>, compose: string, backup: string, upgraded_at: string|null}
     *
     * @throws InstanceException exit 7
     */
    public function plan(InstanceState $state): array
    {
        $previous = $state->previous();
        $history = $state->history();
        $index = array_key_last(array_filter($history, static fn (array $entry): bool => ($entry['event'] ?? null) === 'upgraded'));
        if ($previous === null || $index === null) {
            throw new InstanceException('There is no earlier release to return to: the instance was not upgraded since it was installed, or a rollback used the previous release already.', ExitCode::Incompatible, 'no_previous_release',
                '`fsa restore <backup>` restores a backup of this version; `fsa status` lists the backups.');
        }
        foreach (array_slice($history, $index + 1) as $entry) {
            if (in_array($entry['event'] ?? null, self::BLOCKING, true)) {
                throw new InstanceException("An operation since the upgrade to {$state->version()} replaced the data ({$entry['event']}, {$entry['at']}): returning to the pre-upgrade backup would undo it.", ExitCode::Incompatible, 'restored_since_upgrade',
                    'Restore the backup you want with `fsa restore <backup>` (the upgrade\'s safety backup is '.($history[$index]['safety_backup'] ?? 'gone').'). Nothing was changed.');
            }
        }
        $backup = $history[$index]['safety_backup'] ?? null;
        if (! is_string($backup) || ! $this->store->find($backup) instanceof BackupEntry) {
            throw new InstanceException('The upgrade\'s safety backup '.(is_string($backup) ? $backup : '').' is gone from backups/.', ExitCode::Incompatible, 'safety_backup_missing',
                'Restore another backup of '.$previous['version'].' with `fsa install --from-backup` into a new directory. Nothing was changed.');
        }

        return ['from' => $state->version(), 'version' => $previous['version'], 'digests' => $previous['digests'], 'compose' => $previous['compose'], 'backup' => $backup,
            'upgraded_at' => is_string($history[$index]['at'] ?? null) ? $history[$index]['at'] : null];
    }

    /**
     * What a rollback loses: rows written since the safety backup (R37), counted by the running database; null when it
     * cannot be counted (the database is down).
     *
     * @return array<string, int>|null
     */
    public function loss(InstanceState $state, string $since): ?array
    {
        $outcome = OneOffResult::fromOutcome($this->docker->composeRun(ComposeProject::forInstance($state, $this->directory), ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:rollback-loss', '--since='.$since, '--json'], timeout: 120.0));
        $counts = $outcome->data['counts'] ?? null;

        return $outcome->ok && is_array($counts) ? array_map(intval(...), $counts) : null;
    }

    /** "12 submissions, 340 audit entries" (only what is there), or a plain statement when nothing could be counted. */
    public static function describe(?array $loss): string
    {
        if ($loss === null) {
            return 'Everything written since then is lost (it could not be counted).';
        }
        $parts = [];
        foreach ($loss as $table => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.(self::LABELS[$table] ?? $table);
            }
        }

        return $parts === [] ? 'Nothing was written since then.' : 'Everything written since then is lost: '.implode(', ', $parts).'.';
    }
}
