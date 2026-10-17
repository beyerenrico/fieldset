<?php

declare(strict_types=1);

namespace App\Domain\Instance;

/**
 * Facts about the host that the ops container cannot observe itself; the shim and install.sh pass them as
 * environment variables (BUILD_PROMPT_V2 §6.2, KTD2). U4 adds OS details, LAN candidates and the desktop flag.
 *
 * - FSA_TTY: 1/0, whether the caller has a terminal (the shim adds `-it` only then)
 * - FSA_HOST_UID / FSA_HOST_GID: the invoking host user (recorded in the journal)
 * - FSA_HOST_OS: e.g. linux, darwin
 * - FSA_INSTANCE_DIR: the instance directory, mounted at the same absolute path
 */
final readonly class HostFacts
{
    public function __construct(
        public ?bool $tty = null,
        public ?int $uid = null,
        public ?int $gid = null,
        public ?string $os = null,
        public ?string $instanceDir = null,
    ) {}

    /** @param  array<string, string>  $env  e.g. getenv() */
    public static function fromEnvironment(array $env): self
    {
        $int = static fn (string $key): ?int => isset($env[$key]) && ctype_digit($env[$key]) ? (int) $env[$key] : null;
        $string = static fn (string $key): ?string => isset($env[$key]) && $env[$key] !== '' ? $env[$key] : null;
        $tty = isset($env['FSA_TTY']) ? filter_var($env['FSA_TTY'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;

        return new self($tty, $int('FSA_HOST_UID'), $int('FSA_HOST_GID'), $string('FSA_HOST_OS'), $string('FSA_INSTANCE_DIR'));
    }

    /** The host UID of the caller: from the shim, else this process's UID (the shim runs us as the host user). */
    public function callerUid(): int
    {
        return $this->uid ?? (function_exists('posix_getuid') ? posix_getuid() : 0);
    }
}
