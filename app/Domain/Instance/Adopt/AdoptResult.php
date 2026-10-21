<?php

declare(strict_types=1);

namespace App\Domain\Instance\Adopt;

use App\Domain\Instance\Install\InstallSmoke;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\MailSummary;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\InvalidInput;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceSettings;
use App\Domain\Instance\State\InstanceState;
use Throwable;

/**
 * The envelopes of `fieldset adopt` (plan U19, ADR 0087): the install summary plus where the data came from (checkout, project,
 * the 1.0-format backup, what was verified), the SMTP server to confirm, and the way back; a finished adopt of the same
 * checkout changes nothing; a failed one says that 1.0 runs again and why.
 */
final readonly class AdoptResult
{
    public const string COMMAND = 'adopt';

    /**
     * @param  array<string, mixed>  $preflight  the doctor phase's data
     * @param  array<string, mixed>  $data  quiesce, backup, copy and verify phase data
     */
    public static function build(InstanceState $state, InstanceDirectory $directory, LegacyInstall $legacy, array $preflight, array $data): CommandResult
    {
        $settings = InstanceSettings::of($state);
        $fieldset = $directory->root.'/fieldset';
        $pending = is_array($preflight['pending_mail'] ?? null) ? $preflight['pending_mail'] : null;
        $backup = is_array($data['backup'] ?? null) ? $data['backup'] : [];
        $verify = is_array($data['verify'] ?? null) ? $data['verify'] : [];
        $drain = is_array($data['quiesce']['drain'] ?? null) ? $data['quiesce']['drain'] : [];
        $result = CommandResult::ok(self::COMMAND, [
            'url' => $settings->instanceUrl(), 'version' => $state->version(), 'mode' => $state->mode(), 'dir' => $directory->root, 'project' => $state->project(),
            'port' => $settings->publishesAppPort() ? $settings->port() : null, 'mail' => MailSummary::of($state),
            'adopted' => [
                'checkout' => $legacy->checkout, 'project' => $legacy->project, 'version' => $legacy->version,
                'backup' => $backup['name'] ?? null, 'backup_path' => $backup['path'] ?? null,
                'verified' => ['counts' => $verify['snapshot']['counts'] ?? [], 'chains' => count((array) ($verify['snapshot']['heads'] ?? [])), 'amcheck' => $verify['amcheck']['checked'] ?? []],
                'drain' => $drain, 'volumes' => $data['copy']['volumes'] ?? [], 'legacy_volumes' => $legacy->volumes,
            ],
            'pending_mail' => $pending,
            'way_back' => self::wayBack($fieldset, $legacy),
            'next_commands' => ["{$fieldset} status", "{$fieldset} login-link <admin-email>", "{$fieldset} doctor --post"],
        ], changed: true);

        return self::smokeWarnings(self::warnings($result, $pending, $drain, $backup), is_array($data['smoke'] ?? null) ? $data['smoke'] : []);
    }

    /**
     * A finished instance: adopting the same checkout again changes nothing; anything else is exit 2.
     *
     * @throws InvalidInput
     */
    public static function unchanged(InstanceState $state, InstanceDirectory $directory, AdoptRequest $request): CommandResult
    {
        $adopted = AdoptedInstance::record($state);
        if ($adopted === null || ($adopted['checkout'] ?? null) !== $request->checkout) {
            throw new InvalidInput(
                $adopted === null ? 'This directory holds an installed instance; adopt creates a new one.' : 'This instance was adopted from '.($adopted['checkout'] ?? '?').', not from '.$request->checkout.'.',
                'already_installed',
                'Run adopt in a new directory (install.sh --dir <new directory> --adopt <checkout>).',
            );
        }
        $settings = InstanceSettings::of($state);

        return CommandResult::ok(self::COMMAND, ['url' => $settings->instanceUrl(), 'version' => $state->version(), 'mode' => $state->mode(), 'dir' => $directory->root,
            'project' => $state->project(), 'adopted' => $adopted], changed: false);
    }

    /**
     * An adopt that failed and went back: 1.0 runs again (exit 5), or nothing had changed yet (exit 9).
     *
     * @param  array{restarted: list<string>, not_running: list<string>, removed: list<string>, left: list<string>}  $undo
     */
    public static function rolledBack(Throwable $cause, bool $touched, array $undo, LegacyInstall $legacy): CommandResult
    {
        $instance = $cause instanceof InstanceException ? $cause : null;
        $code = $instance->errorCode ?? 'internal_error';
        $back = $undo['not_running'] === [] ? '1.0 runs again with its own volumes' : '1.0 was started again with its own volumes';
        $message = 'Adopt failed: '.rtrim($cause->getMessage(), '. ').'. '.($touched ? $back.($undo['left'] === [] ? '; nothing of the new instance is left.' : '.') : 'Nothing was changed.');
        $result = CommandResult::failed(self::COMMAND, $touched ? ExitCode::RolledBack : ExitCode::AbortedUnchanged, $code, $message, $instance?->hint, $instance->retryable ?? false,
            [...($instance->data ?? []), 'rollback' => $undo, 'checkout' => $legacy->checkout], $touched);

        return self::undoWarnings($result, $undo, $legacy);
    }

    /** @param  array{restarted: list<string>, not_running: list<string>, removed: list<string>, left: list<string>}  $undo */
    public static function undoWarnings(CommandResult $result, array $undo, LegacyInstall $legacy): CommandResult
    {
        if ($undo['not_running'] !== []) {
            $result = $result->withWarning('legacy_not_running', 'These 1.0 services were started again but do not run: '.implode(', ', $undo['not_running']).'.', "Read `docker compose logs` in {$legacy->checkout} and start them with `docker compose up -d` there.");
        }

        return $undo['left'] === [] ? $result : $result->withWarning('adopt_leftovers', 'These could not be removed: '.implode(', ', $undo['left']).'.', 'Remove them with `docker rm -f`, `docker network rm` and `docker volume rm`.');
    }

    /** @return list<string> */
    public static function wayBack(string $fieldset, LegacyInstall $legacy): array
    {
        return ["{$fieldset} uninstall (a final backup first; the 1.0 volumes were never changed)", "cd {$legacy->checkout} && docker compose up -d"];
    }

    /**
     * @param  array<string, mixed>|null  $pending
     * @param  array<string, mixed>  $drain
     * @param  array<string, mixed>  $backup
     */
    private static function warnings(CommandResult $result, ?array $pending, array $drain, array $backup): CommandResult
    {
        if ($pending !== null) {
            $result = $result->withWarning('mail_captured_after_adopt', 'Mail is captured by the instance\'s catcher until you confirm the SMTP server 1.0 used ('.$pending['host'].').', (string) $pending['command']);
        }
        if (($drain['drained'] ?? true) === false) {
            $result = $result->withWarning('jobs_left_in_1_0', 'With --drop-jobs, '.(int) ($drain['waiting'] ?? 0).' waiting and '.(int) ($drain['reserved'] ?? 0).' running job(s) stayed in 1.0\'s Valkey.', 'They run if 1.0 is started again; stalled PDFs and workflow steps are picked up again by the scheduler.');
        } elseif (array_key_exists('drained', $drain) && $drain['drained'] === null) {
            $result = $result->withWarning('drain_unknown', 'With --drop-jobs, the jobs in 1.0\'s Valkey were not counted; the queue was stopped with its grace period.', 'Jobs left run if 1.0 is started again; stalled PDFs and workflow steps are picked up again by the scheduler.');
        }
        if ((int) ($drain['delayed'] ?? 0) > 0) {
            $result = $result->withWarning('delayed_jobs_left_in_1_0', (int) $drain['delayed'].' delayed job(s) (retries, scheduled sends) stayed in 1.0\'s Valkey.', 'Check the mail log for mails left queued.');
        }
        foreach (is_array($backup['warnings'] ?? null) ? $backup['warnings'] : [] as $warning) {
            $result = $result->withWarning('legacy_backup_warning', (string) $warning);
        }

        return $result;
    }

    /** @param  array<string, mixed>  $smoke  the smoke phase's data */
    private static function smokeWarnings(CommandResult $result, array $smoke): CommandResult
    {
        if (! in_array(InstallSmoke::MAIL_SKIPPED, is_array($smoke['checked'] ?? null) ? $smoke['checked'] : [], true)) {
            return $result;
        }

        return $result->withWarning('test_mail_skipped', 'No test mail was sent: the 1.0 data has no active operator (1.0 granted them with `app:platform-admin`).', 'Name one with `fieldset config set operator-email <email>`; it can then open /platform and receives instance mail.');
    }
}
