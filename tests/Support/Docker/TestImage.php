<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Builds the app image once per test process as `fsa-test-u1:<tag>` and runs throwaway containers of it
 * (install journeys, KTD23). Only resources named `fsa-test-*` are created; containers are removed.
 * FSA_TEST_PLATFORM (e.g. linux/amd64) builds and runs another architecture under emulation.
 */
final class TestImage
{
    public const string REPOSITORY = 'fsa-test-u1';

    public const string VERSION = '2.0.0-test.u1';

    /** @var array<string, string> platform => image reference */
    private static array $built = [];

    public static function dockerUnavailableReason(): ?string
    {
        $process = new Process(['docker', 'info', '--format', '{{.ServerVersion}}']);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return 'Docker is not available: '.$exception->getMessage();
        }

        return $process->isSuccessful() ? null : 'Docker is not available: '.trim($process->getErrorOutput());
    }

    public static function platform(): ?string
    {
        $platform = getenv('FSA_TEST_PLATFORM');

        return is_string($platform) && $platform !== '' ? $platform : null;
    }

    /** Builds the image (BuildKit cache makes repeat builds cheap) and returns its reference. */
    public static function reference(): string
    {
        $platform = self::platform();
        $key = $platform ?? 'native';

        return self::$built[$key] ??= self::build($platform);
    }

    public static function revision(): string
    {
        return trim(self::mustRun(['git', 'rev-parse', 'HEAD'], 30)->getOutput());
    }

    /**
     * Runs a throwaway container of the image and returns the process (run, not necessarily successful).
     *
     * @param  list<string>  $options  `docker run` options
     * @param  list<string>  $command
     */
    public static function run(array $options, array $command, int $timeout = 300): Process
    {
        $platform = self::platform();
        $process = new Process([
            'docker', 'run', '--rm', ...($platform === null ? [] : ['--platform', $platform]),
            ...$options, self::reference(), ...$command,
        ], self::repository());
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    /**
     * @param  list<string>  $command
     */
    public static function mustRun(array $command, int $timeout = 300): Process
    {
        $process = new Process($command, self::repository());
        $process->setTimeout($timeout);
        $process->mustRun();

        return $process;
    }

    public static function repository(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function logFile(string $name): string
    {
        $directory = self::repository().'/storage/agent-logs';
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        return $directory.'/'.$name;
    }

    private static function build(?string $platform): string
    {
        $arch = $platform === null ? 'native' : str_replace('linux/', '', $platform);
        $reference = self::REPOSITORY.':'.($platform === null ? 'latest' : $arch);
        $log = self::logFile("fsa-test-u1-build-{$arch}.log");
        file_put_contents($log, '');

        $process = new Process([
            'docker', 'build', '--progress=plain', '-f', 'docker/app/Dockerfile', '-t', $reference,
            ...($platform === null ? [] : ['--platform', $platform, '--load']),
            '--build-arg', 'APP_VERSION='.self::VERSION, '--build-arg', 'APP_REVISION='.self::revision(), '.',
        ], self::repository());
        $process->setTimeout(3600);
        $process->run(function (string $type, string $output) use ($log): void {
            file_put_contents($log, $output, FILE_APPEND);
        });

        if (! $process->isSuccessful()) {
            $tail = implode("\n", array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -40));
            throw new RuntimeException("Building {$reference} failed (full log: {$log}):\n{$tail}");
        }

        return $reference;
    }
}
