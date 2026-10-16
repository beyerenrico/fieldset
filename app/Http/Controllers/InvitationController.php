<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\AcceptInvitation;
use App\Domain\Identity\Enums\MembershipStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Signed invitation link: set a password (new users) and join the organization. */
final class InvitationController
{
    public function show(Membership $membership): View|RedirectResponse
    {
        if ($membership->status !== MembershipStatus::Invited) {
            return redirect($this->home($membership));
        }

        return view('invitations.accept', [
            'membership' => $membership,
            'needsPassword' => $membership->user->email_verified_at === null,
        ]);
    }

    public function accept(Request $request, Membership $membership, AcceptInvitation $accept): RedirectResponse
    {
        $needsPassword = $membership->user->email_verified_at === null;
        $data = $request->validate($needsPassword
            ? ['password' => ['required', 'confirmed', Password::defaults()]]
            : []);

        $accept->handle($membership, $data['password'] ?? null);
        Auth::login($membership->user);
        $request->session()->regenerate();

        return redirect($this->home($membership));
    }

    private function home(Membership $membership): string
    {
        return $membership->user->hasPermissionIn($membership->organization_id, Permission::AdminAccess)
            ? url('/admin/'.$membership->organization->slug)
            : url('/app');
    }
}
