<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * Field App profile updates: name, locale and time zone only. Email changes go through the admin
 * profile with Filament's email-change verification, so nobody can claim an address they do not own.
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', 'in:de,en'],
            'timezone' => ['nullable', 'timezone:all'],
        ])->validateWithBag('updateProfileInformation');

        $user->forceFill([
            'name' => $data['name'],
            'locale' => $data['locale'] ?? $user->locale,
            'timezone' => $data['timezone'] ?? $user->timezone,
        ])->save();
    }
}
