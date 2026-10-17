<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Domains\ManagedEmailAddress;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Filament's profile page, except that an email address managed by single sign-on (ManagedEmailAddress:
 * linked to an identity provider, or at a domain verified by one of the user's organizations or by an
 * enforcing one) cannot be changed here: moving it off an enforced domain would escape enforcement. The
 * field is read-only with the reason below it, and the save refuses a changed address anyway. The
 * email-change verification link is guarded too (EmailChangeVerificationController).
 */
class EditProfile extends BaseEditProfile
{
    protected function getEmailFormComponent(): Component
    {
        $component = parent::getEmailFormComponent();
        if ($component instanceof TextInput && $this->emailIsManaged()) {
            $component->disabled()->dehydrated(false)->helperText(__('sso_admin.profile.email_managed'));
        }

        return $component;
    }

    /** @param  array<string, mixed>  $data */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        if ($record instanceof User && array_key_exists('email', $data) && $data['email'] !== $record->email
            && app(ManagedEmailAddress::class)->isManaged($record)) {
            throw ValidationException::withMessages(['data.email' => __('sso_admin.profile.email_managed')]);
        }

        return parent::handleRecordUpdate($record, $data);
    }

    private function emailIsManaged(): bool
    {
        $user = $this->getUser();

        return $user instanceof User && app(ManagedEmailAddress::class)->isManaged($user);
    }
}
