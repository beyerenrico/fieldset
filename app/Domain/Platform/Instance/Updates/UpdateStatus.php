<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Updates;

use App\Domain\Instance\Runtime\InstanceFacts;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The outcome of the update check as `/platform` shows it (R48, E15, ADR 0089): when it last ran, the newest release it
 * saw (kept from the last successful check when a later one fails) with its notes URL, and the error of the last run.
 * The manifest is untrusted display data: the version must be a semantic version, and the notes URL becomes a link
 * only when it is https (`notesLink()`).
 */
final readonly class UpdateStatus
{
    public function __construct(
        public CarbonImmutable $checkedAt,
        public ?string $latest,
        public ?string $notesUrl,
        public ?string $error,
    ) {}

    /** @param  array<string, mixed>  $data  a stored status; null when it does not look like one */
    public static function fromArray(array $data): ?self
    {
        $latest = $data['latest'] ?? null;
        $notes = $data['notes_url'] ?? null;
        $error = $data['error'] ?? null;
        $at = $data['checked_at'] ?? null;
        if (! is_string($at) || ($latest !== null && (! is_string($latest) || preg_match(InstanceFacts::VERSION, $latest) !== 1))
            || ($notes !== null && ! is_string($notes)) || ($error !== null && ! is_string($error))) {
            return null;
        }

        try {
            return new self(CarbonImmutable::parse($at)->utc(), $latest, $notes, $error);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{checked_at: string, latest: string|null, notes_url: string|null, error: string|null} */
    public function toArray(): array
    {
        return ['checked_at' => $this->checkedAt->toIso8601ZuluString(), 'latest' => $this->latest, 'notes_url' => $this->notesUrl, 'error' => $this->error];
    }

    /** Whether the newest release seen is newer than the running one (semantic-version precedence). */
    public function availableFor(string $current): bool
    {
        return $this->latest !== null && preg_match(InstanceFacts::VERSION, $current) === 1 && self::compare($this->latest, $current) > 0;
    }

    /** The release notes as a link target: only an https URL without spaces or quotes; anything else stays text. */
    public function notesLink(): ?string
    {
        $url = $this->notesUrl;

        return $url !== null && str_starts_with($url, 'https://') && preg_match('/[\s"\'<>`\\\\]/', $url) !== 1 && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    /** Semantic Versioning 2.0 precedence of two versions of the InstanceFacts grammar: <0, 0 or >0. */
    public static function compare(string $a, string $b): int
    {
        [$coreA, $preA] = array_pad(explode('-', $a, 2), 2, null);
        [$coreB, $preB] = array_pad(explode('-', $b, 2), 2, null);
        $core = array_map(intval(...), explode('.', (string) $coreA)) <=> array_map(intval(...), explode('.', (string) $coreB));
        if ($core !== 0 || $preA === $preB) {
            return $core;
        }
        if ($preA === null || $preB === null) {
            return $preA === null ? 1 : -1; // a release ranks above its pre-releases
        }

        return self::comparePre(explode('.', $preA), explode('.', $preB));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private static function comparePre(array $a, array $b): int
    {
        foreach ($a as $i => $part) {
            if (! array_key_exists($i, $b)) {
                return 1;
            }
            $other = $b[$i];
            $numeric = ctype_digit($part);
            $otherNumeric = ctype_digit($other);
            $order = match (true) {
                $numeric && $otherNumeric => (int) $part <=> (int) $other,
                $numeric !== $otherNumeric => $numeric ? -1 : 1, // numeric identifiers rank lower
                default => strcmp($part, $other) <=> 0,
            };
            if ($order !== 0) {
                return $order;
            }
        }

        return count($a) <=> count($b);
    }
}
