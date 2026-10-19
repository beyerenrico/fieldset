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
 * entry of the new epoch). Re-activations and new accounts are not carried: a restore only ever tightens access.
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

    public function __construct(private ConnectionInterface $source, private ConnectionInterface $target) {}

    /** @return Counts */
    public function carry(): array
    {
        $counts = ['users_erased' => 0, 'users_deactivated' => 0, 'users_missing' => 0, 'passwords' => 0, 'mfa' => 0, 'emails' => 0, 'email_conflicts' => 0, 'export_directories' => []];
        $source = $this->source->table('users')->get(self::COLUMNS)->keyBy('id');
        $erasure = new ErasureCarry($this->target);
        foreach ($this->target->table('users')->orderBy('id')->get(self::COLUMNS) as $user) {
            $before = $source->get($user->id);
            if ($before === null) {
                $counts['users_missing'] += $this->deactivate($user, null) ? 1 : 0;
            } elseif ($before->erased_at !== null && $user->erased_at === null) {
                $this->target->table('users')->where('id', $user->id)->update([...$this->only($before, self::ERASED), 'remember_token' => null]);
                array_push($counts['export_directories'], ...$erasure->scrub((int) $user->id, (string) $user->email));
                $counts['users_erased']++;
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
    private function tighten(stdClass $user, stdClass $before, array $counts): array
    {
        if ($before->status === UserStatus::Deactivated->value && $this->deactivate($user, $before->deactivated_at)) {
            $counts['users_deactivated']++;
        }
        if ($before->password !== $user->password) {
            $this->target->table('users')->where('id', $user->id)->update(['password' => $before->password, 'password_known' => $before->password_known]);
            $counts['passwords']++;
        }
        $mfa = $this->only($before, self::MFA);
        if ($mfa !== $this->only($user, self::MFA)) {
            $this->target->table('users')->where('id', $user->id)->update($mfa);
            $counts['mfa']++;
        }
        if ($before->email !== $user->email) {
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
