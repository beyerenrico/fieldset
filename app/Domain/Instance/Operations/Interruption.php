<?php

declare(strict_types=1);

namespace App\Domain\Instance\Operations;

use Closure;

/**
 * Safe interruption of a foreground operation (ADR 0078). The shim runs the ops container with `--init`, so SIGTERM,
 * SIGINT and SIGHUP sent to `fsa` (Ctrl-C, a closed SSH session, an agent's tool timeout) reach this PHP process.
 * Without a handler PHP would die on the spot and leave a half-applied `config set` with its journal open.
 *
 * `guard()` installs handlers (the ops image's PHP has pcntl; without it the body simply runs unguarded) and
 * restores the previous ones afterwards. While the guard is armed, a signal throws OperationInterrupted into the
 * running step, so the caller's normal failure path runs: before any change an abort, during apply or verify the
 * journaled revert. While disarmed (the revert itself, the audit) a signal is only recorded, so the step finishes.
 * A signal received while disarmed is raised on the next `arm()`.
 *
 * SIGKILL cannot be handled: the journal then stays open and the next run of the same command resumes it (a revert
 * that had started is finished from the snapshot, see ConfigSetter).
 */
final class Interruption
{
    /** SIGHUP, SIGINT, SIGTERM (numbers, so the class loads without pcntl). */
    public const array SIGNALS = [1, 2, 15];

    private bool $armed = false;

    private ?int $pending = null;

    /** @var array<int, mixed> */
    private array $previous = [];

    private bool $wasAsync = false;

    private bool $installed = false;

    public static function supported(): bool
    {
        return function_exists('pcntl_signal') && function_exists('pcntl_async_signals') && function_exists('pcntl_signal_get_handler');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $body
     * @return T
     */
    public function guard(Closure $body): mixed
    {
        $this->install();

        try {
            return $body();
        } finally {
            $this->restore();
        }
    }

    /**
     * From now on a signal interrupts the running step. A signal that arrived while disarmed interrupts at once.
     *
     * @throws OperationInterrupted
     */
    public function arm(): void
    {
        if ($this->pending !== null) {
            $signal = $this->pending;
            $this->pending = null;

            throw new OperationInterrupted($signal);
        }
        $this->armed = true;
    }

    /** From now on a signal is only recorded: the running step (a revert, the audit) must finish. */
    public function disarm(): void
    {
        $this->armed = false;
    }

    /**
     * The signal handler (public for tests that simulate a signal without sending one).
     *
     * @throws OperationInterrupted while armed
     */
    public function handle(int $signal): void
    {
        if (! $this->armed) {
            $this->pending ??= $signal;

            return;
        }
        $this->armed = false;

        throw new OperationInterrupted($signal);
    }

    /** A signal received while disarmed and not raised yet. */
    public function pending(): ?int
    {
        return $this->pending;
    }

    private function install(): void
    {
        if (! self::supported() || $this->installed) {
            return;
        }
        $this->wasAsync = pcntl_async_signals(true);
        foreach (self::SIGNALS as $signal) {
            $this->previous[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, $this->handle(...));
        }
        $this->installed = true;
    }

    private function restore(): void
    {
        if (! $this->installed) {
            return;
        }
        foreach ($this->previous as $signal => $handler) {
            pcntl_signal($signal, is_callable($handler) || is_int($handler) ? $handler : SIG_DFL);
        }
        pcntl_async_signals($this->wasAsync);
        $this->previous = [];
        $this->armed = false;
        $this->installed = false;
    }
}
