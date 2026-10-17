<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Sso\Contracts\PasswordAuthGate;
use App\Domain\Identity\Sso\Exceptions\SsoRequiredException;
use App\Domain\Identity\Sso\Login\BlockedPasswordFlow;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly PasswordAuthGate $gate, private readonly BlockedPasswordFlow $blocked) {}

    /**
     * Validate and reset the user's forgotten password. Refused for an email whose domain enforces SSO
     * (ADR 0036; backstop behind BlockPasswordFlowsForSso).
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        try {
            $this->gate->assertPasswordReset($user->email);
        } catch (SsoRequiredException $e) {
            throw $this->blocked->reset($user->email, $e, 'reset_action');
        }

        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
