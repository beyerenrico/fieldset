<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use JsonException;

/**
 * An instance directory (BUILD_PROMPT_V2 §6.3) and its layout. The marker file says "this is an instance";
 * install.sh drops it before handing over, and every fsa command but `version` requires it (KTD1). All access
 * goes through SafeFiles: temp-file-plus-rename writes that never follow symlinks out of the directory.
 */
final readonly class InstanceDirectory
{
    public const string MARKER = '.fsa-instance';

    public const string MARKER_SCHEMA = '1.0';

    public const string STATE_FILE = 'state.json';

    public const string COMPOSE_FILE = 'compose.yaml';

    public const string ENV_DIR = 'env';

    public const string RUNTIME_DIR = 'runtime';

    public const string OPERATIONS_DIR = 'operations';

    public const string LOCKS_DIR = 'locks';

    public const string BACKUPS_DIR = 'backups';

    /**
     * fsa's private working files that may hold plaintext (0700, never to be synced off the host, M12 gate S2/S3): the
     * backup service's settings and the plain sets of encrypted backups being written (`staging/backup/`), restore
     * scratch directories (`staging/restore-<id>/`). Only finished files move into `backups/`.
     */
    public const string STAGING_DIR = 'staging';

    private function __construct(
        public string $root,
        private SafeFiles $files,
    ) {}

    /** Whether the directory carries the marker as a regular file (cheap check, no validation). */
    public static function isMarked(string $path): bool
    {
        $marker = rtrim($path, '/').'/'.self::MARKER;

        return is_file($marker) && ! is_link($marker);
    }

    /**
     * @param  array{0: int, 1: int}|null  $owner  UID and GID for everything written (the backup service, ADR 0084)
     *
     * @throws NotAnInstanceDirectory|IncompatibleSchema
     */
    public static function open(string $path, ?array $owner = null): self
    {
        if (! str_starts_with($path, '/') || ! is_dir($path) || ! self::isMarked($path)) {
            throw new NotAnInstanceDirectory($path);
        }
        $directory = new self($path, SafeFiles::at($path, $owner));
        $directory->checkMarker();

        return $directory;
    }

    /** Creates the directory (0700) and its marker; idempotent. install.sh does the same in shell. */
    public static function initialize(string $path): self
    {
        if (! str_starts_with($path, '/')) {
            throw new UnsafePath($path, 'it is not an absolute path');
        }
        if (is_link($path) || (! is_dir($path) && ! @mkdir($path, 0700, true))) {
            throw new UnsafePath($path, 'it cannot be created as a directory');
        }
        @chmod($path, 0700);
        $directory = new self($path, SafeFiles::at($path));
        if (! self::isMarked($path)) {
            $directory->write(self::MARKER, json_encode(['schema_version' => self::MARKER_SCHEMA, 'kind' => 'form-schema-app instance'], JSON_THROW_ON_ERROR)."\n");
        }

        return $directory;
    }

    /**
     * Absolute path of a relative path inside the directory (confined; see SafeFiles::resolve). It is built on the
     * path as given, not the resolved one: the shim mounts the directory at the same host path, and Docker
     * resolves bind mounts on the host.
     */
    public function path(string $relative = ''): string
    {
        if ($relative === '') {
            return $this->root;
        }
        $this->files->resolve($relative);

        return $this->root.'/'.$relative;
    }

    public function write(string $relative, string $contents, int $mode = 0600): void
    {
        $this->files->write($relative, $contents, $mode);
    }

    public function read(string $relative): ?string
    {
        return $this->files->read($relative);
    }

    public function exists(string $relative): bool
    {
        return $this->files->exists($relative);
    }

    public function ensureDirectory(string $relative, int $mode = 0700): string
    {
        $this->files->ensureDirectory($relative, $mode);

        return $this->path($relative);
    }

    public function files(): SafeFiles
    {
        return $this->files;
    }

    public function hasState(): bool
    {
        return $this->exists(self::STATE_FILE);
    }

    /** @throws InvalidState|IncompatibleSchema */
    public function state(): InstanceState
    {
        $json = $this->read(self::STATE_FILE);

        return $json === null ? throw new InvalidState('The instance has no state file yet.') : InstanceState::fromJson($json);
    }

    /** Writes the state, but never over a file from a newer major version (exit 7). */
    public function saveState(InstanceState $state): void
    {
        $existing = $this->read(self::STATE_FILE);
        if ($existing !== null) {
            $version = self::decode($existing, 'instance state')['schema_version'] ?? null;
            SchemaVersion::parse($version, 'instance state')->assertReadableBy(SchemaVersion::of(InstanceState::SCHEMA_VERSION), 'instance state');
        }
        $this->write(self::STATE_FILE, $state->toJson());
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidState
     */
    public static function decode(string $json, string $document): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw new InvalidState("The {$document} is not valid JSON.", $e);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidState("The {$document} is not a JSON object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** A JSON marker must not come from a newer major; an empty or non-JSON marker is accepted as written by hand. */
    private function checkMarker(): void
    {
        $contents = trim((string) $this->read(self::MARKER));
        $decoded = $contents === '' ? null : json_decode($contents, true);
        if (is_array($decoded) && array_key_exists('schema_version', $decoded)) {
            SchemaVersion::parse($decoded['schema_version'], 'instance marker')
                ->assertReadableBy(SchemaVersion::of(self::MARKER_SCHEMA), 'instance marker');
        }
    }
}
