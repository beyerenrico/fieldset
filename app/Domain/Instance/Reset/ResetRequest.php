<?php

declare(strict_types=1);

namespace App\Domain\Instance\Reset;

/**
 * The flags of `fsa reset` (§7, R43, plan U23, ADR 0088): `--demo` adds the demo data to the fresh instance, `--no-backup`
 * skips the safety backup (an audit export is still written while real organizations exist). They are the journal's
 * context: a resumed reset continues only with the same flags, and `fsa status` names the command with them.
 * `confirmed`: the operator said yes (`--yes` or the prompt); the operation checks it again under the lock (review S4).
 */
final readonly class ResetRequest
{
    public function __construct(public bool $demo, public bool $noBackup, public int $actorUid, public bool $confirmed = true) {}

    /** The question a reset that may still delete something asks first. */
    public static function question(bool $noBackup): string
    {
        return $noBackup
            ? 'Delete every organization, form, submission, user, file and captured mail of this instance WITHOUT a backup? Settings and secrets stay.'
            : 'Delete every organization, form, submission, user, file and captured mail of this instance and start fresh? A safety backup is taken first; settings and secrets stay.';
    }

    /** @return array{demo: bool, no_backup: bool} */
    public function toContext(): array
    {
        return ['demo' => $this->demo, 'no_backup' => $this->noBackup];
    }

    /** @param  array<string, mixed>  $context */
    public function sameAs(array $context): bool
    {
        return ($context['demo'] ?? false) === $this->demo && ($context['no_backup'] ?? false) === $this->noBackup;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, true> the options of the command a journal records
     */
    public static function options(array $context): array
    {
        return array_filter(['--demo' => ($context['demo'] ?? false) === true, '--no-backup' => ($context['no_backup'] ?? false) === true]);
    }

    /** @param  array<string, mixed>  $context */
    public static function commandLine(array $context): string
    {
        return trim('fsa reset '.implode(' ', array_keys(self::options($context))));
    }
}
