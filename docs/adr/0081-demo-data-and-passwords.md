# 0081: Demo data and demo passwords: `--demo`, `fsa demo`, rotation and the check for U14
- Status: accepted; amended 2026-10-18 by ADR 0082 (`--check` reports organizations; how U14 uses the check)
- Date: 2026-10-18
- Amends: ADR 0055 (what `fsa demo` is, the default password is no secret), ADR 0072 (install phases, `--demo`),
  ADR 0070 (the wrapper's command list gains `demo`)

## Context
Plan unit U13 (PLAN M11-T02): BUILD_PROMPT_V2 E9, §7 (`demo`, `--demo`), R29, R30, AE2, F2, J5 (demo part), KTD25.
Demo users had the password `password` (`DemoSeeder`), and `DatabaseSeeder` skips the demo seed in production. ADR 0055
fixed the rule: `password` only for a loopback-only local instance, random passwords listed once whenever the
instance is reachable beyond loopback. U14 (tunnel, local CA) must refuse while any account still has `password`, and
rotate first; it needs an app-side check for that. The process holding the Docker socket never opens a database
connection (KTD25), so every database step is a socket-less one-off (`compose run --rm --no-deps app`, ADR 0068).

## Decision
**Password source (app side, `App\Domain\Identity\Demo`).** `DemoPasswordSource` (`#[Bind(DefaultDemoPassword)]`)
gives a new demo account its password; the default stays `password`, so development, tests and the E2E suite are
unchanged (`DemoSeeder::PASSWORD` remains). `RandomDemoPasswords` hands out 24 letters and digits from the CSPRNG
(`Str::password`, `random_int`) and remembers them for the one-time output. `DemoAccounts` is the single table of demo
accounts (owner plus eight personas with roles and groups); the seeder builds from it, rotation acts on exactly it.
The seeder asks the source only for accounts it creates: an account that existed keeps its password and gets none
listed.

**App-side one-offs (the contract fsa and U14 use).**
- `app:demo-seed --passwords=default|random [--actor-uid=N] --json`: runs `DemoSeeder` (unguarded, as `db:seed`) in
  one transaction; the queue connection's `after_commit` is switched on first, so jobs of a rolled-back seeding never
  run. Audited once as `instance.demo_seeded` (actor `cli`, host UID, `mode`). `data`: `organization {id, name, slug}`,
  `users [{email, name, roles}]`, `login_url`, `seeded`, `passwords` (`default`|`random`), and `default_password`
  (`password`) only for `default`. Random passwords are secrets `demo_password:<email>` (no expiry). An existing demo
  organization (also a deleted one) changes nothing: `seeded: false`, `passwords: null`, `default_password_users` (demo
  accounts still on `password`), `deleted`, warning `already_seeded`.
- `app:demo-passwords --check --json` (read-only; **U14 refuses tunnel and local CA while `data.count > 0`**):
  `data.checked` (accounts with a known password), `data.count`, `data.users [{email, demo}]`. Every account with a
  password and `password_known = true` is hashed once against `password`, demo or not; accounts whose password nobody
  knows (unused owner link or invitation) cannot match and are skipped. The cost is one hash per account
  (`BCRYPT_ROUNDS`, 12 in the image): fine for demo-grade instances, minutes for tens of thousands of accounts.
- `app:demo-passwords --rotate [--actor-uid=N] --json`: every existing demo account (any status) gets a random
  password (`password_known` true), and its database sessions, remember-me token and Sanctum tokens are revoked in one
  transaction; devices are not wiped (their data is the demo's own), and unused sign-in links or reset tokens stay
  (they were handed only to the operator). One audit entry per account, `instance.demo_password_rotated` (properties
  `email`, `sessions_ended`, `api_access_revoked`: key names that the audit redactor's `password|token` pattern leaves
  readable), instead of the generic `auth.password_changed`. `data`: `users` (emails), `sessions_ended`,
  `tokens_revoked`; secrets `demo_password:<email>`. No demo accounts: `changed: false`, warning `no_demo_users`.
- fsa side: `AppCommands::demoSeed()`, `rotateDemoPasswords()`, `demoPasswordCheck()` (U14 calls the last one and,
  before starting, `DemoData::rotation()` or `rotateDemoPasswords()`).

**Reachability (ops side, `Demo\DemoPasswords::exposure`).** Reasons, any of which makes the passwords random:
`domain_mode`, `proxy_mode` (also behind an internal proxy: fsa cannot tell), `local_ca` / `tunnel` from the state
setting `phone_https` (`local-ca`, `tunnel`; another value counts as `phone_https`), `tunnel` when `runtime.json`
records a tunnel host (also an expired one: the old host was public), and `runtime_unreadable` when the runtime file
exists but cannot be parsed (fail safe). U14 must record phone HTTPS as `phone_https` or adapt `exposure()`.

**Output (R50).** Random passwords leave only as secrets `demo_password:<email>` (envelope `secrets`, or the 0600
`--secrets-file` together with the sign-in link), never in data, warnings, the journal (phases keep `seeded`,
`rotated`, `passwords`, the number of users), the state history (mode only) or progress. The documented `password` is
public and is reported as `data.demo.default_password`; it is never registered with the redactor, which would blank
the word "password" in every message (`--rotate-passwords`, hints). `data.demo`: `organization`, `seeded`,
`passwords` (`default`|`random`|null), `default_password`, `exposure`, `users`, `login_url`, `delivered`
(`data`|`secrets`|`secrets_file`|null), plus `rotated`, `sessions_ended`, `tokens_revoked` after a rotation and
`default_password_users` for an existing demo.

**`fsa install --demo`.** A `demo` phase after `start` and before `smoke` (not right after `app_install`): the seeding
then runs against the same running stack as `fsa demo` does, the queue picks up its jobs after the commit, and
Gotenberg is up. The phase (and `demo_rotate`) run again on a resume, because the one-offs are idempotent and an
interrupted attempt's random passwords exist only in its memory: an existing demo whose passwords were never shown
gets new ones (`demo_passwords_reissued`). `--demo` is journaled as `demo: 1` and may be added on a resume.
The state history records `demo_seeded {passwords}` (and `demo_passwords_rotated`), so a repeated install with
`--demo` on an instance with demo data is `changed: false` (AE3), and `--demo` on one without is exit 2
`use_config_set` (`data.flags [{flag: --demo, key: null}]`, hint `fsa demo`).

**`fsa demo` (§7, R30, F2).** It works on an installed instance: the shim exists only once an instance does, so a new
local demo is `install.sh --demo` (`fsa install --demo`), which needs `--org` and `--owner-email` like any install.
Under the instance lock with a journal (operation `demo`, phases `demo`, `demo_rotate`): seeds (or `changed: false`),
rotates at once when the instance is reachable beyond loopback and demo accounts still have `password` (warning
`demo_passwords_rotated`, e.g. after `config set domain`), warns `demo_passwords_not_shown` for an existing demo with
random passwords, and hands out a 15-minute sign-in link for `owner@demo.test` under the delivery policy of ADR 0070
(browser only with `--open` or a person at a desktop terminal; else `secrets` or `--secrets-file`); a link that cannot
be issued is the warning `sign_in_link_unavailable`, not a failure. `--rotate-passwords` runs only the rotation (no
link). Exit codes: 2 `not_installed`, 4 `one_off_failed`/`one_off_timeout`/`demo_failed` (retryable, the journal stays
open and the next run resumes), 9 `instance_locked`/`operation_unfinished`. `fsa demo` has no `--phone-https`: phone
HTTPS is `fsa install --phone-https` and `fsa tunnel start` (U14).

## Alternatives considered
- **`fsa demo` as a complete local install:** the host has no `fsa` before install.sh ran; a second bootstrap path
  would duplicate install.sh's verification.
- **The default password under `secrets`:** it is public, and registering it would redact "password" everywhere.
- **Seeding right after `app_install`:** one phase earlier, but against a half-started stack (no queue, no Gotenberg),
  unlike `fsa demo`.
- **Rotating every account that has `password`, demo or not:** silently changing a real user's password is worse than
  refusing; the check reports non-demo accounts with `demo: false` for the operator to fix.

## Consequences
- U14: call `AppCommands::demoPasswordCheck()` before `tunnel start` and the local-CA edge; rotate first on an existing
  demo (`DemoData::rotation()` inside its operation, or `fsa demo --rotate-passwords`), then refuse while `count > 0`
  (non-demo accounts on `password`). Record phone HTTPS as the `phone_https` setting.
- `config set domain|url` (U11) does not rotate yet; `fsa demo` does on its next run. (Superseded: no review question
  was recorded; since the M11 gate `config set` refuses such a change instead, see the amendment of 2026-10-19.)
- The E2E suite and every existing test keep `password` (default binding).

## Amendment 2026-10-18: how U14 uses the check (ADR 0082)
- `app:demo-passwords --check --json` also returns `data.organizations {total, non_demo}` (organizations that are not
  deleted; `non_demo` leaves out the demo organization). `fsa tunnel start` asks for `--yes` while `non_demo > 0`
  (exit 6 without it and without a terminal).
- U14 records phone HTTPS as the `phone_https` setting (`local-ca`, `tunnel`), so `exposure()` stayed unchanged.
- The check runs read-only before the lock. `fsa tunnel start` refuses other accounts on `password` (exit 3) and
  rotates the demo accounts inside its operation (`DemoData::rotation`, passwords listed once). `fsa config set
  phone-https local-ca` rotates nothing: it refuses while any account has `password` (exit 3
  `default_password_in_use`) and names `fsa demo --rotate-passwords`.

## Amendment 2026-10-19: `config set` refuses to widen the reach while an account has `password` (M11 gate)
- Gap: `config set domain` on a local `--demo` instance (accounts on `password`) made `owner@demo.test` / `password`
  sign in on the public domain (R29, AE2, E9). The "review question" the Consequences named was never recorded.
- Rule (`Config\DefaultPasswordGuard`, run by `ConfigSetter` first in the preflight phase, under the lock, before the
  applier's own checks and before anything changes): a change widens the reach when
  `DemoPasswords::exposure()` of the target has a reason the current state lacks. Then `app:demo-passwords --check`
  (`ExposureCheck`) runs, and the change is refused with exit 3 `default_password_in_use` while any account signs in
  with `password`: other accounts first (hint: change their passwords; fsa never changes a real user's password),
  then demo accounts (hint: `fsa demo --rotate-passwords`, which lists the new ones once; `data.users`,
  `data.exposure`). `config set` rotates nothing itself: it cannot hand out passwords, and it should not change data
  as a side effect of a settings change.
- Keys that widen the reach today: `domain` from local or proxy mode (`domain_mode`) and `phone-https local-ca`
  (`local_ca`; the check moved here from `LocalCaPreflight`, so it runs once, now before the ports check). `domain`
  on a domain-mode instance, `mode local`, `port`, `url` and `trusted-proxy` (proxy mode only), `lan-address`, `mail`
  and the ACME keys add no reason and run no check; the app port is always bound to 127.0.0.1. Proxy mode is chosen
  at install only. A key added later is covered as soon as its target state changes the mode or `phone_https`.
- Consistent with QUESTIONS 39: `config set` (domain, local CA) refuses; `fsa tunnel start` rotates the demo
  accounts itself inside its operation, as before.

