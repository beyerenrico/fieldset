# 0077: fsa config: show, set with apply, verify and revert
- Status: accepted
- Date: 2026-10-18
- Refines: ADR 0066 (supervisor, journal), ADR 0069 (renderer), ADR 0072 (install), ADR 0074 (runtime), ADR 0075
  (edge, domain mode), ADR 0076 (proxy mode)

## Context
Plan unit U11 (PLAN M10-T04): R31, R32, R54, KTD6, KTD26. After an install, settings change only through `fsa`
(nobody edits the generated files, KTD6). A change must restart only what it must, be idempotent, and leave the
instance as it was when it fails. BUILD_PROMPT_V2 §7 names `config show` / `config set` with flags "per setting";
R32 lists the settings. A URL change moves the SAML entity IDs, ACS URLs and OIDC redirect URIs of organizations with
SSO and breaks links already sent (R21).

Facts this relies on (checked 2026-10-18 on Docker Desktop 29.9 / Compose 5.6): `docker compose up` recreates a
service when the *content* of its `env_file` changed and leaves unchanged services running (a two-service project:
only the service whose env file changed got a new container ID); `--remove-orphans` removes the containers of
services no longer in the file.

## Decision
**Syntax.** `fsa config show` and `fsa config set <key> <value>` (one artisan command `fsa:config {action} {key?}
{value?}`; the image's wrapper lists `config`). `--acme-email`, `--acme-staging`, `--acme-ca` and
`--acme-ca-root-file` belong to `config set domain` (as at install, ADR 0075); `config set acme-ca` takes only the
root file; on other keys they are exit 2.

**Keys and appliers** (`App\Domain\Instance\Config\Appliers`, one `SettingApplier` per key, `ConfigKeys`):

| key | modes | value | target | recreated (Compose decides, the result lists it) |
|---|---|---|---|---|
| `domain` | local, proxy, domain | domain name (not an IP) | domain mode, `https://<name>`, edge subnet kept or chosen once, proxy settings cleared, ACME options merged | app, queue, scheduler, mailpit; edge added |
| `url` | proxy | http(s) origin, normalized | `--url` origin (APP_URL, runtime, proxy labels) | app, queue, scheduler |
| `port` | local, proxy `port` | 1–65535, not the catcher port | loopback port | app (+ queue, scheduler in local mode for APP_URL) |
| `trusted-proxy` | proxy | container, address or `auto` (port setup: `auto` only) | re-resolved through the engine (ADR 0076): `auto` follows the recorded container, else the only proxy-image container; port setup re-reads the gateway | app, queue, scheduler |
| `mode` | domain, proxy | `local` | local mode; domain/proxy settings cleared; remembered ports, or the next free ones (warning) | app roles, mailpit; edge removed |
| `acme-email` | domain | e-mail or `none` | ACME contact | edge |
| `acme-ca` | domain | `production`, `staging` or https URL | directory (production stored as null); a Let's Encrypt directory drops a custom root | edge |

Refused with exit 2: `mode domain` (use `config set domain`), `mode proxy` (needs network and URL: install only),
keys outside their modes (with the key to use), and R61's phone-HTTPS exclusion (read from the `phone_https`
setting that U14 will write). Keys of later pre-releases are known and refused with exit 2 `setting_not_available`
naming the release: `mail` and `operator-email` (2.0.0-beta.1), `backup-schedule`, `backup-retention`,
`backup-encryption` (2.0.0-rc.1), `update-check` (2.0.0). Anything else is `unknown_setting` with the key list.

**Flow** (`ConfigSetter`, for every key):
1. *plan* (`SettingApplier::plan`): validate (InputGrammar via `ConfigValues`, exit 2) and build the target state. The
   same value again is `changed: false` with **no Docker call** and no journal (`ConfigChange::unchanged()`
   compares mode, URL and non-null settings). The only plans that ask the engine are `trusted-proxy` names and
   `auto` (read-only inspect), because only the engine knows the address; a repeated address needs no call.
2. *confirm* when the instance URL changes (`domain`, `url`, `port` in local mode, `mode`): the socket-less one-off
   `instance:url-impact --json` lists organizations with an SSO provider (all statuses: a disabled one would fail the
   same way when re-enabled) with what follows the URL (`entity_id`, `acs_url` or `redirect_uri`) and counts links
   already sent with the old address (pending invitations, document links, unused sign-in links, data exports). Only
   known fields pass through. Without `--yes` and without a terminal (or with `--json`) it is exit 6 with the list in
   the message and `data.impact`; in a terminal it is asked; declined is exit 9. An unreadable impact (database down)
   still asks and says so (`known: false`).
3. Under the lock and journal (Supervisor, operation `config`, context: key, value, ACME options with the root's
   SHA-256): *snapshot* (the previous state; the CA root it replaces), a check that no other command changed the
   state while this one waited (exit 9 `state_changed`), *preflight* (exit 3; DNS and ports 80/443 for a new domain
   via the install's checks, free loopback ports, the edge subnet, and a pull of images the target needs and the
   engine lacks, e.g. Caddy on a local install). The preflight journals the refined target (chosen ports, subnet).
   The edge subnet is chosen once; an edge network a reverted attempt left behind (`up --remove-orphans` removes
   orphan containers, never networks) keeps its subnet, so the next attempt reuses it.
4. *apply* (`ConfigRollout`): save the target state with a `config_changed` history entry, render runtime, env files,
   Caddyfile and compose file from it (KTD6, same emulation factor as the current file), then `compose up --wait
   --remove-orphans` for the whole project. `affected()` diffs the renders of both states for the report.
5. *verify* (`ConfigVerifier`): the install's smoke test (app `/up` and `/admin/login`, Gotenberg; in domain mode a
   certificate from the configured CA for the domain and the HTTP → HTTPS redirect) plus, in proxy mode, `/up` with
   the new URL's Host, which only passes once the app trusts it.
6. *audit*: `instance:config-changed --key --old --new [--old-url --new-url] --actor-uid` in a socket-less one-off
   writes `instance.config_changed` (actor `cli`, host UID; properties `key`, `from`, `to`, `url`; DE/EN labels).
   Values pass the command's redactor first. A failed audit, also one whose one-off cannot start, is a warning
   (`audit_failed`): the verified change stays and the state history records it.

**Revert.** Any failure in apply or verify starts the *revert* phase: the snapshot state is saved and rendered again
(the ACME CA root file follows that state: removed when it trusts none, else restored from the snapshot when its
SHA-256 differs), `compose up --wait --remove-orphans` brings the old services back (and
removes an added edge), and the old configuration is verified the same way. Then `OperationRun::markReverted()`
makes the run count as unchanged, so the journal is archived (outcome `failed`), and the result is **exit 4**,
`changed: false`, `data.reverted: true`, the original error code (`certificate_failed`, `services_unhealthy`,
`smoke_failed`, `url_check_failed`) with a config-specific hint, `data.url`/`data.mode` the effective (old) values and
`data.attempted` the target. If the revert fails too, every service is stopped (nothing runs half-configured) and the
result is **exit 8** `revert_failed`, `changed: true`, with both errors and `data.recovery`; the journal stays open.

**Resume.** An unfinished `config` journal accepts only the same key, value and options (`ConfigRequest::sameAs`;
anything else is exit 9 naming the command to repeat). The run starts over from the journaled snapshot (apply,
verify, revert and audit run again: `OperationRun::phase(always: true)`), replays the recorded preflight target
instead of checking again (the first attempt may already hold the new port or network), skips the no-op shortcut,
and brings every service up. `fsa status` and exit 9 name `fsa config set <key> <value>` as the command to repeat. A different
unfinished operation is exit 9 (`OperationUnfinished`), a live lock holder exit 9 (`instance_locked`).

**Exit codes.** 0 (also `changed: false`), 2 invalid key/value/option or mode, 3 preflight, 4 apply/verify failed and
reverted, 6 confirmation required, 8 revert failed and instance stopped, 9 lock held, unfinished operation, state
changed meanwhile or declined, 10 Docker unavailable (CLI missing, daemon unreachable or too old; ADR 0066),
1 internal.

**`config show`.** Read from the state only (no Docker call): mode, URL, version, ports, domain and ACME inputs (with
`staging`), edge subnet, proxy setup, effective trusted proxies (the edge address in domain mode), extra hosts, mail
as it stands (capture/SMTP, catcher URL, sender), size, database. `secrets` lists the names present in the env files
with `[redacted]`. `keys` lists every key with its value, `changes_url`, or `arrives_with`. `outbound_contacts`
(§10): `default` says nothing calls out without these features; `acme` (active in domain mode: the directory),
`smtp` (active with SMTP: the server), `upgrades` (inactive until `fsa upgrade`: the image registries of the pins and
the release URL), `update_check` (off; 2.0.0). Identity providers and SMTP servers that organization admins configure
are named as contacted as configured.

## Alternatives considered
- **Recreating an explicit service list** computed from the diff: same result, but a stopped service (after exit 8)
  would stay stopped on resume, and the list could drift from what Compose hashes; `up` on the whole project lets
  Compose decide and starts anything stopped.
- **Snapshotting the rendered files** (env files hold secrets) instead of the state: the state holds no secret and
  renders the same files again (KTD6), so the journal stays secret-free.
- **Confirmation only for `domain`** (the plan's wording): `url`, local `port` and `mode` move the same SSO URLs and
  links; one rule (any URL change) is simpler to document for agents.
- **Reverting through a new journal**: one journal per change, with the revert as its phase, keeps resume and
  history readable.

## Consequences
- Deviations from the plan: `operator-email` (plan U11 approach) is deferred and refused as `setting_not_available`
  (PLAN M10-T04 does not list it; it needs an app-side grant flow); `acme-email` and `acme-ca` were added (R32 lists
  them and they reuse the domain applier's verification). The "test mail" verification arrives with `config set mail`
  (U12).
- `fsa install` on an installed instance now names `fsa config set` / `config show` in its `use_config_set` hint.
- The edge network stays after `mode local` (`up --remove-orphans` removes orphan containers, never networks);
  uninstall removes it by label, and a later `config set domain` reuses it with the same subnet.
- A change interrupted by a killed process leaves the journal open; the same command resumes from the snapshot.
- Known gap: ADR 0076 asked for a doctor check that warns when the recorded proxy address no longer belongs to
  `proxy_container`; it was not built in U11 (QUESTIONS 35, open). After a recreated proxy, `fsa config set
  trusted-proxy auto` re-resolves the address.
- Unverified until the human checklist: a real Let's Encrypt switch from staging to production with `acme-ca`.
