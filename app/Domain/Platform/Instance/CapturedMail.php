<?php

declare(strict_types=1);

namespace App\Domain\Platform\Instance;

use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\LoginLinks\LoginLinkKind;
use App\Domain\Identity\Models\LoginLink;
use App\Domain\Identity\Models\Membership;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What capture mode kept from people (ADR 0080), counted from the app's own records when `fieldset config set mail smtp`
 * switches to real delivery: invitations still waiting for acceptance and password resets still valid. Their mails
 * went to the catcher, so the recipients never got them; the operator resends them. A reset token behind an unused
 * owner sign-in link does not count: fieldset handed that link over itself, no mail was sent. Counting the catcher's
 * messages instead would need its credentials in the app and localized subjects. Read-only; counts only.
 */
final class CapturedMail
{
    /** @return array{invitations: int, password_resets: int} */
    public function assess(): array
    {
        $now = Carbon::now();
        $broker = (string) config('auth.defaults.passwords', 'users');
        $table = (string) config("auth.passwords.{$broker}.table", 'password_reset_tokens');
        $expire = (int) config("auth.passwords.{$broker}.expire", 60);
        $ownerLinks = LoginLink::query()->select('user_id')->where('kind', LoginLinkKind::Owner)->whereNull('used_at')->where('expires_at', '>', $now);

        return [
            'invitations' => Membership::query()->where('status', MembershipStatus::Invited)
                ->where('invited_at', '>', $now->copy()->subDays(UrlChangeImpact::INVITATION_DAYS))->count(),
            'password_resets' => DB::table($table)->where('created_at', '>', $now->copy()->subMinutes($expire))
                ->whereNotIn('email', User::query()->select('email')->whereIn('id', $ownerLinks))->count(),
        ];
    }
}
