<?php

declare(strict_types=1);

namespace App\Domain\Instance\Tunnel;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\Output\ProgressReporter;

/**
 * Who would be exposed by the quick tunnel or the local-CA edge (R29, R65, ADR 0081, ADR 0082), asked through the
 * read-only one-off `app:demo-passwords --check` (AppCommands::demoPasswordCheck()): accounts that still sign in with
 * the documented demo password `password`, split into demo accounts (fieldset rotates them first) and others (fieldset refuses
 * until their owners changed them), and the organizations besides the demo (`fieldset tunnel start` asks for `--yes`).
 */
final readonly class ExposureCheck
{
    /** The progress phase (and `fieldset tunnel start`'s journaled phase) of the check. */
    public const string PHASE = 'accounts';

    /**
     * @param  list<string>  $demoDefaults  demo accounts on `password`
     * @param  list<string>  $otherDefaults  other accounts on `password`
     */
    public function __construct(
        public array $demoDefaults,
        public array $otherDefaults,
        public int $nonDemoOrganizations,
    ) {}

    /** The longest the check takes: the one-off's timeout. */
    public static function maxSeconds(): int
    {
        return (int) AppCommands::TIMEOUT;
    }

    /**
     * With `$progress` (a check outside a journaled phase, before the lock) it reports its start with the maximum
     * duration and its end, as a phase would (ADR 0078).
     *
     * @throws InstanceException exit 4 when the one-off fails (retryable)
     */
    public static function run(AppCommands $app, ComposeProject $project, ?ProgressReporter $progress = null): self
    {
        $progress?->event(self::PHASE, ProgressReporter::INFO, 'Starting '.self::PHASE.' (at most '.OperationRun::duration(self::maxSeconds()).')', ['max_seconds' => self::maxSeconds()]);
        $started = hrtime(true);
        $result = $app->demoPasswordCheck($project);
        $elapsed = (int) round((hrtime(true) - $started) / 1e9);
        $progress?->event(self::PHASE, $result->ok ? ProgressReporter::INFO : ProgressReporter::ERROR, ($result->ok ? 'Completed '.self::PHASE.' in ' : self::PHASE.' failed after ').OperationRun::duration($elapsed), ['elapsed_seconds' => $elapsed]);
        if (! $result->ok) {
            throw new InstanceException('The accounts could not be checked: `app:demo-passwords --check` failed: '.$result->errorMessage(), ExitCode::HealthCheckFailed, 'account_check_failed', 'Check that the instance runs (`fieldset status`, `fieldset logs app`), then run the command again.', true, ['output' => $result->output]);
        }
        $users = array_values(array_filter(is_array($result->data['users'] ?? null) ? $result->data['users'] : [], static fn (mixed $user): bool => is_array($user) && is_string($user['email'] ?? null)));
        $emails = static fn (bool $demo): array => array_values(array_map(static fn (array $user): string => $user['email'], array_filter($users, static fn (array $user): bool => (($user['demo'] ?? false) === true) === $demo)));
        $demo = $emails(true);
        $other = $emails(false);
        $organizations = is_array($result->data['organizations'] ?? null) ? $result->data['organizations'] : [];
        $nonDemo = $organizations['non_demo'] ?? null;

        return new self($demo, $other, is_int($nonDemo) ? $nonDemo : 0);
    }

    /** @return array{demo_defaults: int, other_defaults: int, non_demo_organizations: int} what a journal may keep: counts, no e-mail */
    public function counts(): array
    {
        return ['demo_defaults' => count($this->demoDefaults), 'other_defaults' => count($this->otherDefaults), 'non_demo_organizations' => $this->nonDemoOrganizations];
    }

    /**
     * Exit 3 while an account that is not a demo account signs in with `password`: fieldset never changes a real user's
     * password (ADR 0081), and the instance is about to become reachable beyond loopback.
     *
     * @throws InstanceException
     */
    public function refuseOtherDefaults(string $what): void
    {
        if ($this->otherDefaults === []) {
            return;
        }

        throw new InstanceException(
            count($this->otherDefaults)." account(s) still sign in with the demo password \"password\": {$what} would make them reachable beyond this machine.",
            ExitCode::PreflightFailed,
            'default_password_in_use',
            'Change the password of '.implode(', ', array_slice($this->otherDefaults, 0, 10)).' (admin panel or the password reset), then run the command again. Demo accounts are rotated by `fieldset demo --rotate-passwords`.',
            false,
            ['users' => $this->otherDefaults],
        );
    }
}
