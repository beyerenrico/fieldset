<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Reset;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Organization;
use App\Domain\Platform\Instance\Restore\SequenceAdvancer;
use App\Domain\Platform\Models\PlatformAdmin;
use Illuminate\Support\Facades\DB;

/**
 * What `fsa reset` must know from the live database before it stops anything (plan U23, ADR 0088), read in a socket-less
 * one-off (`instance:reset plan`): the organizations (soft-deleted ones count: their audit trail is still there) and how
 * many of them are not the demo, which decides whether a reset without a safety backup still writes an audit export;
 * the oldest real organization (name and locale, for an instance whose install record lacks them, e.g. one installed
 * from a backup); and the active platform operators, oldest grant first, from whom fsa picks the owner of the fresh
 * instance (the install record keeps the owner's e-mail only as a digest); and the last value of every sequence, the
 * floors of the new database's sequences (review S1: signed links name row IDs, which must never be handed out again).
 * Read-only.
 */
final class ResetPlan
{
    /**
     * @return array{organizations: array{total: int, non_demo: int}, organization: array{name: string, locale: string}|null, operators: list<array{email: string, name: string, locale: string}>, sequences: array<string, int>}
     */
    public static function read(): array
    {
        $first = Organization::query()->where('is_demo', false)->orderBy('id')->first(['name', 'locale']);

        return [
            'organizations' => [
                'total' => Organization::query()->withTrashed()->count(),
                'non_demo' => Organization::query()->withTrashed()->where('is_demo', false)->count(),
            ],
            'organization' => $first instanceof Organization ? ['name' => $first->name, 'locale' => $first->locale] : null,
            'operators' => self::operators(),
            'sequences' => SequenceAdvancer::values(DB::connection()),
        ];
    }

    /** @return list<array{email: string, name: string, locale: string}> */
    private static function operators(): array
    {
        $rows = PlatformAdmin::query()
            ->join('users', 'users.id', '=', 'platform_admins.user_id')
            ->where('users.status', UserStatus::Active->value)
            ->orderBy('platform_admins.id')
            ->toBase()
            ->get(['users.email', 'users.name', 'users.locale']);

        return array_values(array_map(static fn (object $row): array => [
            'email' => (string) ($row->email ?? ''), 'name' => (string) ($row->name ?? ''), 'locale' => (string) ($row->locale ?? ''),
        ], $rows->all()));
    }
}
