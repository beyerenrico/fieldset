<?php

declare(strict_types=1);

namespace App\Domain\Instance\Console;

use App\Domain\Instance\Docker\ComposeProject;
use App\Domain\Instance\Docker\DockerGateway;
use App\Domain\Instance\Lifecycle\ComposeFile;
use App\Domain\Instance\Output\CommandResult;
use App\Domain\Instance\Output\InvalidInput;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `fsa logs [service…]` (§7, R45): the services' logs with timestamps. Without --json they go to stdout as text;
 * with --json under `data.logs`. Secret values of the instance's env files are replaced in both (R50).
 */
#[Description('Show the logs of the instance services')]
#[Signature('fsa:logs
        {service?* : Services to show (default: all)}
        {--since= : Only lines since a time or duration, e.g. 10m or 2026-10-18T10:00:00}
        {--tail=200 : Last lines per service (0 = all)}')]
final class LogsCommand extends FsaCommand
{
    protected function perform(FsaContext $context): CommandResult
    {
        $directory = $context->directory();
        $state = $directory->state();
        $known = ComposeFile::services($directory);
        if ($known === []) {
            throw new InvalidInput('The instance has no services yet.', 'not_installed', 'Run `fsa install` first.');
        }
        $services = $this->services($known);
        [$since, $tail] = [$this->since(), $this->tail()];
        $logs = $this->laravel->make(DockerGateway::class)->composeLogs(ComposeProject::forInstance($state, $directory), $services, $since, $tail);
        $data = ['services' => $services === [] ? $known : $services, 'since' => $since, 'tail' => $tail];
        if ($context->json) {
            return CommandResult::ok('logs', [...$data, 'logs' => $logs]);
        }
        $this->output->write($context->redactor->redactString($logs), false, OutputInterface::OUTPUT_RAW);

        return CommandResult::ok('logs', [...$data, 'lines' => substr_count($logs, "\n")]);
    }

    /**
     * @param  list<string>  $known
     * @return list<string>
     */
    private function services(array $known): array
    {
        $given = $this->argument('service');
        $services = is_array($given) ? array_values(array_map(strval(...), $given)) : [];
        $unknown = array_diff($services, $known);
        if ($unknown !== []) {
            throw new InvalidInput('Unknown service: '.implode(', ', $unknown).'.', 'unknown_service', 'Services of this instance: '.implode(', ', $known).'.');
        }

        return $services;
    }

    private function since(): ?string
    {
        $since = $this->option('since');
        if (! is_string($since) || $since === '') {
            return null;
        }
        if (preg_match('/^[0-9][0-9A-Za-z:.+-]{0,39}$/D', $since) !== 1) {
            throw new InvalidInput('--since takes a duration such as 10m or a timestamp such as 2026-10-18T10:00:00.', 'invalid_flag');
        }

        return $since;
    }

    private function tail(): ?int
    {
        $tail = $this->option('tail');
        if (! is_string($tail) || ! ctype_digit($tail)) {
            throw new InvalidInput('--tail takes a number of lines (0 = all).', 'invalid_flag');
        }

        return (int) $tail === 0 ? null : (int) $tail;
    }
}
