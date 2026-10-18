<?php

declare(strict_types=1);

namespace App\Domain\Identity\Demo;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Which accounts still sign in with the demo password `password` (R29: `fsa tunnel start` and the local-CA edge
 * refuse while any user has it, ADR 0081). Every account with a known password is checked, demo or not; accounts
 * whose password nobody knows (an owner link or invitation not used yet, `password_known = false`) cannot match and
 * are skipped. Each check costs one password hash (BCRYPT_ROUNDS), so the time grows with the number of accounts.
 */
final class DefaultPasswordUsers
{
    private const int CHUNK = 200;

    /**
     * @param  list<string>|null  $emails  only these accounts (e.g. the demo accounts); null for every account
     * @return array{checked: int, users: list<array{email: string, demo: bool}>}
     */
    public function find(?array $emails = null): array
    {
        $checked = 0;
        $found = [];
        $demo = array_flip(DemoAccounts::emails());
        User::query()->whereNotNull('password')->where('password_known', true)->select(['id', 'email', 'password'])
            ->when($emails !== null, static fn ($query) => $query->whereIn('email', $emails))
            ->chunkById(self::CHUNK, function ($users) use (&$checked, &$found, $demo): void {
                foreach ($users as $user) {
                    $checked++;
                    if ($this->matches((string) $user->getAuthPassword())) {
                        $found[] = ['email' => $user->email, 'demo' => isset($demo[$user->email])];
                    }
                }
            });

        return ['checked' => $checked, 'users' => $found];
    }

    private function matches(string $hash): bool
    {
        try {
            return Hash::check(DefaultDemoPassword::PASSWORD, $hash);
        } catch (Throwable) {
            return false; // a hash of another algorithm (Hash::check verifies the algorithm) is not `password`
        }
    }
}
