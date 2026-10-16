# 0019: Fortify headless endpoints under /auth; Filament keeps its own login
- Status: accepted
- Date: 2026-10-16

## Decision
Fortify runs with `views => false`, prefix `auth`, features: reset passwords, email verification,
profile, password update, 2FA (confirm + password confirmation). Registration and passkeys are
disabled (organizations register in Filament; workers are invited). Password reset mails link to the
Field App (`/app/reset-password`). Login rejects deactivated users (`Fortify::authenticateUsing`).
The admin panel uses Filament login, password reset, email verification and MFA pages.

## Consequences
Two login UIs share one user table, one session and one TOTP secret (ADR 0016).
