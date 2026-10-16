# QUESTIONS for later human review (never blocks work)

Format: question · chosen option · impact · how to reverse.

1. App name/domain unresolved · used `form-schema-app` / `localhost` (ADR 0001) · docs, meta-schema
   `$id`, defaults · search-replace + republish meta-schema `$id` before 1.0.
2. D1 AGPL/MIT split without CLA or DCO · no CLA; DCO recommended in CONTRIBUTING · relicensing later
   needs all contributors' consent · add CLA bot before accepting outside contributions.
3. D6 no e-invoice output · generic invoice only (ADR 0008) · customers above €800k turnover need
   another tool from 2027 · implement B4 (ZUGFeRD) after tax-advisor review.
4. Risky default: D4 offline UX only in M6 · API offline-ready from M2 (ADR 0006) · field use before
   M6 needs connectivity · none needed if M6 ships before 1.0.
5. Filament MFA challenge failures are not audited (Filament fires no event) · accepted for now ·
   failed admin TOTP attempts leave no trace (Fortify ones do) · add a Filament challenge subclass or
   upstream event when available.
6. Self-service registration default · off (`APP_ALLOW_REGISTRATION=false`) after security review ·
   public hosted instance must enable it · set env to true.
7. Unconfirmed Fortify 2FA secret is replaced if the user then enrols in Filament · accepted (the
   unconfirmed secret was never active) · none.
8. Builder v1 page weight · the Filament Builder renders every block schema (incl. nested block pickers
   and logic editors); the service-report editor HTML is ~4 MB · acceptable for v1, Builder v2 (M7) is
   the planned replacement · if needed earlier: lazy-load block bodies or collapse nested pickers.
