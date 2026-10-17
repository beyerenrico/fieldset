# 0055: Empty first data, demo data on request, random demo passwords beyond loopback
- Status: accepted
- Date: 2026-10-17

## Context
E9. Demo users have the password `password`, and production skips the demo seed (`DemoSeeder.php:30-42`,
`DatabaseSeeder.php:12-19`). `app:install` takes `--organization --name --email --password --locale` and does
nothing once an organization exists (Appendix C). A public demo with a known password is an open door.

## Decision
- A default install creates an empty instance with the first organization and owner from flags (`--org`,
  `--owner-email`, `--owner-name`, `--locale`).
  - On a TTY, missing values are prompted.
  - Under `--yes` or without a TTY, a missing value is an error (R26).
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
  exists (R30).

## Alternatives considered
- **Always install demo data:** a production instance would need cleaning up, and a reachable demo with
  `password` would be exposed.

## Consequences
- `DemoSeeder` gets an injectable password source.
- Proxy mode always counts as reachable, even behind an internal proxy, because `fsa` cannot tell the difference.
- `fsa reset --demo` follows the same rule (plan U23).
- Demo passwords are one-time secrets in the sense of R50: shown once, marked secret in JSON, never logged.
