<?php

declare(strict_types=1);

namespace Tests\Support\Instance;

use App\Domain\Instance\Docker\ContainerSpec;
use App\Domain\Instance\Docker\DockerRuntime;
use App\Domain\Instance\Docker\OwnerSource;
use App\Domain\Instance\Docker\ProcessOutcome;
use App\Domain\Instance\HostFacts;
use App\Domain\Instance\State\InstanceDirectory;
use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * An instance installed by a 2.1.0 launcher (no measured owner in state.json) with its stack running, for the 2.1.1 U4
 * tests: doctor's owner re-check, first-use recording and `fieldset repair-ownership`. The doctor sees the instance files
 * as owned by the UID `seeFilesAs()` names (the test host cannot give files to other users). The repair's root container
 * (`find … -exec chown -h`) runs here as the same `find` with `-print` instead of the chown, its owner test widened to every
 * file of this user: the entries it prints are what the real container would give to the new owner, and the doctor
 * then sees them as that owner.
 */
final class OwnerFixture
{
    /** The files the doctor's permissions check looks at (PermissionsCheck). */
    public const array CHECKED = ['', InstanceDirectory::STATE_FILE, InstanceDirectory::COMPOSE_FILE, 'fieldset'];

    public InstanceCommandFixture $instance;

    /** @var list<string> absolute paths the emulated root container printed (would have given to the new owner) */
    public array $chowned = [];

    /** The root container's outcome instead of the emulation (null: emulate). */
    public ?ProcessOutcome $chownOutcome = null;

    public function __construct(public readonly int $containerUid, DockerRuntime $runtime = DockerRuntime::Rootful)
    {
        $this->instance = new InstanceCommandFixture;
        $this->instance->facts(['runtime' => $runtime, 'containerUid' => $containerUid]); // a 2.1.0 launcher: no owner source
        $this->seeFilesAs($containerUid);
        [$code, $json] = $this->instance->run('fieldset:install', InstanceCommandFixture::installFlags());
        if ($code !== 0) {
            throw new RuntimeException('The fixture install failed: '.json_encode($json));
        }
        $this->instance->withRunningStack();
        $this->seeFilesAs($containerUid);
        $previous = $this->instance->docker->runResponder;
        $this->instance->docker->respondToRun(fn (ContainerSpec $spec): ?ProcessOutcome => $spec->entrypoint === 'find'
            ? $this->chown($spec)
            : ($previous instanceof Closure ? $previous($spec) : null));
    }

    public function root(): string
    {
        return $this->instance->directory->root;
    }

    /**
     * The facts of the 2.1.1 shim after its measurement (or its fallback to rules).
     *
     * @param  array<string, mixed>  $overrides  more HostFacts arguments (WSL, socket group)
     */
    public function measure(int $owner, DockerRuntime $runtime, OwnerSource $source = OwnerSource::Measured, array $overrides = []): HostFacts
    {
        return $this->instance->facts(['runtime' => $runtime, 'containerUid' => $owner, 'ownerSource' => $source, ...$overrides]);
    }

    /** The doctor sees the checked instance files as owned by this UID inside containers, with their real modes. */
    public function seeFilesAs(int $uid): void
    {
        foreach (self::CHECKED as $relative) {
            $path = $relative === '' ? $this->root() : $this->root().'/'.$relative;
            if (file_exists($path)) {
                $this->instance->probes->stats[$path] = ['uid' => $uid, 'mode' => fileperms($path) & 07777];
            }
        }
    }

    /** @return array<string, array{mode: int, uid: int}> every entry below the instance directory (lstat), by relative path */
    public function entries(): array
    {
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root(), \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $path => $info) {
            if (str_contains((string) $path, '/.fieldset-nonce-') || str_contains((string) $path, '/locks/')) {
                continue; // per-run files and the lock come and go
            }
            $stat = lstat((string) $path);
            $entries[substr((string) $path, strlen($this->root()))] = ['mode' => $stat['mode'] & 07777, 'uid' => $stat['uid']];
        }
        ksort($entries);

        return $entries;
    }

    public function stateJson(): string
    {
        return (string) file_get_contents($this->root().'/'.InstanceDirectory::STATE_FILE);
    }

    /** @return list<ContainerSpec> the repair's root containers */
    public function chownSpecs(): array
    {
        return $this->instance->runSpecs('find');
    }

    /** The same find here: `-print` instead of `-exec chown -h … {} +`, and every file of this user counts as another owner's. */
    private function chown(ContainerSpec $spec): ProcessOutcome
    {
        if ($this->chownOutcome instanceof ProcessOutcome) {
            return $this->chownOutcome;
        }
        $arguments = [];
        $target = null;
        for ($i = 0, $n = count($spec->command); $i < $n; $i++) {
            $argument = $spec->command[$i];
            if ($argument === '-exec') {
                break;
            }
            if ($argument === '-uid' && $spec->command[$i - 1] === '!') {
                $target = (int) $spec->command[++$i];
                $arguments[] = '-uid';
                $arguments[] = (string) (posix_getuid() + 1);

                continue;
            }
            $arguments[] = $argument;
        }
        $process = new Process(['find', ...$arguments]);
        $process->run();
        $printed = array_values(array_filter(explode("\n", $process->getOutput())));
        array_push($this->chowned, ...$printed);
        foreach ($printed as $path) {
            if (in_array($path, array_map(fn (string $relative): string => $relative === '' ? $this->root() : $this->root().'/'.$relative, self::CHECKED), true)) {
                $this->instance->probes->stats[$path] = ['uid' => (int) $target, 'mode' => fileperms($path) & 07777];
            }
        }

        return new ProcessOutcome($process->getExitCode() ?? 1, $process->getOutput(), $process->getErrorOutput());
    }
}
