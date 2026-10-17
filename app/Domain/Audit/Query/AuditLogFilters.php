<?php

declare(strict_types=1);

namespace App\Domain\Audit\Query;

use App\Domain\Audit\AuditImpersonator;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The audit log filters of §8.11 (actor, event, subject type, date range) as plain values, so the table, the
 * synchronous CSV download and the queued export all select the same entries. Actors are `user:<id>` or
 * `system`; dates are calendar days in `timezone`; `impersonated` keeps only entries written while a platform
 * operator impersonated a member (AuditImpersonator).
 */
final readonly class AuditLogFilters
{
    public const string SYSTEM = 'system';

    /**
     * @param  list<string>  $actors
     * @param  list<string>  $events
     * @param  list<string>  $subjectTypes
     */
    public function __construct(
        public array $actors = [],
        public array $events = [],
        public array $subjectTypes = [],
        public ?string $from = null,
        public ?string $until = null,
        public string $timezone = 'UTC',
        public bool $impersonated = false,
    ) {}

    /**
     * From a Filament table filter state: select filters hold `values`, the date filter `from`/`until`, the
     * impersonation toggle `isActive`.
     *
     * @param  array<string, mixed>  $state
     */
    public static function fromTableFilters(array $state, string $timezone): self
    {
        return new self(
            self::strings($state['actor']['values'] ?? []),
            self::strings($state['event']['values'] ?? []),
            self::strings($state['subject_type']['values'] ?? []),
            self::date($state['created_at']['from'] ?? null),
            self::date($state['created_at']['until'] ?? null),
            $timezone,
            ($state['impersonated']['isActive'] ?? false) === true,
        );
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::strings($data['actors'] ?? []), self::strings($data['events'] ?? []), self::strings($data['subject_types'] ?? []),
            self::date($data['from'] ?? null), self::date($data['until'] ?? null),
            is_string($data['timezone'] ?? null) ? $data['timezone'] : 'UTC',
            ($data['impersonated'] ?? false) === true,
        );
    }

    /** @return array{actors: list<string>, events: list<string>, subject_types: list<string>, from: ?string, until: ?string, timezone: string, impersonated: bool} */
    public function toArray(): array
    {
        return [
            'actors' => $this->actors, 'events' => $this->events, 'subject_types' => $this->subjectTypes,
            'from' => $this->from, 'until' => $this->until, 'timezone' => $this->timezone, 'impersonated' => $this->impersonated,
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->actors !== [], fn (Builder $q): Builder => $this->applyActors($q))
            ->when($this->events !== [], fn (Builder $q) => $q->whereIn('event', $this->events))
            ->when($this->subjectTypes !== [], fn (Builder $q) => $q->whereIn('subject_type', $this->subjectTypes))
            ->when($this->from !== null, fn (Builder $q) => $q->where('created_at', '>=', $this->dayStart((string) $this->from)))
            ->when($this->until !== null, fn (Builder $q) => $q->where('created_at', '<', $this->dayStart((string) $this->until)->addDay()))
            ->when($this->impersonated, fn (Builder $q) => $q->whereNotNull('properties->'.AuditImpersonator::PROPERTY));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function applyActors(Builder $query): Builder
    {
        $userIds = [];
        foreach ($this->actors as $actor) {
            if (str_starts_with($actor, 'user:') && ctype_digit(substr($actor, 5))) {
                $userIds[] = (int) substr($actor, 5);
            }
        }
        $system = in_array(self::SYSTEM, $this->actors, true);

        return $query->where(function (Builder $q) use ($userIds, $system): void {
            $q->where(fn (Builder $u) => $u->where('causer_type', (new User)->getMorphClass())->whereIn('causer_id', $userIds === [] ? [0] : $userIds));
            if ($system) {
                $q->orWhereNull('causer_id');
            }
        });
    }

    private function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->timezone)->startOfDay()->utc();
    }

    /** @return list<string> */
    private static function strings(mixed $values): array
    {
        return array_values(array_filter(is_array($values) ? $values : [], static fn (mixed $v): bool => is_string($v) && $v !== ''));
    }

    /** A calendar day `Y-m-d` from client state; anything else (also "2026-13-45") is ignored. */
    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? substr($value, 0, 10) : null;
    }
}
