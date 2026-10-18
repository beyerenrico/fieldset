<?php

declare(strict_types=1);

namespace App\Domain\Instance\Config\Appliers;

use App\Domain\Instance\Compose\Services\MailpitService;
use App\Domain\Instance\Config\ConfigChange;
use App\Domain\Instance\Config\ConfigFailure;
use App\Domain\Instance\Config\ConfigRequest;
use App\Domain\Instance\Config\SmtpCredentials;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Docker\DockerResource;
use App\Domain\Instance\Docker\Labels;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceSettings;
use Throwable;

/**
 * The steps of `fsa config set mail` around the rollout (ADR 0080), all in socket-less one-offs or read-only Docker
 * calls:
 *
 * - the test mail through the new SMTP server, in verify: a refusal fails the change, which then reverts;
 * - once the change runs: the catcher's volume removed after `--remove-catcher` (Compose removes the container as an
 *   orphan, never a volume), and the invitations and password resets capture mode kept from people, counted from the
 *   app's records, so the operator knows what to resend.
 */
final readonly class MailCompletion
{
    /** The test mail's one-off (AppCommands::MAIL_TEST_TIMEOUT, 120 s) plus a margin. */
    public const int VERIFY_SECONDS = 130;

    public function __construct(private AppCommands $app, private DockerGateway $docker) {}

    /**
     * @return list<string>
     *
     * @throws InstanceException exit 4 `test_mail_failed` (the change is reverted)
     */
    public function testMail(ConfigChange $change, ConfigRequest $request, ComposeProject $project): array
    {
        if (! self::changesDelivery($change)) {
            return [];
        }
        $to = $request->mail?->testTo;
        $result = $this->app->mailTest($project, $to === null ? null : strtolower(trim($to)));
        if (! $result->ok) {
            throw new InstanceException('The SMTP server did not accept the test mail: '.$result->errorMessage(), ExitCode::HealthCheckFailed, 'test_mail_failed', ConfigFailure::hint('test_mail_failed', $change), false, ['error' => $result->errorCode(), 'server' => MailApplier::describe($change->after)]);
        }
        $recipient = is_string($result->data['to'] ?? null) ? $result->data['to'] : 'the operator';

        return ["test mail to {$recipient} accepted by ".MailApplier::describe($change->after)];
    }

    /** @return array{data: array<string, mixed>, warnings: list<array{code: string, message: string, hint: string|null}>} */
    public function completed(ConfigChange $change, ComposeProject $project): array
    {
        $before = InstanceSettings::of($change->before);
        $after = InstanceSettings::of($change->after);
        $mail = ['mode' => $after->capturesMail() ? 'capture' : 'smtp', 'catcher' => $this->catcher($after), 'catcher_url' => $after->catcherUrl()];
        $warnings = [];
        if ($before->runsCatcher() && ! $after->runsCatcher()) {
            [$mail['catcher_volume_removed'], $warning] = $this->removeVolume($change, $project);
            $warnings = [...$warnings, ...$warning];
        }
        if ($before->capturesMail() && ! $after->capturesMail()) {
            [$mail['captured'], $warning] = $this->captured($project);
            $warnings = [...$warnings, ...$warning];
        }
        if ($after->keepsCatcher()) {
            $mail['remove_catcher_command'] = 'fsa config set mail smtp --remove-catcher';
        }

        return ['data' => ['mail' => $mail], 'warnings' => $warnings];
    }

    /** Whether the mail goes out differently now: from capture, or another server, port, encryption, sender or account. */
    public static function changesDelivery(ConfigChange $change): bool
    {
        $after = InstanceSettings::of($change->after);
        if ($after->capturesMail()) {
            return false;
        }
        $before = InstanceSettings::of($change->before);
        $route = static fn (InstanceSettings $s): array => [$s->smtpHost(), $s->smtpPort(), $s->smtpScheme(), $s->mailFromAddress(), $s->mailFromName()];

        return $before->capturesMail() || $change->credentials instanceof SmtpCredentials || $route($before) !== $route($after);
    }

    private function catcher(InstanceSettings $settings): string
    {
        return match (true) {
            $settings->capturesMail() => 'active',
            $settings->keepsCatcher() => 'kept',
            default => 'removed',
        };
    }

    /** @return array{0: string|null, 1: list<array{code: string, message: string, hint: string|null}>} */
    private function removeVolume(ConfigChange $change, ComposeProject $project): array
    {
        $name = $project->name.'_'.MailpitService::VOLUME;

        try {
            $volumes = array_map(static fn (DockerResource $volume): string => $volume->name, $this->docker->volumes(Labels::forInstance($change->after->uuid())));
            if (! in_array($name, $volumes, true)) {
                return [null, []];
            }
            $this->docker->removeVolume($name);
        } catch (Throwable $e) {
            return [null, [['code' => 'catcher_volume_left', 'message' => "The catcher is gone, but its volume {$name} could not be removed: {$e->getMessage()}", 'hint' => "Remove it with `docker volume rm {$name}` once nothing uses it."]]];
        }

        return [$name, []];
    }

    /** @return array{0: array{invitations: int, password_resets: int}|null, 1: list<array{code: string, message: string, hint: string|null}>} */
    private function captured(ComposeProject $project): array
    {
        try {
            $result = $this->app->mailCaptured($project);
            $error = $result->ok ? null : $result->errorMessage();
        } catch (Throwable $e) {
            $result = null;
            $error = $e->getMessage();
        }
        if (! $result instanceof OneOffResult || $error !== null) {
            return [null, [['code' => 'captured_unknown', 'message' => "How many invitations and password resets were captured could not be read: {$error}.", 'hint' => 'Open the catcher, or check the pending invitations of each organization.']]];
        }
        $counts = ['invitations' => (int) ($result->data['invitations'] ?? 0), 'password_resets' => (int) ($result->data['password_resets'] ?? 0)];
        if ($counts['invitations'] + $counts['password_resets'] === 0) {
            return [$counts, []];
        }

        return [$counts, [[
            'code' => 'captured_mail',
            'message' => "{$counts['invitations']} pending invitation(s) and {$counts['password_resets']} open password reset(s) went to the catcher while mail was captured and never reached anyone.",
            'hint' => 'Resend the invitations from the organizations\' member lists; people waiting for a password reset request a new one.',
        ]]];
    }
}
