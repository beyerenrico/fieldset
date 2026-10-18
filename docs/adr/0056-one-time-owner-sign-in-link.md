# 0056: One-time sign-in links instead of printed passwords
- Status: accepted; the `open_url` delivery is superseded by ADR 0070; `--owner-password-file` is planned
- Date: 2026-10-17

## Context
E10. A printed password ends up in terminal logs and agent transcripts (§2.4, principle 4). The app already has
the Fortify password-reset broker and the invitation flow, with expiry, single use, throttling and SSO
enforcement. The plan (KTD21, U6) builds the links on top of them.

## Decision
- **Owner's first link:** install ends with a one-time sign-in link, not a password (R12). For a new owner it is
  a password-reset broker token: 60 minutes, single use, with the existing throttle and `PasswordAuthGate`/SSO
  enforcement unchanged (R27). The landing page leads to setting a password and offers MFA. It has DE/EN states
  for valid, expired, used, rate-limited and invalid tokens, and each state names `fsa login-link <email>`.
- **`fsa login-link <email>`** for an existing admin (R28, KTD21):
  - single use, at most **15 minutes**, stored hashed and rate-limited;
  - a newer link invalidates older ones for the same user.
  - This is deliberately shorter than E10's 60 minutes, which applies to the owner's first link only.
- The token travels in the URL fragment, and the landing page POSTs it, so it never reaches access logs or a logged
  query string.
- A link creates only an ordinary `/admin` session with the normal MFA challenge. It never opens a `/platform`
  session and never impersonates.
- Audit records issuance with actor "cli" and the host UID, and use with time and IP. `fsa status` lists unused
  links.
- Delivery (KTD3): in the JSON `secrets` array (marked secret), in a `--secrets-file` (0600), or through the shim's
  `open_url`, so an agent never has to see the link. (Superseded 2026-10-18 by ADR 0070: the hand-over is the
  `FSA_OPEN_URL_FILE` file, which the shim reads, opens and deletes; nothing is parsed from stdout.)
- Automation: `--owner-password-file` sets a password instead of issuing a link (R27; **planned**, not built:
  `fsa install` does not accept it yet and always runs `app:install --owner-link`, ADR 0072). `app:install` gains
  `--password-file`; `--password` stays but prints a deprecation warning. A secret passed as a flag value exits 2
  (R50, AE9).

## Alternatives considered
- **Generated password printed once:** a reusable secret ends up in logs and transcripts.

## Consequences
- Horizon moves from `PLATFORM_ADMIN_EMAILS` to the operator check plus a `/platform` session, which amends ADR
  0041 (plan U6).
- For a user whose domain enforces SSO, the existing gate refuses the link, and that user signs in through SSO.
- An owner who misses the 60 minutes runs `fsa login-link`. The recovery text says so in both languages.

## Implementation (2026-10-17)
See ADR 0068: one `login_links` table for both kinds (hashed token, plain `DELETE` on restore), the `/login-link`
landing page and its states (plus "SSO required"), the hand-off through Filament's reset page and the admin
login's MFA challenge, `AuditLogger::cli()`, and the app-side commands `app:install --owner-link`,
`identity:login-link` and `identity:login-links` that `fsa` runs as socket-less one-offs.
