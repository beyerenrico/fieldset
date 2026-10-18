<?php

declare(strict_types=1);

namespace Tests\Support\Docker;

use Symfony\Component\Process\Process;

/**
 * Runs an image script (docker/app/entrypoint.sh, docker/app/fsa) on the host with `php` and the role
 * commands replaced by stubs that only record how they were called. The image's `fsa` wrapper is on the
 * stub PATH as well, so an entrypoint test sees the artisan call it makes.
 */
final readonly class ScriptHarness
{
    private const array STUBS = ['php', 'frankenphp'];

    public string $bin;

    private string $log;

    public function __construct(private string $repository)
    {
        $directory = sys_get_temp_dir().'/fsa-script-harness-'.bin2hex(random_bytes(6));
        $this->bin = $directory.'/bin';
        $this->log = $directory.'/calls.log';
        mkdir($this->bin, 0700, true);
        touch($this->log);

        foreach (self::STUBS as $stub) {
            $this->writeExecutable($stub, "#!/bin/sh\nprintf '%s\\n' \"\${0##*/} \$*\" >> \"\$STUB_LOG\"\n");
        }

        $this->writeExecutable('fsa', (string) file_get_contents($repository.'/docker/app/fsa'));
    }

    /**
     * Runs the script with the given arguments and returns the recorded calls, one per line.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment  false removes an inherited variable
     * @return list<string>
     */
    public function run(string $script, array $arguments, array $environment = []): array
    {
        $this->process($script, $arguments, $environment)->mustRun();

        return $this->calls();
    }

    /**
     * Runs the script without requiring exit 0; the recorded calls are in `calls()`.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment  false removes an inherited variable
     */
    public function attempt(string $script, array $arguments, array $environment = []): Process
    {
        $process = $this->process($script, $arguments, $environment);
        $process->run();

        return $process;
    }

    /** @return list<string> the calls recorded by the last run, one per line */
    public function calls(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->log))));
    }

    public function cleanUp(): void
    {
        foreach ([...self::STUBS, 'fsa'] as $file) {
            @unlink($this->bin.'/'.$file);
        }

        @unlink($this->log);
        @rmdir($this->bin);
        @rmdir(dirname($this->bin));
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string|false>  $environment
     */
    private function process(string $script, array $arguments, array $environment): Process
    {
        file_put_contents($this->log, '');

        return new Process(['sh', $this->repository.'/'.$script, ...$arguments], $this->repository, [
            'PATH' => $this->bin.':/usr/bin:/bin',
            'STUB_LOG' => $this->log,
            'RUN_MIGRATIONS' => false,
            ...$environment,
        ]);
    }

    private function writeExecutable(string $name, string $contents): void
    {
        file_put_contents($this->bin.'/'.$name, $contents);
        chmod($this->bin.'/'.$name, 0755);
    }
}
