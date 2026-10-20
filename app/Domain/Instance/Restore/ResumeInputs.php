<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

use App\Domain\Instance\Operations\JournalRecord;
use App\Domain\Instance\Operations\OperationUnfinished;
use App\Domain\Instance\Output\InvalidInput;

/**
 * A resumed restore, rollback or install from a backup prepares the backup again (decrypt, check) before it changes more.
 * Started without an input the first run had, that step would fail on an instance the first run had quiesced and take the
 * way back (exit 5) although only a flag was missing (M12 gate O2). So the inputs are compared with the journal before
 * the run takes the lock: exit 2 (`identity_required`, `backup_unauthenticated`, `app_key_required`,
 * `backup_without_files`) with the full resume command, nothing written to the journal and nothing changed by this run.
 */
final class ResumeInputs
{
    /**
     * @param  bool  $prepares  whether the resumed run prepares the backup again (not once its swap began or it goes back)
     *
     * @throws InvalidInput
     */
    public static function assert(JournalRecord $open, RestoreRequest $request, bool $prepares): void
    {
        if (! $prepares) {
            return;
        }
        $recorded = $open->context();
        $identity = is_string($recorded['identity_file'] ?? null) && $recorded['identity_file'] !== '';
        $missing = match (true) {
            ($recorded['accept_unauthenticated'] ?? false) === true && ! $request->acceptUnauthenticated => ['backup_unauthenticated', '--accept-unauthenticated'],
            $identity && $request->identityFile === null => ['identity_required', '--identity-file'],
            $identity && $request->passphrase === null => ['identity_required', 'the passphrase (--passphrase-file or --passphrase-stdin)'],
            ($recorded['key_file'] ?? false) === true && ! $request->keyFile instanceof AppKeys => ['app_key_required', '--app-key-file'],
            ($recorded['without_files'] ?? false) === true && ! $request->withoutFiles => ['backup_without_files', '--without-files'],
            default => null,
        };
        if ($missing === null) {
            return;
        }
        $command = OperationUnfinished::commandLine($open);

        throw new InvalidInput("The interrupted `{$open->operation()}` was started with {$missing[1]}: it checks the backup again when it resumes and needs it again.", $missing[0],
            "Run `{$command}`; nothing was changed by this run.", ['command' => $command, 'options' => OperationUnfinished::options($open)]);
    }
}
