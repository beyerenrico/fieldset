# 0061: The update check is off by default
- Status: accepted
- Date: 2026-10-17

## Context
E15 and §2.4 principle 5: self-hosted means private, and nothing phones home by default. Operators still want to
learn about new releases in `/platform` (R47, R48).

## Decision
- The update check is off by default. `fsa config set` turns it on or off (R32).
- When on, a daily scheduled job sends one plain GET for the release manifest URL. That is `FSA_RELEASE_URL`, or
  GitHub Releases by default (ADR 0050).
  - It sends no instance identifier and no telemetry, and nothing else leaves the server (§10).
  - The `/platform` instance page shows "update available" with the notes link and the `fsa upgrade` command
    (plan U20).
- When off, the app makes no request at all. A test asserts this with `Http::assertNothingSent`.
- The manifest is treated as untrusted display data. The notes URL becomes a link only when it is https. The
  check never pulls or upgrades anything. `fsa upgrade` verifies the signature on its own (ADR 0060).
- `fsa config show` and the docs name what the check contacts (§10, Privacy). The tunnel and ACME are listed in
  the same place.

## Alternatives considered
- **On by default:** contradicts principle 5. Tunnel and update check are opt-in third-party contacts by design.
- **Richer telemetry (version, instance size):** useful for planning, but it would phone home. Out of scope.

## Consequences
- Operators who leave it off learn about releases elsewhere, for example by watching the GitHub repository. The
  agent guide names `fsa upgrade` as the way to check and move.
- With the check on, the release host (or mirror) sees the instance's IP address once a day.
- Feature tests cover the off case (no request), the on case with a newer manifest, and a non-https notes URL.
