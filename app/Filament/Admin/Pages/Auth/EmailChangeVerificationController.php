<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Domains\ManagedEmailAddress;
use Filament\Auth\Http\Controllers\EmailChangeVerificationController as BaseController;
use Filament\Auth\Http\Requests\EmailChangeVerificationRequest;
use Filament\Auth\Http\Responses\Contracts\EmailChangeVerificationResponse;
use Filament\Notifications\Notification;

/**
 * Filament's email-change verification link, refused while the current address is managed by single
 * sign-on (ManagedEmailAddress): a link requested before the domain was verified or enforcement was
 * switched on must not move the address off the domain afterwards. Bound in place of Filament's
 * controller by AdminPanelProvider; the pending link stays unused.
 */
class EmailChangeVerificationController extends BaseController
{
    public function __invoke(EmailChangeVerificationRequest $request): EmailChangeVerificationResponse
    {
        $user = $request->user();
        if ($user instanceof User && app(ManagedEmailAddress::class)->isManaged($user)) {
            Notification::make()->danger()->title(__('sso_admin.profile.email_managed'))->persistent()->send();

            return app(EmailChangeVerificationResponse::class);
        }

        return parent::__invoke($request);
    }
}
