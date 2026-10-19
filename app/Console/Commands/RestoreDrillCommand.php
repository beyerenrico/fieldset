<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Support\JsonResult;
use App\Domain\Platform\Instance\Restore\DrillReport;
use App\Domain\Platform\Instance\Restore\RestoreConnections;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The checks of `fsa restore --drill` (R41, plan U17, ADR 0085) in a socket-less one-off whose DB_DATABASE is the drill's
 * scratch database (`restore_drill_…`; anything else is refused), with fsa's null mailer and sync queue (MAIL_MAILER,
 * QUEUE_CONNECTION by name): counts, audit heads, files, one render into a temporary directory, decrypt sample,
 * migrations (DrillReport). stdin carries the storage archive's member list (one path per line); `--no-storage` when
 * the backup has none. The JSON document always carries the report; it fails when a check fails.
 */
#[Description('Check a backup restored into a drill database: counts, audit heads, files, a render, APP keys, migrations')]
#[Signature('instance:restore-drill
        {--no-storage : The backup has no storage archive (no member list on stdin)}
        {--json : Print one JSON document on stdout}')]
final class RestoreDrillCommand extends Command
{
    /** Container key of a closure returning stdin (tests); the one-off reads STDIN. */
    public const string STDIN = 'instance.restore-drill.stdin';

    /** The drill's databases (fsa names them `restore_drill_<time>`); never the live one. */
    public const string DATABASE = '/(?:^|_)restore_drill_[a-z0-9_]{1,40}$/D';

    private const string NAME = 'instance:restore-drill';

    public function handle(DrillReport $drill): int
    {
        $database = RestoreConnections::current();
        if (preg_match(self::DATABASE, $database) !== 1) {
            return $this->refuse('not_a_drill_database', "The drill runs only against a restore_drill_ database, not {$database}.");
        }

        try {
            $report = $drill->run($this->option('no-storage') === true ? null : $this->members());
        } catch (QueryException $e) {
            return $this->refuse('database_failed', 'The drill database could not be read (SQLSTATE '.($e->errorInfo[0] ?? '?').'): '.($e->errorInfo[2] ?? 'no message'));
        }
        $failed = array_values(array_filter($report['checks'], static fn (array $check): bool => $check['status'] === 'fail'));
        if ($this->option('json') !== true) {
            foreach ($report['checks'] as $check) {
                $this->line(strtoupper($check['status']).'  '.$check['message']);
            }

            return $failed === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($failed === []) {
            return JsonResult::ok(self::NAME, $report)->emit($this);
        }
        $document = JsonResult::failed(self::NAME, 'drill_failed', 'The drill failed: '.implode(' ', array_column($failed, 'message')))->toArray();
        $this->getOutput()->writeln(json_encode([...$document, 'data' => $report], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::FAILURE;
    }

    /** @return list<string> */
    private function members(): array
    {
        $reader = $this->laravel->bound(self::STDIN)
            ? $this->laravel->make(self::STDIN)
            : static fn (): string => function_exists('posix_isatty') && posix_isatty(STDIN) ? '' : (string) stream_get_contents(STDIN);

        return array_values(array_filter(array_map(static fn (string $line): string => rtrim($line, "\r"), explode("\n", (string) $reader())), static fn (string $line): bool => $line !== ''));
    }

    private function refuse(string $code, string $message): int
    {
        if ($this->option('json') === true) {
            return JsonResult::failed(self::NAME, $code, $message)->emit($this);
        }
        $this->components->error($message);

        return self::FAILURE;
    }
}
