<?php

declare(strict_types=1);

namespace App\Domain\Instance\Upgrade;

use App\Domain\Instance\Compose\ComposeRenderer;
use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Lifecycle\OneOffResult;
use App\Domain\Instance\Output\ExitCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Sleep;

/**
 * The wait for requests that passed before the gate rose (KTD19 step 3, R64, plan U18, ADR 0086, review U18 A1). The gate
 * turns new requests away, but one already inside (an import, a publish, a PDF) can still commit; the safety backup, the
 * audit seal and a rollback's security export must come after it. Once queue, scheduler and backup stopped, every
 * client session of the database is such a request (the gated app holds none between requests, FrankenPHP classic mode)
 * or another client (psql, a script): the quiesce waits until none is left, at most WAIT_SECONDS, read by a socket-less
 * one-off (`instance:upgrade-db foreign`, names and states only). Sessions that stay stop the operation: exit 9, the
 * caller goes back.
 */
final readonly class InFlightRequests
{
    public const int WAIT_SECONDS = 120;

    public const int POLL_SECONDS = 2;

    public function __construct(private DockerGateway $docker) {}

    /**
     * @return array{waited: int}
     *
     * @throws InstanceException exit 9 `requests_in_flight` or `in_flight_check_failed`
     */
    public function wait(ComposeProject $project, string $operation): array
    {
        $started = CarbonImmutable::now();
        while (true) {
            $sessions = $this->sessions($project, $operation);
            $waited = (int) $started->diffInSeconds(CarbonImmutable::now(), true);
            if ($sessions === []) {
                return ['waited' => $waited];
            }
            if ($waited >= self::WAIT_SECONDS) {
                throw new InstanceException(
                    sprintf('%d database session(s) stayed open %d s after the gate rose: %s.', count($sessions), $waited, implode(', ', array_map($this->describe(...), $sessions))),
                    ExitCode::AbortedUnchanged, 'requests_in_flight',
                    "A long request or another client (psql, a script) still uses the database. Let it finish or close it, then run `fieldset {$operation}` again; nothing was changed.",
                    true, ['sessions' => $sessions],
                );
            }
            Sleep::for(self::POLL_SECONDS)->seconds();
        }
    }

    /** @return list<array<string, mixed>> */
    private function sessions(ComposeProject $project, string $operation): array
    {
        $outcome = OneOffResult::fromOutcome($this->docker->composeRun($project, ComposeRenderer::ONE_OFF_SERVICE, ['php', 'artisan', 'instance:upgrade-db', 'foreign', '--json'], timeout: 120.0));
        if (! $outcome->ok) {
            throw new InstanceException('Checking for requests still in flight failed: '.$outcome->errorMessage(), ExitCode::AbortedUnchanged, 'in_flight_check_failed',
                "Read `fieldset logs postgres`, then run `fieldset {$operation}` again; nothing was changed.", true);
        }
        $sessions = is_array($outcome->data['sessions'] ?? null) ? $outcome->data['sessions'] : [];

        return array_values(array_filter($sessions, is_array(...)));
    }

    /** @param  array<string, mixed>  $session */
    private function describe(array $session): string
    {
        $name = is_string($session['application_name'] ?? null) && $session['application_name'] !== '' ? $session['application_name'] : 'no application name';
        $state = is_string($session['state'] ?? null) ? $session['state'] : 'unknown state';

        return 'pid '.(is_int($session['pid'] ?? null) ? $session['pid'] : '?')." ({$name}, {$state})";
    }
}
