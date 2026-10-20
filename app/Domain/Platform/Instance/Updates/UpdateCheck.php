<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Updates;

use App\Domain\Instance\Runtime\InstanceFacts;
use App\Domain\Instance\Runtime\RuntimeSource;
use App\Domain\Instance\Runtime\RuntimeStateReader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * The opt-in update check (E15, R48, plan U20, ADR 0061, ADR 0089). Off by default: only when runtime.json names a
 * release manifest URL (`fsa config set update-check on`) does the daily scheduled run send one plain GET for it, with
 * no instance identifier, and nothing else. Off, it sends nothing at all. It reads `version` and `notes_url` of the
 * manifest as untrusted display data, never pulls or upgrades anything (`fsa upgrade` verifies the signature itself),
 * and keeps the outcome in the cache for the `/platform` instance page; a cache flush only empties the page until the
 * next run.
 *
 * The plan placed this class under App\Domain\Instance\Updates; that namespace counts as the ops side, which web and
 * scheduler code may not reach (tests/Arch/InstanceBoundaryTest.php), so it lives with the other `/platform` reads.
 */
final readonly class UpdateCheck
{
    public const string CACHE_KEY = 'platform:update_check:last';

    /** A manifest is a few KiB; anything larger is not one. */
    private const int MAX_BYTES = 1_048_576;

    public function __construct(private RuntimeStateReader $runtime, private Cache $cache) {}

    /** The manifest URL to read, or null while the check is off (also without a usable runtime file). */
    public function manifestUrl(): ?string
    {
        $reading = $this->runtime->reading();

        return in_array($reading->source, [RuntimeSource::File, RuntimeSource::LastGood], true) ? $reading->state?->updateManifestUrl : null;
    }

    public function enabled(): bool
    {
        return $this->manifestUrl() !== null;
    }

    /** Runs the check when it is on and stores the outcome; null (and no request) when it is off. */
    public function run(?CarbonImmutable $now = null): ?UpdateStatus
    {
        $url = $this->manifestUrl();
        if ($url === null) {
            return null;
        }
        $now ??= CarbonImmutable::now('UTC');
        $previous = $this->last();
        try {
            [$latest, $notes] = $this->fetch($url);
            $status = new UpdateStatus($now, $latest, $notes, null);
        } catch (UpdateCheckFailed $failed) {
            $status = new UpdateStatus($now, $previous?->latest, $previous?->notesUrl, $failed->getMessage());
        }
        $this->cache->forever(self::CACHE_KEY, $status->toArray());

        return $status;
    }

    /** The stored outcome of the last run, if any. */
    public function last(): ?UpdateStatus
    {
        $stored = $this->cache->get(self::CACHE_KEY);

        return is_array($stored) ? UpdateStatus::fromArray($stored) : null;
    }

    /**
     * @return array{0: string, 1: string|null} version and notes URL
     *
     * @throws UpdateCheckFailed
     */
    private function fetch(string $url): array
    {
        try {
            $response = Http::timeout(30)->connectTimeout(10)->accept('application/json')
                ->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => str_starts_with($url, 'http://') ? ['http', 'https'] : ['https']]])
                ->get($url);
        } catch (Throwable) {
            throw new UpdateCheckFailed('unreachable');
        }
        $body = $response->body();
        if (! $response->successful() || $body === '' || strlen($body) > self::MAX_BYTES) {
            throw new UpdateCheckFailed($response->successful() ? 'invalid_manifest' : 'http_'.$response->status());
        }

        try {
            $manifest = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UpdateCheckFailed('invalid_manifest');
        }
        $version = is_array($manifest) ? ($manifest['version'] ?? null) : null;
        if (! is_string($version) || preg_match(InstanceFacts::VERSION, $version) !== 1) {
            throw new UpdateCheckFailed('invalid_manifest');
        }
        $notes = is_array($manifest) && is_string($manifest['notes_url'] ?? null) ? mb_substr($manifest['notes_url'], 0, 2048) : null;

        return [$version, $notes];
    }
}
