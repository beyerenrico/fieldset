<?php

declare(strict_types=1);

namespace App\Domain\Platform\Auth;

use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Carbon;

/**
 * Marks a session that signed in through /platform/login (password + MFA) for one operator (ADR 0041).
 * The web guard is shared with /admin, whose SSO login an organization's identity provider controls: a
 * session started there (or in the Field App) never reaches /platform without this marker. The marker
 * expires after platform.session_hours (ASVS 3.3.2: periodic re-authentication).
 */
final readonly class PlatformSession
{
    public const string KEY = 'platform.auth';

    public function __construct(private Session $session) {}

    public function mark(User $user): void
    {
        $this->session->put(self::KEY, ['user_id' => $user->id, 'at' => Carbon::now()->getTimestamp()]);
    }

    public function isValid(?Authenticatable $user): bool
    {
        $marker = $this->session->get(self::KEY);
        if (! $user instanceof User || ! is_array($marker) || ($marker['user_id'] ?? null) !== $user->id) {
            return false;
        }

        $at = $marker['at'] ?? null;
        $hours = max(1, (int) config('platform.session_hours', 12));

        return is_int($at) && $at >= Carbon::now()->subHours($hours)->getTimestamp();
    }

    public function forget(): void
    {
        $this->session->forget(self::KEY);
    }
}
