# 0016: One TOTP secret and one recovery-code set for Filament MFA and Fortify 2FA
- Status: accepted
- Date: 2026-10-16

## Context
§9.3 requires Filament's MFA and Fortify's 2FA to share one secret so users never enrol twice.
Verified contracts (vendor source, 2026-10-16): Fortify stores `two_factor_secret` encrypted with
the app encrypter, `two_factor_recovery_codes` as encrypted JSON of plain codes, and
`two_factor_confirmed_at`. Filament delegates storage to `HasAppAuthentication` /
`HasAppAuthenticationRecovery` on the user, but its `AppAuthentication` bcrypt-hashes recovery codes
before saving and verifies with `Hash::check` — incompatible with Fortify's plain-code compare.

## Decision
- The User implements Filament's contracts on top of Fortify's columns: secret is read only when
  `two_factor_confirmed_at` is set; saving a secret sets `two_factor_confirmed_at` (Filament saves only
  after code confirmation); clearing it clears all three columns.
- `SharedAppAuthentication` (subclass of Filament `AppAuthentication`) stores recovery codes in
  Fortify's format and verifies with `hash_equals` under a row lock.
- A used recovery code is removed (not replaced) in both flows (`User::replaceRecoveryCode`).
- Enabling/disabling is audited once via a User `updated` hook on `two_factor_confirmed_at`.

## Consequences
Recovery codes are encrypted at rest rather than hashed (Fortify's model). Covered by
`tests/Feature/Auth/SharedMfaTest.php`. A Filament upgrade that changes `AppAuthentication` may need
the subclass adjusted.
