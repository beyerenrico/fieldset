<?php

declare(strict_types=1);

namespace App\Domain\Identity\Demo;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sessions\EndUserAccess;
use Illuminate\Support\Facades\DB;

/**
 * `fsa demo --rotate-passwords` (R29, ADR 0081): every demo account (DemoAccounts) gets a new random password, and its
 * sessions, remember-me token and API tokens are revoked, so whoever knew the old password (`password` on a
 * loopback demo that is about to become reachable) is signed out everywhere. Devices are not wiped: their data is
 * the demo's own. One audit entry per account (`instance.demo_password_rotated`, actor `cli` with the host UID)
 * replaces the generic `auth.password_changed`.
 */
final readonly class RotateDemoPasswords
{
    public const string EVENT = 'instance.demo_password_rotated';

    public function __construct(private AuditLogger $audit) {}

    /** @return array{passwords: array<string, string>, sessions_ended: int, tokens_revoked: int} email => new password */
    public function handle(?int $hostUid): array
    {
        $passwords = [];
        $sessions = 0;
        $tokens = 0;
        DB::transaction(function () use ($hostUid, &$passwords, &$sessions, &$tokens): void {
            foreach (DemoAccounts::users() as $user) {
                [$endedSessions, $revokedTokens] = $this->rotate($user, $passwords[$user->email] = RandomDemoPasswords::generate());
                $sessions += $endedSessions;
                $tokens += $revokedTokens;
                $this->audit->cli('instance.demo_password_rotated', $user, ['email' => $user->email, 'sessions_ended' => $endedSessions, 'api_access_revoked' => $revokedTokens], $hostUid);
            }
        });

        return ['passwords' => $passwords, 'sessions_ended' => $sessions, 'tokens_revoked' => $tokens];
    }

    /** @return array{0: int, 1: int} sessions ended, API tokens revoked */
    private function rotate(User $user, string $password): array
    {
        $sessions = DB::table('sessions')->where('user_id', $user->id)->count();
        User::withoutPasswordAudit(fn () => $user->forceFill(['password' => $password, 'password_known' => true])->save());
        $tokens = $user->tokens()->delete();
        EndUserAccess::endSessions($user);

        return [$sessions, is_int($tokens) ? $tokens : 0];
    }
}
