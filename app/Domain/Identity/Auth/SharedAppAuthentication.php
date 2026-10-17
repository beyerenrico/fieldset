<?php

declare(strict_types=1);

namespace App\Domain\Identity\Auth;

use App\Domain\Identity\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Filament app MFA that stores recovery codes in Fortify's format (encrypted plain codes), so the
 * admin panel and the Field App (Fortify) share one TOTP secret and one recovery-code set (ADR 0016).
 */
final class SharedAppAuthentication extends AppAuthentication
{
    /**
     * google2fa-qrcode 4 already returns a data URI; Filament 5.10.2 base64-encodes it a second time,
     * so the set-up modal shows a broken image. Fixed upstream (filamentphp/filament 36f3e87609):
     * delete this override once a release after 5.10.2 is installed.
     */
    public function generateQrCodeDataUri(#[SensitiveParameter] string $secret): string
    {
        /** @var HasAppAuthentication $user */
        $user = Filament::auth()->user();

        $inlineQrCode = $this->google2FA->getQRCodeInline($this->getBrandName(), $this->getHolderName($user), $secret);

        return str_starts_with($inlineQrCode, 'data:')
            ? $inlineQrCode
            : 'data:image/svg+xml;base64,'.base64_encode($inlineQrCode);
    }

    /**
     * @param  array<string>|null  $codes
     */
    public function saveRecoveryCodes(HasAppAuthenticationRecovery $user, #[SensitiveParameter] ?array $codes): void
    {
        $user->saveAppAuthenticationRecoveryCodes($codes);
    }

    public function verifyRecoveryCode(#[SensitiveParameter] string $recoveryCode, ?HasAppAuthenticationRecovery $user = null): bool
    {
        $user ??= Filament::auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return DB::transaction(function () use ($user, $recoveryCode): bool {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->two_factor_recovery_codes === null) {
                return false;
            }

            foreach ($locked->recoveryCodes() as $code) {
                if (hash_equals($code, $recoveryCode)) {
                    $locked->replaceRecoveryCode($code);

                    return true;
                }
            }

            return false;
        });
    }
}
