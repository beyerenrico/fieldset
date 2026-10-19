<?php

declare(strict_types=1);

namespace App\Domain\Instance\Demo;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\AppCommands;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Operations\OperationRun;
use App\Domain\Instance\Output\ExitCode;
use App\Domain\Instance\State\InstanceDirectory;
use App\Domain\Instance\State\InstanceState;
use Closure;

/**
 * Demo data on an instance (R29, R30, KTD25, ADR 0081), shared by `fsa install --demo` and `fsa demo`: the
 * app-side seeder and password rotation run as socket-less one-offs, the password mode follows the instance's
 * reachability (DemoPasswords), and the state history records `demo_seeded` / `demo_passwords_rotated` with the mode
 * (never a password), so a repeated `fsa install --demo` knows the demo is there.
 *
 * `provide()` is the journaled sequence of both commands (phases `demo`, then `demo_rotate` when needed; both run again
 * on a resume, the one-offs being idempotent): an existing demo whose accounts still have `password` on an instance
 * reachable beyond loopback is rotated at once; an existing demo whose random passwords were never seen (an
 * interrupted install) gets new ones when `$reissue` is set.
 */
final readonly class DemoData
{
    public const string SEEDED = 'demo_seeded';

    public const string ROTATED = 'demo_passwords_rotated';

    public function __construct(private AppCommands $app, private InstanceDirectory $directory) {}

    /** Whether the state history records demo data (installed with `--demo` or added by `fsa demo`). */
    public static function recorded(InstanceState $state): bool
    {
        return array_any($state->history(), static fn (array $entry): bool => ($entry['event'] ?? null) === self::SEEDED);
    }

    /**
     * @return array{0: DemoCredentials, 1: list<array{0: string, 1: string, 2: string|null}>} the credentials and warnings
     *
     * @throws InstanceException when a one-off fails (exit 4, retryable)
     */
    public function provide(OperationRun $run, ComposeProject $project, int $actorUid, bool $reissue): array
    {
        $credentials = $this->inPhase($run, 'demo', fn (): DemoCredentials => $this->seed($project, $actorUid));
        if ($credentials->seeded()) {
            return [$credentials, []];
        }
        $exposure = $credentials->data['exposure'];
        if ($exposure !== [] && $credentials->defaultPasswordUsers() > 0) {
            return [$credentials->withRotation($this->rotation($run, $project, $actorUid)), [[
                'demo_passwords_rotated',
                'The demo accounts still had the password "password" on an instance that is reachable beyond loopback ('.implode(', ', $exposure).'): they got new random passwords, and their sessions, API tokens and unused sign-in links were revoked.',
                null,
            ]]];
        }
        if (($credentials->data['passwords'] ?? null) !== null) {
            return [$credentials, []];
        }

        return $reissue
            ? [$credentials->withRotation($this->rotation($run, $project, $actorUid)), [['demo_passwords_reissued', 'The demo data was added by an earlier, interrupted attempt whose passwords were never shown: the demo accounts got new ones.', null]]]
            : [$credentials, [['demo_passwords_not_shown', 'The demo data exists already; its passwords were shown once when it was added.', 'Run `fsa demo --rotate-passwords` for new ones.']]];
    }

    /** `--rotate-passwords`, or a rotation `provide()` needs: phase `demo_rotate`. */
    public function rotation(OperationRun $run, ComposeProject $project, int $actorUid): DemoCredentials
    {
        return $this->inPhase($run, 'demo_rotate', fn (): DemoCredentials => $this->rotate($project, $actorUid));
    }

    /** @throws InstanceException when the one-off fails (exit 4, retryable) */
    public function seed(ComposeProject $project, int $actorUid): DemoCredentials
    {
        $exposure = DemoPasswords::exposure($this->directory->state(), $this->directory);
        $mode = DemoPasswords::mode($exposure);
        $result = $this->app->demoSeed($project, $mode, $actorUid);
        if (! $result->ok) {
            throw $this->failure('app:demo-seed', 'The demo data could not be added', $result);
        }
        $credentials = DemoCredentials::fromSeed($result, $exposure);
        if ($credentials->seeded() || ! self::recorded($this->directory->state())) {
            $this->record(self::SEEDED, $credentials->seeded() ? $mode : null);
        }

        return $credentials;
    }

    /** @throws InstanceException when the one-off fails (exit 4, retryable) */
    public function rotate(ComposeProject $project, int $actorUid): DemoCredentials
    {
        $exposure = DemoPasswords::exposure($this->directory->state(), $this->directory);
        $result = $this->app->rotateDemoPasswords($project, $actorUid);
        if (! $result->ok) {
            throw $this->failure('app:demo-passwords --rotate', 'The demo passwords could not be rotated', $result);
        }
        $credentials = DemoCredentials::fromRotation($result, $exposure);
        if ($credentials->changed()) {
            $this->record(self::ROTATED, DemoPasswords::RANDOM);
        }

        return $credentials;
    }

    /** @param  Closure(): DemoCredentials  $step */
    private function inPhase(OperationRun $run, string $name, Closure $step): DemoCredentials
    {
        $credentials = null;
        $run->phase($name, function () use ($step, &$credentials): array {
            $credentials = $step();

            return $credentials->journal();
        }, always: true);

        return $credentials instanceof DemoCredentials ? $credentials : throw new InstanceException("The {$name} phase returned no result.");
    }

    /** @param  string|null  $mode  null when the demo existed before fsa recorded it */
    private function record(string $event, ?string $mode): void
    {
        $this->directory->saveState($this->directory->state()->withHistoryEntry($event, ['passwords' => $mode]));
    }

    private function failure(string $command, string $what, OneOffResult $result): InstanceException
    {
        return new InstanceException(
            "{$what}: `{$command}` failed: ".$result->errorMessage(),
            ExitCode::HealthCheckFailed,
            in_array($result->errorCode(), ['one_off_failed', 'one_off_timeout'], true) ? $result->errorCode() : 'demo_failed',
            'Check that the instance runs (`fsa status`, `fsa logs app`), then run the command again.',
            true,
            ['code' => $result->errorCode(), 'output' => $result->output],
        );
    }
}
