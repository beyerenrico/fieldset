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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Signed invitation link (security review M0):
 * - new accounts (never verified) choose a password here and are signed in once;
 * - existing accounts must sign in normally (password + MFA) and then accept;
 * - a link only works while the membership is still `invited` (410 afterwards).
 */
final class InvitationController
{
    public function show(Request $request, Membership $membership): View
    {
        abort_if($membership->status !== MembershipStatus::Invited, 410);

        $isNewAccount = $membership->user->email_verified_at === null;

        if (! $isNewAccount && ! $membership->user->is($request->user())) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return view('invitations.accept', [
            'membership' => $membership,
            'needsPassword' => $isNewAccount,
            'needsLogin' => ! $isNewAccount && ! $membership->user->is($request->user()),
            'loginUrl' => $this->loginUrl($membership),
        ]);
    }

    public function accept(Request $request, Membership $membership, AcceptInvitation $accept): RedirectResponse
    {
        abort_if($membership->status !== MembershipStatus::Invited, 410);

        $isNewAccount = $membership->user->email_verified_at === null;
        $data = $request->validate($isNewAccount ? ['password' => ['required', 'confirmed', Password::defaults()]] : []);

        if (! $isNewAccount) {
            abort_unless($membership->user->is($request->user()), 403);
        }

        DB::transaction(function () use ($membership, $data, $accept): void {
            $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->status !== MembershipStatus::Invited, 410);
            $accept->handle($locked, $data['password'] ?? null);
        });

        if ($isNewAccount) {
            Auth::login($membership->user);
            $request->session()->regenerate();
        }

        return redirect($this->home($membership));
    }

    private function home(Membership $membership): string
    {
        return $membership->user->hasPermissionIn($membership->organization_id, Permission::AdminAccess)
            ? url('/admin/'.$membership->organization->slug)
            : url('/app');
    }

    private function loginUrl(Membership $membership): string
    {
        return $membership->user->hasPermissionIn($membership->organization_id, Permission::AdminAccess)
            ? url('/admin/login')
            : url('/app/login');
    }
}
