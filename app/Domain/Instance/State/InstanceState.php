<?php

declare(strict_types=1);

namespace App\Domain\Instance\State;

use App\Domain\Instance\InstanceException;
use App\Domain\Instance\Output\ExitCode;
use Carbon\CarbonImmutable;

/**
 * The instance state (KTD6): the single authority from which compose file, env files and runtime directory are
 * rendered. A versioned JSON document; keys this version does not know (a newer minor) are kept verbatim.
 * It never holds secrets: those live only in the per-service env files (KTD7, EnvFiles).
 */
final readonly class InstanceState
{
    public const string SCHEMA_VERSION = '1.0';

    public const array MODES = ['local', 'domain', 'proxy'];

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private const string SLUG = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';

    private const string DIGEST_REF = '/^[^\s@]+@sha256:[0-9a-f]{64}$/';

    private const string RECIPIENT = '/^age1[0-9a-z]{20,}$/';

    private const string SECRET_KEY = '/(password|passphrase|secret|token|app_key|private_key)/i';

    /** @param  array<string, mixed>  $document */
    private function __construct(private array $document) {}

    public static function create(string $uuid, string $path, int $hostUid, int $hostGid, int $containerUid, string $mode = 'local'): self
    {
        $slug = self::slugFor($path);

        return self::fromArray([
            'schema_version' => self::SCHEMA_VERSION,
            'uuid' => $uuid,
            'slug' => $slug,
            'path' => $path,
            'project' => self::projectFor($slug, $uuid),
            'owner' => ['host_uid' => $hostUid, 'host_gid' => $hostGid, 'container_uid' => $containerUid],
            'mode' => $mode,
            'url' => null,
            'version' => null,
            'digests' => [],
            'previous' => null,
            'recipients' => [],
            'settings' => [],
            'history' => [],
        ]);
    }

    /** @throws InvalidState|IncompatibleSchema */
    public static function fromJson(string $json): self
    {
        return self::fromArray(InstanceDirectory::decode($json, 'instance state'));
    }

    /**
     * @param  array<string, mixed>  $document
     *
     * @throws InvalidState|IncompatibleSchema
     */
    public static function fromArray(array $document): self
    {
        $version = SchemaVersion::parse($document['schema_version'] ?? null, 'instance state');
        $version->assertReadableBy(SchemaVersion::of(self::SCHEMA_VERSION), 'instance state');
        StateValidator::validate($document, [
            'uuid' => self::UUID, 'slug' => self::SLUG, 'digest' => self::DIGEST_REF, 'recipient' => self::RECIPIENT,
        ]);

        return new self($document);
    }

    /** "My Instance.v2" → "my-instance-v2"; Compose project names must be lowercase (KTD6). */
    public static function slugFor(string $path): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($path))), '-');
        $slug = rtrim(substr($slug, 0, 40), '-');

        return $slug === '' ? 'instance' : $slug;
    }

    /** `fsa-<slug>-<4 hex>`: the hex comes from the UUID, so two instances with the same directory name differ. */
    public static function projectFor(string $slug, string $uuid): string
    {
        return 'fsa-'.$slug.'-'.substr(hash('sha256', $uuid), 0, 4);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->document;
    }

    public function toJson(): string
    {
        return json_encode($this->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    public function schemaVersion(): string
    {
        return (string) SchemaVersion::parse($this->document['schema_version'], 'instance state');
    }

    public function uuid(): string
    {
        return $this->string('uuid');
    }

    public function slug(): string
    {
        return $this->string('slug');
    }

    public function path(): string
    {
        return $this->string('path');
    }

    public function project(): string
    {
        return $this->string('project');
    }

    public function mode(): string
    {
        return $this->string('mode');
    }

    public function url(): ?string
    {
        return is_string($this->document['url'] ?? null) ? $this->document['url'] : null;
    }

    public function version(): ?string
    {
        return is_string($this->document['version'] ?? null) ? $this->document['version'] : null;
    }

    public function hostUid(): int
    {
        return $this->owner('host_uid');
    }

    public function hostGid(): int
    {
        return $this->owner('host_gid');
    }

    /** UID that owns files inside containers: the host UID on rootful Docker, 0 on rootless/Desktop (KTD2). */
    public function containerUid(): int
    {
        return $this->owner('container_uid');
    }

    /** @return array<string, string> image role => `repository@sha256:…` */
    public function digests(): array
    {
        return $this->document['digests'];
    }

    /** @return array{version: string, digests: array<string, string>, compose: string}|null */
    public function previous(): ?array
    {
        return $this->document['previous'] ?? null;
    }

    /** @return list<string> age X25519 recipients (public keys only, KTD17) */
    public function recipients(): array
    {
        return $this->document['recipients'] ?? [];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $settings = $this->document['settings'] ?? [];

        return is_array($settings) && array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return $this->document['history'] ?? [];
    }

    public function withMode(string $mode): self
    {
        return $this->with('mode', $mode);
    }

    public function withUrl(?string $url): self
    {
        return $this->with('url', $url);
    }

    /** @param  array<string, string>  $digests */
    public function withRelease(string $version, array $digests): self
    {
        return $this->with('version', $version)->with('digests', $digests);
    }

    /**
     * The release to return to on rollback: its digests and the compose file it ran with (KTD19).
     *
     * @param  array<string, string>  $digests
     */
    public function withPreviousRelease(string $version, array $digests, string $composeSnapshot): self
    {
        return $this->with('previous', ['version' => $version, 'digests' => $digests, 'compose' => $composeSnapshot]);
    }

    public function withoutPreviousRelease(): self
    {
        return $this->with('previous', null);
    }

    /** @param  list<string>  $recipients */
    public function withRecipients(array $recipients): self
    {
        return $this->with('recipients', array_values(array_unique($recipients)));
    }

    /** A non-secret setting (port, domain, locale …). Secret-looking keys are refused: they belong in env files. */
    public function withSetting(string $key, string|int|bool|null $value): self
    {
        if (preg_match(self::SECRET_KEY, $key) === 1) {
            throw new InstanceException("The setting [{$key}] looks like a secret; secrets live only in the service env files.", ExitCode::InvalidInput, 'secret_in_state');
        }
        $settings = is_array($this->document['settings'] ?? null) ? $this->document['settings'] : [];

        return $this->with('settings', [...$settings, $key => $value]);
    }

    /** @param  array<string, string|int|bool|null>  $data */
    public function withHistoryEntry(string $event, array $data = []): self
    {
        $entry = ['at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(), 'event' => $event, ...$data];

        return $this->with('history', [...$this->history(), $entry]);
    }

    private function with(string $key, mixed $value): self
    {
        $document = $this->document;
        $document[$key] = $value;
        $document['schema_version'] = (string) SchemaVersion::parse($document['schema_version'], 'instance state')
            ->max(SchemaVersion::of(self::SCHEMA_VERSION));

        return self::fromArray($document);
    }

    private function string(string $key): string
    {
        $value = $this->document[$key];

        return is_string($value) ? $value : '';
    }

    private function owner(string $key): int
    {
        $owner = $this->document['owner'];

        return is_array($owner) && is_int($owner[$key] ?? null) ? $owner[$key] : 0;
    }
}
