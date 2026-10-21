<?php

declare(strict_types=1);

namespace App\Domain\Identity\LoginLinks;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\Organization;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use LogicException;
use SensitiveParameter;

/**
 * Storage of one-time sign-in links (ADR 0056, ADR 0068): one usable link per user, so a newer link replaces
 * every unused older one; the token is stored as a SHA-256 hash and travels in the URL fragment of
 * `/login-link#<token>`, so it never reaches a request line or an access log. Issuing is audited for the
 * instance owner's command line (actor `cli`, host UID).
 */
final readonly class LoginLinks
{
    public const string ISSUER_CLI = 'cli';

    public function __construct(private AuditLogger $audit) {}

    public function replace(User $user, LoginLinkKind $kind, #[SensitiveParameter] string $token, CarbonInterface $expiresAt, ?int $hostUid): LoginLink
    {
        return DB::transaction(function () use ($user, $kind, $token, $expiresAt, $hostUid): LoginLink {
            // Serializes concurrent issues for one user: afterwards exactly one unused link exists.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $older = LoginLink::query()->where('user_id', $user->id)->whereNull('used_at');
            if ($kind === LoginLinkKind::Admin && (clone $older)->where('kind', LoginLinkKind::Owner)->exists()) {
                self::broker()->deleteToken($user); // the replaced owner link's reset token goes with it
            }
            $replaced = $older->delete();

            $link = LoginLink::query()->create([
                'user_id' => $user->id,
                'kind' => $kind,
                'token_hash' => LoginLink::hashToken($token),
                'expires_at' => $expiresAt,
                'created_by' => self::ISSUER_CLI,
                'created_by_uid' => $hostUid,
            ]);
            $this->audit->cli('login_link.issued', $user, [
                'email' => $user->email,
                'kind' => $kind->value,
                'expires_at' => $expiresAt->toIso8601String(),
                'replaced' => $replaced,
            ], $hostUid);

            return $link;
        });
    }

    /** The password-reset broker behind owner links (Fortify and Filament use the same one). */
    public static function broker(): PasswordBroker
    {
        $broker = Password::broker();
        if (! $broker instanceof PasswordBroker) {
            throw new LogicException('Owner links need Laravel\'s password-reset broker.');
        }

        return $broker;
    }

    /** Sign-in links are for admins: an active membership with admin access in some organization. */
    public static function isAdmin(User $user): bool
    {
        return $user->activeOrganizations()->get()
            ->contains(fn (Organization $organization): bool => $user->hasPermissionIn($organization, Permission::AdminAccess));
    }

    public function url(#[SensitiveParameter] string $token): string
    {
        return route('login-link.show').'#'.$token;
    }

    public function find(#[SensitiveParameter] string $token): ?LoginLink
    {
        return LoginLink::query()->where('token_hash', LoginLink::hashToken($token))->first();
    }

    /**
     * Links that can still be used: not used and not expired (`fieldset status`).
     *
     * @return Collection<int, LoginLink>
     */
    public function unused(): Collection
    {
        return LoginLink::query()->with('user')->whereNull('used_at')->where('expires_at', '>', now())->oldest('id')->get();
    }

    /**
     * The most recent links in any state, newest first.
     *
     * @return Collection<int, LoginLink>
     */
    public function recent(int $limit = 50): Collection
    {
        return LoginLink::query()->with('user')->latest('id')->limit($limit)->get();
    }

    /** `unused`, `used` or `expired`, for listings. */
    public static function status(LoginLink $link): string
    {
        return match (true) {
            $link->isUsed() => 'used',
            $link->isExpired() => 'expired',
            default => 'unused',
        };
    }
}
