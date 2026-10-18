# 0079: Edge and runtime hardening (M10 gate)
- Status: accepted
- Date: 2026-10-18
- Refines: ADR 0040 (trusted proxies), ADR 0065 (entrypoint, wrapper), ADR 0070 (shim), ADR 0074 (runtime URL),
  ADR 0075 (edge), ADR 0076 (stack file)

## Context
The M10 gate review found eight problems in the edge, the runtime URL, the stack file and the launchers. Each was
re-checked and fixed test-first; this ADR records the decisions that were not obvious.

Facts this relies on (checked in `vendor/` or by running the pinned images on 2026-10-18 unless marked):
- `UrlGenerator::hasCorrectSignature()` builds the URL it verifies from `$request->getSchemeAndHttpHost()`, the
  base URL and the path. Symfony's `getPort()` takes a trusted `X-Forwarded-Port`, else the port of a trusted
  `X-Forwarded-Host`, else the `Host` header's, else the scheme's default.
- Caddy 2.11's `reverse_proxy` sets `X-Forwarded-For`, `-Proto` and `-Host` itself (it trusts no incoming ones) and
  passes every other request header on, `X-Forwarded-Port` included (gate finding; `caddy adapt` of the rendered
  file now shows the deletes). Traefik sets `X-Forwarded-Port` (Traefik's documentation; not re-checked here).
- `migrate` is `Isolatable`: with `--isolated[=code]` it asks the bound `CommandMutex` (default `CacheCommandMutex`,
  a cache lock that expires after one hour) and, when the lock is taken, prints "The [migrate] command is already
  running." and exits with the given code (0 by default).
- A failed or interrupted `CREATE INDEX CONCURRENTLY` leaves the index in `pg_index` with `indisvalid = false`;
  `IF NOT EXISTS` then skips it (reproduced in `tests/Feature/Database/MigrationSafetyTest.php`).

## Decision
**In-request URL root (amends ADR 0074, step 5).** The root of a request on an allowed host is the request's own
scheme and host, plus the port of the URL configured for that host: the local URL (every loopback spelling), the
tunnel (443), else the instance URL, which extra hosts follow as they already do for scheme and path.
- Scheme: a plain-http request on an https host (a recreated proxy that is no longer trusted, a host proxy without
  `X-Forwarded-Proto`) used to get an https root while Laravel verifies signatures against the request's http
  scheme, so every URL signed in that request failed. The root now keeps the request's scheme. Nothing marks such
  a request secure: the HTTPS expectation (HSTS, Secure and `__Host-` cookies, `upgrade-insecure-requests`) still
  follows the host, so a browser that is really on https stays there and lifts the same-origin http links and
  redirects. There is no app-side http→https redirect, so no loop: the redirect target is served (tested).
- Port: the configured URL's port when the schemes agree (left out when it is the default); otherwise only when the
  request addressed that very port, so a plain-http request on `https://host:8443` still verifies its signatures.
  A port from `X-Forwarded-Port` or the `Host` header never reaches a link (password-reset mails were the risk).
- `X-Forwarded-Port` stays in `trustProxies` (Traefik and host proxies set it legitimately; checkout installs
  without a runtime keep their behaviour); the edge strips it, and on `fsa` instances its value no longer matters.

**Edge (amends ADR 0075).** The app's `reverse_proxy` and the `forward_auth` subrequest send
`header_up -X-Forwarded-Port`. The Mailpit `reverse_proxy` sends `header_up -Cookie` and `header_up -X-XSRF-TOKEN`:
the operator's MFA'd `/platform` session cookie stays between browser, edge and app; `forward_auth` still receives
it. Verified by the renderer unit tests and by `caddy adapt` of the pinned image (EdgeConfigJourneyTest); Mailpit
has no echo endpoint, so J2 does not observe the header on Mailpit's side. J2 checks end to end that a forged
`X-Forwarded-Port` or `Host` port never shows up in the rendered login page.

**Edge label.** `app.form-schema.edge-config` is the SHA-256 of the Caddyfile and, when a custom ACME CA root is
configured, of `"\nacme-ca-root sha256 <root's SHA-256 from the state>"` appended to it
(`EdgeService::configHash()`). The Caddyfile only names the root's path, so a root-only change never recreated the
edge. Without a root the label is the Caddyfile's plain hash, so existing edges are not recreated by the upgrade.

**Migrations on app start (amends ADR 0065 and ADR 0076).** The entrypoint runs
`php artisan migrate --force --isolated=75 --no-interaction`; on exit 75 it waits 5 s and tries again, up to 60
times (the stack file's 300 s start period), then exits 75 so the restart policy takes over. The app never starts
while another container is migrating.
- `MigrationMutex` (bound as `CommandMutex`) gives `migrate` a session-level Postgres advisory lock
  (`pg_try_advisory_lock(hashtext('form-schema-app:migrate'))`) on the default connection; other isolated commands
  and non-Postgres connections keep the framework's cache mutex. The lock dies with its session, so a container
  killed mid-migration does not block the next start for an hour as the cache lock would, and it needs no
  `cache_locks` table (the database cache store, the framework default, has none on a fresh database). Every role
  that sets `RUN_MIGRATIONS` (the stack file's app and the checkout's `compose.yaml` app) migrates on Postgres.
- `ConcurrentIndex::create($name, $definition)` drops an INVALID index of that name (current schema) before
  `CREATE INDEX CONCURRENTLY IF NOT EXISTS`. The four migrations that build indexes concurrently use it (up and
  down). Upgrade migrations still never use CONCURRENTLY (plan U18); this only makes the existing ones rerunnable.

**Launchers (amends ADR 0070 and the wrapper part of ADR 0065).** The image wrapper (`value_flags`) and the shim
(`fsa_value_flags`, used by `fsa_find_command` and `fsa_run`) list every value-taking option of the `fsa:*`
commands (required or optional value: Symfony gives an optional one the next argument too) plus install's
`--version` alias. A flag from the list before the command keeps its value, so `fsa --behind-proxy traefik install`
runs `fsa:install --behind-proxy traefik`; `--flag=value` always worked and the `unknown_command` hint now says so.
`FsaCommandFlags` (tests) derives the list from the registered commands; a test per script keeps it in step.

**Evidence.** `InstanceJourney::evidence()` runs every transcript through `EvidenceRedactor`: cookie values in
`Set-Cookie`/`Cookie` (maps, lists, raw header lines), `X-XSRF-TOKEN`/`X-CSRF-TOKEN` values, CSP nonces, sign-in
links, `signature`/`token` query parameters and fields named `*_token`, `*-token`, `*secret`, `*password`
(`fsa config show`'s own `[redacted]` stays visible). J2's evidence was regenerated.

**Stack file header.** It now says `docker compose -f compose.stack.yaml exec app php artisan app:install …`, run in
the file's directory with the same variables set (Compose interpolates the file for every command), or the
`php artisan app:install …` part in the app container's console in Portainer or Coolify.

## Alternatives considered
- **Keep the https root and verify signatures against it** (a custom `signed` middleware or URL generator): would
  make links nicer behind a misconfigured proxy, but replaces framework security code and hides the misconfiguration.
- **Treat the plain-http request as http everywhere** (no HSTS, plain cookie name): the session cookie name would
  flip between `__Host-…` and plain with the proxy's state, signing users out and risking login loops.
- **Drop `HEADER_X_FORWARDED_PORT` from `trustProxies`:** Traefik sets it; a Traefik on a non-default entry point
  still works through `X-Forwarded-Host`, but checkout installs whose host proxy sends only `X-Forwarded-Port` would
  lose their port. The port rule makes the header harmless where it matters.
- **Plain `--isolated` (cache lock, exit 0 when taken):** a second replica would start serving while the first
  migrates, and a killed migrator would block or skip migrations for an hour.
- **Blocking `pg_advisory_lock`:** waits without bound and without a log line; the entrypoint loop is visible.

## Consequences
- Behind a stale proxy, links in pages and mails sent from that request are `http://…`; browsers on https upgrade
  them (HSTS, `upgrade-insecure-requests`), and signed links verify as long as the proxy stays in that state. The
  remedy is unchanged: `fsa config set trusted-proxy auto` (ADR 0076/0077).
- Links rendered for a request on a non-configured port (`localhost:9999` with local URL `localhost:8080`) point
  to the configured port.
- Concurrent app starts serialize on the database; a stuck migrator (alive but hung) keeps others waiting up to 5
  minutes per attempt cycle, with a log line, until it is stopped.
- The shim and wrapper lists must grow with every new value option; the sync tests fail until they do.
