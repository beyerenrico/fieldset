<?php

declare(strict_types=1);

namespace App\Domain\Instance\Runtime;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Reads runtime.json from the directory in config('instance.runtime_path') (FIELDSET_RUNTIME_PATH, KTD11).
 *
 * - Not configured: no state at all, and every caller behaves as before (checkout installs).
 * - Cached in this process, keyed on path, inode, size and mtime: `fieldset` replaces the file by rename, so a new
 *   inode always means new content, even within the same second. Never cached in Valkey.
 * - An unusable file (missing, unreadable, too large, invalid JSON, broken grammar) keeps the last good value of
 *   this process; without one the state falls back to APP_URL with the gate raised (fail closed). Each new problem
 *   is logged once as a warning, and the platform health page shows it. Reading never throws.
 *
 * Registered as a singleton: a Horizon worker keeps its last good value across jobs. FrankenPHP serves web
 * requests in classic mode, where each request starts with fresh PHP state, so there "this process" is the request.
 */
final class RuntimeStateReader
{
    public const string FILE = 'runtime.json';

    public const int MAX_BYTES = 65_536;

    private const int REGULAR_FILE = 0100000;

    private const int FILE_TYPE_MASK = 0170000;

    /** @var array{0: string, 1: int, 2: int, 3: int}|null path, inode, size and mtime of the cached reading */
    private ?array $signature = null;

    private ?RuntimeReading $cached = null;

    private ?RuntimeState $lastGood = null;

    private ?string $loggedProblem = null;

    public function __construct(private readonly Repository $config) {}

    public function current(): ?RuntimeState
    {
        return $this->reading()->state;
    }

    public function reading(): RuntimeReading
    {
        $directory = $this->config->get('instance.runtime_path');
        if (! is_string($directory) || trim($directory) === '') {
            return RuntimeReading::notConfigured();
        }
        $path = rtrim($directory, '/').'/'.self::FILE;
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false) {
            return $this->unusable($path, 'runtime.json is missing or not accessible');
        }
        $signature = [$path, (int) $stat['ino'], (int) $stat['size'], (int) $stat['mtime']];
        if ($signature === $this->signature && $this->cached instanceof RuntimeReading) {
            if ($this->cached->source === RuntimeSource::File) {
                $this->loggedProblem = null;
            }

            return $this->cached;
        }
        $this->signature = $signature;

        try {
            $state = RuntimeDocument::parse($this->decode($path, $stat));
        } catch (InvalidRuntimeState $e) {
            return $this->cached = $this->unusable($path, $e->getMessage());
        } catch (Throwable $e) {
            return $this->cached = $this->unusable($path, 'runtime.json could not be read ('.$e::class.')');
        }
        $this->lastGood = $state;
        $this->loggedProblem = null;

        return $this->cached = new RuntimeReading($state, RuntimeSource::File);
    }

    /** @param  array<int|string, int>  $stat */
    private function decode(string $path, array $stat): mixed
    {
        if (($stat['mode'] & self::FILE_TYPE_MASK) !== self::REGULAR_FILE) {
            throw new InvalidRuntimeState('runtime.json is not a regular file');
        }
        if ($stat['size'] > self::MAX_BYTES) {
            throw new InvalidRuntimeState('runtime.json is larger than 64 KiB');
        }
        $json = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($json === false) {
            throw new InvalidRuntimeState('runtime.json is not readable');
        }

        try {
            return json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidRuntimeState('runtime.json is not valid JSON');
        }
    }

    private function unusable(string $path, string $problem): RuntimeReading
    {
        $reading = $this->lastGood instanceof RuntimeState
            ? new RuntimeReading($this->lastGood, RuntimeSource::LastGood, $problem)
            : new RuntimeReading(RuntimeState::fallback((string) $this->config->get('app.url')), RuntimeSource::Fallback, $problem);

        if ($this->loggedProblem !== $problem) {
            $this->loggedProblem = $problem;
            $this->warn($path, $reading);
        }

        return $reading;
    }

    private function warn(string $path, RuntimeReading $reading): void
    {
        try {
            Log::warning($reading->source === RuntimeSource::LastGood
                ? 'Instance runtime file unusable; keeping the last good value.'
                : 'Instance runtime file unusable; using APP_URL with the operation gate raised.', [
                    'path' => $path,
                    'problem' => $reading->problem,
                ]);
        } catch (Throwable) {
            // A broken log channel must not turn every request into a 500.
        }
    }
}
