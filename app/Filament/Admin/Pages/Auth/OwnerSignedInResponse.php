<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Auth;

use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** After the owner's first link set a password (ADR 0056): on to the profile page, where MFA is offered. */
final readonly class OwnerSignedInResponse implements PasswordResetResponse
{
    public function __construct(private string $url) {}

    /** @param  Request  $request */
    public function toResponse($request): RedirectResponse
    {
        return redirect()->to($this->url);
    }
}
