<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance\Restore;

use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * The account half of the R64 carry (plan U17, KTD18, ADR 0085): what changed on accounts after the backup was taken is
 * read from the pre-restore database (`source`) and re-applied to the restored one (`target`) before the swap, so a
 * restore never brings back a revoked access.
 *
 * - erased in the source → erased in the target: the source's scrubbed account columns, and the personal data the
 *   erasure removes (ErasureCarry);
 * - deactivated in the source, or no longer there → deactivated, its remember token, API tokens and sessions gone;
 * - a password hash or the MFA settings changed → the source's values;
 * - an e-mail address changed → the source's, unless another account of the target holds it (counted, not copied).
 *
 * Everything is written with the query builder (no model events, no audit entries: `instance.restored` stays the first
 * entry of the new epoch). Re-activations and new accounts are not carried: a restore only ever tightens access. In
 * mode audit (CarryScope) an erasure or deactivation needs its timestamp after the backup, a password, MFA or e-mail
 * change the source's audit entry after it; an account the source lacks is kept.
 *
 * @phpstan-type Counts array{users_erased: int, users_deactivated: int, users_missing: int, passwords: int, mfa: int, emails: int, email_conflicts: int, export_directories: list<array{disk: string, path: string}>}
 */
final readonly class AccountCarry
{
    private const array COLUMNS = [
        'id', 'name', 'email', 'email_verified_at', 'status', 'deactivated_at', 'locale', 'timezone', 'password', 'password_known',
        'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'erased_at',
    ];

    private const array MFA = ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'];

    private const array ERASED = [
        'name', 'email', 'email_verified_at', 'status', 'deactivated_at', 'locale', 'timezone', 'password', 'password_known',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'erased_at',
    ];

    /** @var array<string, array<string, true>|null> audited subjects per change (null: mode state) */
    private array $audited;

    public function __construct(private ConnectionInterface $source, private ConnectionInterface $target, private CarryScope $scope)
    {
        $this->audited = [
            'deactivated' => $scope->subjects(User::class, ['user.deactivated']),
            'passwords' => $scope->subjects(User::class, ['auth.password_changed']),
            'mfa' => $scope->subjects(User::class, ['auth.mfa_enabled', 'auth.mfa_disabled']),
            'emails' => $scope->subjects(User::class, ['updated'], 'email'),
        ];
    }

    /** @return Counts */
    public function carry(): array
    {
        $counts = ['users_erased' => 0, 'users_deactivated' => 0, 'users_missing' => 0, 'passwords' => 0, 'mfa' => 0, 'emails' => 0, 'email_conflicts' => 0, 'export_directories' => []];
        $source = $this->source->table('users')->get(self::COLUMNS)->keyBy('id');
        $erasure = new ErasureCarry($this->target);
        foreach ($this->target->table('users')->orderBy('id')->get(self::COLUMNS) as $user) {
            $before = $source->get($user->id);
            if ($before === null) { // hard-deleted after the backup (mode state), or created after the source's backup (mode audit)
                $counts['users_missing'] += $user->status !== UserStatus::Deactivated->value && $this->scope->permits('users_missing', null, null) && $this->deactivate($user, null) ? 1 : 0;
            } elseif ($before->erased_at !== null && $user->erased_at === null) {
                $counts = $this->erase($user, $before, $erasure, $counts);
            } else {
                $counts = $this->tighten($user, $before, $counts);
            }
        }

        return $counts;
    }

    /**
     * @param  Counts  $counts
     * @return Counts
     */
    private function erase(stdClass $user, stdClass $before, ErasureCarry $erasure, array $counts): array
    {
        if (! $this->scope->permits('users_erased', null, null, $before->erased_at)) {
            return $counts;
        }
        $this->target->table('users')->where('id', $user->id)->update([...$this->only($before, self::ERASED), 'remember_token' => null]);
        array_push($counts['export_directories'], ...$erasure->scrub((int) $user->id, (string) $user->email));
        $counts['users_erased']++;

        return $counts;
    }

    /**
     * @param  Counts  $counts
     * @return Counts
     */
    private function tighten(stdClass $user, stdClass $before, array $counts): array
    {
        $id = (int) $user->id;
        if ($before->status === UserStatus::Deactivated->value && $user->status !== UserStatus::Deactivated->value
            && $this->scope->permits('users_deactivated', $this->audited['deactivated'], $id, $before->deactivated_at) && $this->deactivate($user, $before->deactivated_at)) {
            $counts['users_deactivated']++;
        }
        if ($before->password !== $user->password && $this->scope->permits('passwords', $this->audited['passwords'], $id)) {
            $this->target->table('users')->where('id', $user->id)->update(['password' => $before->password, 'password_known' => $before->password_known]);
            $counts['passwords']++;
        }
        $mfa = $this->only($before, self::MFA);
        if ($mfa !== $this->only($user, self::MFA) && $this->scope->permits('mfa', $this->audited['mfa'], $id)) {
            $this->target->table('users')->where('id', $user->id)->update($mfa);
            $counts['mfa']++;
        }
        if ($before->email !== $user->email && $this->scope->permits('emails', $this->audited['emails'], $id)) {
            $counts[$this->email($user, (string) $before->email) ? 'emails' : 'email_conflicts']++;
        }

        return $counts;
    }

    /** Copies the source's address; false (nothing written) when another account of the target holds it. */
    private function email(stdClass $user, string $email): bool
    {
        if ($this->target->table('users')->where('email', $email)->where('id', '!=', $user->id)->exists()) {
            return false;
        }
        $this->target->table('users')->where('id', $user->id)->update(['email' => $email]);

        return true;
    }

    /** Deactivates an account that is active in the target; false when it was deactivated there already. */
    private function deactivate(stdClass $user, mixed $since): bool
    {
        if ($user->status === UserStatus::Deactivated->value) {
            return false;
        }
        $this->target->table('users')->where('id', $user->id)->update([
            'status' => UserStatus::Deactivated->value, 'deactivated_at' => $since ?? now(), 'remember_token' => null,
        ]);
        $this->target->table('personal_access_tokens')->where('tokenable_type', new User()->getMorphClass())->where('tokenable_id', $user->id)->delete();
        $this->target->table('sessions')->where('user_id', $user->id)->delete();
        $this->target->table('devices')->where('user_id', $user->id)->whereNull('wipe_requested_at')->update(['wipe_requested_at' => now()]);

        return true;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function only(stdClass $row, array $columns): array
    {
        return array_intersect_key((array) $row, array_flip($columns));
    }
}
