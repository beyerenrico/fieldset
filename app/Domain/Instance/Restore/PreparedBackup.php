<?php

declare(strict_types=1);

namespace App\Domain\Instance\Restore;

/**
 * What `fsa restore-prepare` found out about a backup (plan U17, ADR 0085): the format, where its plain set now lies,
 * whether the checksums and the HMAC hold, the storage archive's members, the dump's table of contents and its migration
 * set. It carries no secret (secrets.env and a 1.0 `env` stay files in the set); it crosses from the socket-less one-off
 * to fsa as JSON, and fsa makes every decision on it (RestoreChecks).
 */
final readonly class PreparedBackup
{
    /**
     * @param  list<string>  $problems  checksum problems (any: the backup is damaged)
     * @param  list<string>  $strays  dotfiles next to the set, ignored
     * @param  array<string, mixed>  $manifest  a summary: format, kind, name, created_at, version, epoch, app_key_fingerprints …
     * @param  array<string, mixed>  $settings  settings.json (portable settings, no secret)
     * @param  array{files: int, directories: int, bytes: int, archive: string, listing: string}|null  $storage
     * @param  array{entries: int, types: array<string, int>, rejected: list<string>, list?: string}  $dump  `list`: the table of contents a restricted restore uses
     * @param  array{relation: string, count: int, last: string|null, unknown: list<string>, pending: int}  $migrations
     */
    public function __construct(
        public string $format,
        public bool $encrypted,
        public string $source,
        public string $set,
        public string $name,
        public string $hmac,
        public ?string $keyFingerprint,
        public array $problems,
        public array $strays,
        public array $manifest,
        public array $settings,
        public ?array $storage,
        public array $dump,
        public array $migrations,
        public bool $secrets,
        public bool $legacyEnv,
    ) {}

    /** The HMAC verified, with the key the manifest names first (the APP_KEY the backup was made with). */
    public function authenticated(): bool
    {
        $listed = $this->fingerprints();

        return $this->hmac === 'verified' && ($listed === [] || $this->keyFingerprint === $listed[0]);
    }

    public function isLegacy(): bool
    {
        return $this->format === BackupSet::LEGACY;
    }

    public function version(): ?string
    {
        return is_string($this->manifest['version'] ?? null) ? $this->manifest['version'] : null;
    }

    /** @return list<string> the fingerprints of every APP key the backup's data may be encrypted under */
    public function fingerprints(): array
    {
        return array_values(array_filter((array) ($this->manifest['app_key_fingerprints'] ?? []), is_string(...)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => $this->format, 'encrypted' => $this->encrypted, 'source' => $this->source, 'set' => $this->set, 'name' => $this->name,
            'hmac' => $this->hmac, 'key_fingerprint' => $this->keyFingerprint, 'problems' => $this->problems, 'strays' => $this->strays,
            'manifest' => $this->manifest, 'settings' => $this->settings, 'storage' => $this->storage, 'dump' => $this->dump,
            'migrations' => $this->migrations, 'secrets' => $this->secrets, 'legacy_env' => $this->legacyEnv,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $array = static fn (string $key): array => is_array($data[$key] ?? null) ? $data[$key] : [];
        $strings = static fn (string $key): array => array_values(array_filter($array($key), is_string(...)));
        $storage = is_array($data['storage'] ?? null) ? $data['storage'] : null;
        $dump = $array('dump');
        $migrations = $array('migrations');

        return new self(
            (string) ($data['format'] ?? ''), ($data['encrypted'] ?? false) === true, (string) ($data['source'] ?? ''), (string) ($data['set'] ?? ''),
            (string) ($data['name'] ?? ''), (string) ($data['hmac'] ?? 'absent'), is_string($data['key_fingerprint'] ?? null) ? $data['key_fingerprint'] : null,
            $strings('problems'), $strings('strays'), $array('manifest'), $array('settings'),
            $storage === null ? null : ['files' => (int) ($storage['files'] ?? 0), 'directories' => (int) ($storage['directories'] ?? 0), 'bytes' => (int) ($storage['bytes'] ?? 0),
                'archive' => (string) ($storage['archive'] ?? ''), 'listing' => (string) ($storage['listing'] ?? '')],
            ['entries' => (int) ($dump['entries'] ?? 0), 'types' => is_array($dump['types'] ?? null) ? array_map(intval(...), $dump['types']) : [],
                'rejected' => array_values(array_filter((array) ($dump['rejected'] ?? []), is_string(...))), ...(is_string($dump['list'] ?? null) ? ['list' => $dump['list']] : [])],
            ['relation' => (string) ($migrations['relation'] ?? MigrationSet::NONE), 'count' => (int) ($migrations['count'] ?? 0),
                'last' => is_string($migrations['last'] ?? null) ? $migrations['last'] : null,
                'unknown' => array_values(array_filter((array) ($migrations['unknown'] ?? []), is_string(...))), 'pending' => (int) ($migrations['pending'] ?? 0)],
            ($data['secrets'] ?? false) === true, ($data['legacy_env'] ?? false) === true,
        );
    }
}
