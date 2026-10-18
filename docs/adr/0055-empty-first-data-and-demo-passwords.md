# 0055: Empty first data, demo data on request, random demo passwords beyond loopback
- Status: accepted; the `open_url` hand-over is superseded by ADR 0070; amended 2026-10-18 by ADR 0081 (what
  `fsa demo` is, the public default password) and ADR 0082 (the local CA refuses instead of rotating)
- Date: 2026-10-17

## Context
E9. Demo users have the password `password`, and production skips the demo seed (`DemoSeeder.php:30-42`,
`DatabaseSeeder.php:12-19`). `app:install` takes `--organization --name --email --password --locale` and does
nothing once an organization exists (Appendix C). A public demo with a known password is an open door.

## Decision
- A default install creates an empty instance with the first organization and owner from flags (`--org`,
  `--owner-email`, `--owner-name`, `--locale`). Only `--org` and `--owner-email` are required: `--owner-name`
  defaults to the part of the email before the @, and `--locale` to `de` (as built, ADR 0072).
  - On a TTY, missing required values are prompted.
  - Under `--yes` or without a TTY, a missing required value is an error (R26).
- The owner also gets the platform operator role through `GrantPlatformAdmin` (ADR 0041), so `/platform` requires
  MFA.
- `--demo` adds the demo organization, forms, workflow and users. `DemoSeeder` runs in a one-off container without
  the socket, because `DatabaseSeeder` skips it in production (plan U13).
- Reachability rule (R29, AE2): an instance counts as reachable beyond loopback in domain mode, proxy mode, local
  mode with the local CA, and local mode with a tunnel. In those cases the demo users get random passwords, listed
  once under `secrets`. Only a loopback-only local instance keeps `password`.
- Starting a tunnel or the local-CA edge on an existing demo first rotates the demo passwords and revokes their
  sessions and tokens. `fsa tunnel start` refuses while any user still has `password`.
  `fsa demo --rotate-passwords` rotates them on demand.
- `fsa demo` is a local install with demo data and capture mail. It returns `open_url` when a desktop session
  exists (R30). (Superseded 2026-10-18 by ADR 0070: the URL goes to the shim through `FSA_OPEN_URL_FILE`, which
  opens it; it is not returned as `open_url`.)

## Alternatives considered
- **Always install demo data:** a production instance would need cleaning up, and a reachable demo with
  `password` would be exposed.

## Consequences
- `DemoSeeder` gets an injectable password source.
- Proxy mode always counts as reachable, even behind an internal proxy, because `fsa` cannot tell the difference.
- `fsa reset --demo` follows the same rule (plan U23).
- Demo passwords are one-time secrets in the sense of R50: shown once, marked secret in JSON, never logged.

## Amendment 2026-10-18: `fsa demo`, the default password and the local CA (ADR 0081, ADR 0082)
- Reverses "`fsa demo` is a local install with demo data and capture mail": it works only on an installed instance
  (exit 2 `not_installed`), since the shim exists only once an instance does. A new local demo is `install.sh --demo`
  (`fsa install --demo`), which needs `--org` and `--owner-email` like any install.
- The loopback default password `password` is public: reported as `data.demo.default_password`, never registered
  with the redactor. Random passwords leave once, as the secrets `demo_password:<email>` (`secrets` or
  `--secrets-file`).
- "Starting a tunnel or the local-CA edge on an existing demo first rotates the demo passwords" holds for
  `fsa tunnel start` (also from `fsa install --phone-https tunnel`). `fsa config set phone-https local-ca` has no
  secrets channel, so it refuses instead (exit 3 `default_password_in_use`) until `fsa demo --rotate-passwords` ran.
  Both refuse while an account that is not a demo account has `password`.
