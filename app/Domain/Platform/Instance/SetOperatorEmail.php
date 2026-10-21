<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Actions\IssueOwnerLink;
use App\Domain\Identity\LoginLinks\IssuedLoginLink;
use App\Domain\Identity\LoginLinks\LoginLinkRefused;
use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Platform\Actions\GrantPlatformAdmin;
use App\Domain\Platform\Actions\RevokePlatformAdmin;
use App\Domain\Platform\Models\PlatformAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The app side of `fieldset config set operator-email <email>` (R32, plan U11/U12, ADR 0080), in one transaction:
 *
 * - grants the platform operator role (ADR 0041) to the account with that address, creating it when there is none
 *   (no organization, a password nobody knows, the address counted as verified: fieldset's caller vouches for it);
 * - issues the first sign-in link (the owner link, password-reset broker, 60 minutes) when the account has no password
 *   of its own yet, so the new operator can set one; an account with a password signs in at /platform as before.
 *   A repeat for an operator who is one already issues a new link only when no usable one is left (unused, not
 *   expired): a new link would invalidate the one delivered before, so the same command twice changes nothing
 *   (ADR 0080 amendment 2026-10-19);
 * - with `$revokePrevious` revokes every other operator, which is how the instance moves to a new operator;
 * - audits `instance.config_changed` (key `operator-email`, the new address and the revoked ones) as the instance owner
 *   at the command line; grant and revocations keep their own `platform.admin_*` entries.
 *
 * Refused before anything changes for an address whose domain enforces SSO (/platform takes only password sign-ins)
 * and for a deactivated account.
 */
final readonly class SetOperatorEmail
{
    public const string KEY = 'operator-email';

    public function __construct(
        private GrantPlatformAdmin $grant,
        private RevokePlatformAdmin $revoke,
        private IssueOwnerLink $ownerLink,
        private PasswordAuthGate $gate,
        private AuditLogger $audit,
    ) {}

    /** @throws OperatorEmailRefused|LoginLinkRefused */
    public function handle(string $email, bool $revokePrevious, ?int $hostUid): OperatorEmailResult
    {
        $email = Str::lower(trim($email));
        try {
            $this->gate->assertPasswordLogin($email);
        } catch (SsoRequiredException) {
            throw OperatorEmailRefused::ssoRequired();
        }

        return DB::transaction(function () use ($email, $revokePrevious, $hostUid): OperatorEmailResult {
            [$user, $created] = $this->account($email);
            $granted = $this->grant->handle($user);
            $revoked = $revokePrevious ? $this->revokeOthers($user) : [];
            $usable = $user->password_known ? null : $this->usableLink($user);
            $link = ! $user->password_known && ($created || $granted || ! $usable instanceof LoginLink) ? $this->ownerLink->handle($user, $hostUid) : null;
            if ($granted || $revoked !== []) {
                $this->audit->cli('instance.config_changed', $user, ['key' => self::KEY, 'from' => null, 'to' => $email, 'revoked' => $revoked], $hostUid);
            }

            return new OperatorEmailResult($user, $created, $granted, $revoked, $link, $link instanceof IssuedLoginLink ? null : $usable?->expires_at);
        });
    }

    /**
     * @return array{0: User, 1: bool} the account and whether it was created
     *
     * @throws OperatorEmailRefused
     */
    private function account(string $email): array
    {
        $user = User::query()->where('email', $email)->first();
        if ($user instanceof User) {
            return $user->isActive() ? [$user, false] : throw OperatorEmailRefused::inactive();
        }
        $user = User::query()->create([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Str::password(64), // nobody knows it: the owner link sets the first one (ADR 0039)
            'locale' => (string) config('app.locale'),
        ]);
        $user->forceFill(['password_known' => false])->saveQuietly();
        $user->markEmailAsVerified();

        return [$user, true];
    }

    /** The account's sign-in link that can still be used (not used, not expired), if any. */
    private function usableLink(User $user): ?LoginLink
    {
        return LoginLink::query()->where('user_id', $user->id)->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
    }

    /** @return list<string> the addresses whose operator role was revoked */
    private function revokeOthers(User $keep): array
    {
        $revoked = [];
        foreach (PlatformAdmin::query()->with('user')->where('user_id', '!=', $keep->id)->orderBy('id')->get() as $grant) {
            if ($this->revoke->handle($grant->user)) {
                $revoked[] = $grant->user->email;
            }
        }

        return $revoked;
    }
}
