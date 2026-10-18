# 0074: Instance runtime state in the app (URL, gate, tunnel policy)
- Status: accepted; amended 2026-10-18 at the M10 gate (in-request URL root, ADR 0079; runtime writer, ADR 0078)
- Date: 2026-10-18

## Context
Plan unit U10 (PLAN M10-T01): R56, R62 (app side), R65 (app side), KTD11, KTD12, KTD24. The app has to take its
public URL, trusted hosts, HTTPS expectation, cookie security and Sanctum stateful domains from the runtime
directory that `fsa` writes (ADR 0069), per request and per job, so a URL change, a tunnel or an upgrade gate needs
no rebuild or restart. Local mode has no edge, so the 503 gate and the tunnel's path restrictions live in the app.
The same code must change nothing on checkout installs, which have no runtime directory.

Facts this relies on (checked in `vendor/` and the repository on 2026-10-18):
- Laravel's global stack is `ValidatePathEncoding, InvokeDeferredCallbacks, TrustHosts, TrustProxies, HandleCors,
  PreventRequestsDuringMaintenance, ValidatePostSize, TrimStrings, ConvertEmptyStringsToNull`, then appended
  middleware (`Foundation\Configuration\Middleware::getGlobalMiddleware`). TrustHosts only sets the patterns. The
  check runs on the first `getHost()` call, and its `SuspiciousOperationException` is a
  `RequestExceptionInterface`, which the handler renders as 400. After one failure Symfony returns `''` instead of
  throwing again, so the first `getHost()` call must not swallow the exception.
- FrankenPHP serves the app with `php_server` in classic mode (docker/app/Caddyfile). Every web request starts with
  fresh PHP state. Horizon workers and `schedule:work` are long-lived processes.
- `config('session.cookie')` is read only by `SessionManager` (Livewire, Filament and Sanctum never read it).

## Decision
**Namespace (KTD24).** `App\Domain\Instance\Runtime` holds the code: `RuntimeDocument` (grammar),
`RuntimeState` + `TunnelState` + `GateState` (values), `RuntimeStateReader` (cache, fallback), `RuntimeUrl`,
`OperationGate`, `TunnelPathPolicy`, `ApplyRuntimeToJob`, `ResolvedRequest`, `HostName`, `RuntimeServiceProvider`.
It imports no ops namespace. `tests/Arch/InstanceBoundaryTest.php` now also treats every directory and top-level
class of `app/Domain/Instance` except `Runtime` as ops, including ones added later and not yet listed, and forbids
Runtime to use `App\Http`, `App\Filament` and `App\Providers`. Each rule was proven by planting a violation once.
Web code (`App\Http`, the platform health check) reaches the instance domain only through Runtime.

**Where the file is.** The renderer already sets `FSA_RUNTIME_PATH=/run/fsa-runtime` for app, queue, scheduler and
the one-offs (ADR 0069). `config/instance.php` maps it to `instance.runtime_path`, so `php artisan optimize` keeps
it. Unset means no state at all, and every caller behaves exactly as before.

**What the app reads from `runtime.json`.**
| field | type | rule |
|---|---|---|
| `schema_version` | string `"1.x"` | major must be 1; unknown keys are ignored |
| `instance_url` | string | http(s), host or IP, optional port, plain path; no userinfo, query, fragment |
| `local_url` | string or null | same rule (local mode only) |
| `extra_hosts` | list of strings, at most 20 | RFC 1123 names or IP addresses; no wildcard, no trailing dot |
| `tunnel.hostname` | string or null | `^[a-z0-9-]+\.trycloudflare\.com$` (D modifier) |
| `tunnel.expires_at` | ISO 8601 or null | required when there is a hostname |
| `tunnel.peer` | IP or null | cloudflared's fixed address, the TCP peer of every tunnel request; required with a hostname |
| `tunnel.admin` | bool, default false | `--tunnel-admin` |
| `gate.raised` | bool, required | a file without it fails closed |
| `gate.operation` | identifier or null | `^[a-z][a-z0-9_-]{0,63}$` |
| `gate.since` | ISO 8601 or null | |
| `gate.smoke_token` | string or null | `^[A-Za-z0-9_-]{32,128}$` |

`RuntimeWriter` (ops side) now writes `tunnel {hostname, expires_at, peer: null, admin: false}`; U14 fills them.
`raiseGate($state, $operation, ?$token)` generates a 64-hex smoke token, or validates a given one against the
app's grammar, stores it under `gate.smoke_token` and returns it. `lowerGate()` writes `smoke_token: null`, and a
re-render keeps the token with the gate. The grammar mirrors `State\InputGrammar`, because Runtime may not import
it. A contract test parses the writer's output with `RuntimeDocument`.
(Amended 2026-10-18 by ADR 0078: a re-render keeps the gate raised, with operation `unknown`, when the current file
exists but cannot be parsed (invalid JSON, or no boolean `gate.raised`), and refuses (UnsafePath) when it exists but
cannot be read; only a missing file starts with the gate lowered. Re-renders and gate changes keep the current
`tunnel` block (`hostname`, `expires_at`, `peer`, `admin`).)

**Reader.** A singleton that never throws.
- It stats the file on every call and caches the reading on path, inode, size and mtime. `fsa` replaces the file by
  rename, so a new inode means new content even within the same second. Nothing is cached in Valkey.
- An unusable file (missing, unreadable, not a regular file, over 64 KiB, invalid JSON, broken grammar) keeps the
  last good state of this process. Without one, the state is APP_URL with the gate counting as raised (fail
  closed). Each new problem is logged once as a warning.
- A `runtime` check on `/platform/health` reports ok, warn (last good in force) or fail (fallback), with the
  public URL, the tunnel, the gate and the problem (see Consequences for when web requests can show it).

**Middleware order.** `ResolveInstanceRuntime` is appended to the global stack before `SecureCookies`. It runs
after TrustHosts and TrustProxies, so the client address is resolved, and before every route middleware (session,
CSRF, auth). HandleCors reads the host before it (an untrusted host gets its 400 there). A middleware placed in
front of the runtime must never catch the `SuspiciousOperationException`, or later `getHost()` calls return `''`.
`TrustedHosts::patterns()` adds the runtime hosts: instance URL, local URL, extra hosts (IPv6 also in brackets)
and the tunnel host, kept while expired. Unknown hosts still get 400.

**Per request.**
1. If REMOTE_ADDR equals `tunnel.peer` (both in canonical form, IPv4-mapped IPv6 as IPv4), the request is a
   tunnel request whatever its headers say. `TunnelPathPolicy::pinHost()` sets Host to the runtime tunnel
   hostname, drops `X-Forwarded-Host`, `-Port`, `-Prefix` and `Forwarded`, and sets `X-Forwarded-Proto: https` plus
   `HTTPS=on`, so the request is https whether or not cloudflared is a trusted proxy. Signed URLs created on the
   tunnel verify there. A peer without a recorded hostname gets 503.
2. `getHost()`: an unknown host gets its 400 here (or already in HandleCors, which reads the host first). The
   resolution of step 5 is computed now and kept on the request, so the 404 and 503 answers below follow the
   host's scheme too: no HSTS or `upgrade-insecure-requests` on loopback http.
3. Tunnel requests (from the peer, or with the tunnel host from any peer): a tunnel that has expired or is missing
   gets 503 with `Retry-After: 300`. A path outside the allow-list gets 404.
4. The gate: see below.
5. `RuntimeUrl` forces the resolved URL root and scheme on the URL generator and adds the allowed hosts to
   `sanctum.stateful`.
   - The URL root is the request's origin (resolved scheme, host, any non-default port) when the host is allowed
     (local URL with every loopback spelling when it is a loopback URL, instance URL, extra hosts, active tunnel).
     Instance and extra hosts also get the instance URL's path. Any other trusted host gets the public URL: the
     active tunnel, else the instance URL.
     (Amended 2026-10-18 by ADR 0079: on an allowed host the root is the request's own scheme and host plus the port
     of the URL configured for that host (local URL, tunnel 443, else the instance URL, which extra hosts follow):
     always when the schemes agree (a default port left out), otherwise only when the request addressed that very
     port. A port from `Host` or `X-Forwarded-Port` never reaches a link. The HTTPS expectation below (HSTS, Secure
     and `__Host-` cookies, `upgrade-insecure-requests`) is unchanged.)
   - HTTPS is expected when the request is secure, or when the URL configured for its host is https (extra and
     other hosts follow the instance URL, the active tunnel host is https). Loopback over plain http (localhost,
     `*.localhost`, 127/8, ::1) never counts.

`SecureTransport::expected()` returns the resolved value, so HSTS, `upgrade-insecure-requests` and Secure cookies
follow the host. Early answers that never reach the runtime middleware keep ADR 0040's rule: secure request, or
APP_URL https. `SecureCookies` sets `session.secure` to the resolved value. When it is HTTPS, the session cookie is
`__Host-<name>` (Secure, Path=/, no Domain; not with a SESSION_DOMAIN or another path). Session stores that are
already open are renamed. A plain-http localhost request in the same browser keeps a plain, non-Secure cookie.

**Tunnel allow-list (R65).** It is matched with `Request::is()` on these path prefixes:
| reachable | patterns |
|---|---|
| always | `app`, `app/*` (Field App shell, service worker, manifest, its files) · `api`, `api/*` (API v1, signed document downloads) · `auth/*` (Fortify sign-in, two-factor, password reset, SSO start and return) · `sanctum/csrf-cookie` · `login-link` · `invitations/*` (signed invitations: set a password, then sign in) · assets `build/*`, `css/*`, `fonts/*`, `js/*`, `favicon.ico`, `robots.txt` |
| only with `tunnel.admin` | `/`, `admin`, `admin/*`, `livewire/*`, `livewire-*`, `filament/*`, `form-preview`, `form-preview/*` |
| never | everything else, among it `platform*`, `horizon*`, `docs/api*`, `_scramble/*`, `up`, `storage/*`, `documents/links/*` |

Static files that exist are served by Caddy before Laravel, so the asset patterns only matter for missing files.

**Gate (R62, app side) and the plan's deferred question.** While `gate.raised` is true, or the reader falls back
(fail closed), every request gets 503 with `Retry-After: 30`. The API renders it as JSON with `server_error`, the
web as the error page with security headers. Two exemptions:
- `/up` when the TCP peer is loopback (127/8 or ::1). The container healthcheck `curl localhost:8080/up` stays
  healthy behind the gate, so Compose does not mark the new app unhealthy during the smoke test. `/up` from
  anywhere else, including the tunnel, stays gated.
- A request carrying the current operation's token in `X-Fsa-Smoke-Token` (`OperationGate::SMOKE_HEADER`). The
  client address must be private (`10/8`, `172.16/12`, `192.168/16`, `127/8`, `::1`, `fc00::/7`) after
  trusted-proxy resolution, so through the edge it is the forwarded client, and the request must not come through
  the tunnel. The token is compared with `hash_equals`.

This answers the R62/KTD19 question as far as the running app goes: the gate is app-level in every mode, the
local healthcheck is exempt, and the smoke test passes with a per-operation token. The smoke test must send a
trusted Host (`localhost` or the instance host); `Host: app:8080` gets the 400 before the token is looked at.
What answers while the app is stopped (KTD19 steps 3–6) stays with U18. So does the plan's rule that queue,
scheduler and backup refuse to start behind a raised gate: it arrives with U18, and nothing implements it yet (the
gate only makes the app answer 503).

**Jobs.** `ApplyRuntimeToJob` listens to `JobProcessing`. Before every job, queued notifications and mails
included, it re-reads the runtime and points the URL root at the public URL. A job started during a tunnel
therefore links to the tunnel, while a localhost request at the same moment links to localhost. A sync job inside
an already resolved request keeps the request's resolution. `config('app.url')` stays the rendered APP_URL: mail
Message-IDs take their host from it and must not change between delivery attempts, and the tunnel hostname stays
out of mail headers.

## Alternatives considered
- **Persisting the last good value outside the process (APCu, a file in `storage/framework`)** would let a web
  request survive a corrupt file after a good one. The plan asks for the last good value in process and fail
  closed otherwise. APCu is not in the image, and a second writable cache file adds another state to keep
  consistent.
- **Inserting the middleware right after TrustProxies with `$middleware->use([...])`** means copying Laravel's
  default stack into the app and keeping it in sync on every framework upgrade. Appending puts only HandleCors,
  the maintenance check, ValidatePostSize and the string trimmers in front of it. HandleCors reads the host (a
  forged `X-Forwarded-Host` from a trusted cloudflared is then seen there, but the runtime pins the host before
  anything uses it for links or routing decisions).
- **Path policy only on the tunnel Host header** would let a forged `X-Forwarded-Host` from cloudflared through
  whenever cloudflared is a trusted proxy. Keying on the TCP peer closes that (KTD12).
- **A separate `FSA_RUNTIME_DIR` variable** (orchestrator's example name): the renderer already sets
  `FSA_RUNTIME_PATH`, which is reused.

## Consequences
- In web requests "this process" is the request. A corrupt or unreadable file therefore fails closed at once
  for web traffic: 503 everywhere except the loopback healthcheck. Workers and the scheduler keep their last good
  value. `/platform/health` is gated in that case, so its warn/fail states show only where a process already holds
  a last good value; in production the operator's signal is the log warning, which a web process writes once per
  request (one line per request while the file is broken, the healthcheck included). Surfacing the problem in
  `fsa status` from the ops side is left to a later unit. A missing file on an `fsa` instance is a broken install, not a
  normal state: `write()` creates `runtime/` before compose starts.
- HTTPS modes rename the session cookie to `__Host-…`, so sessions under the old name end once and users sign in
  again; a remember-me cookie restores the login. Rows under the old name expire through session GC.
  SESSION_SECURE_COOKIE in the rendered env is only a default now (ADR 0040's V3.4.4 is done for `fsa` instances).
- U14 should also list cloudflared's address in TRUSTED_PROXIES: the app pins host and scheme either way, but
  without it every tunnel client shares cloudflared's address for throttles and audit IPs.
- The smoke token sits in a 0644 file inside the 0700 instance directory. Only the instance owner, root and the
  app containers can read it. In local mode a host process holding the token arrives from the Docker gateway (a
  private address) and passes; it could read the instance's files anyway.
- Mails sent during a tunnel link to the tunnel host. Customer document links (`/documents/links/*`), admin
  notification links (`/admin/…` without `--tunnel-admin`) and the builder preview are not reachable there
  (QUESTIONS 34).
- The reader's cache key cannot tell apart two writes in the same second with the same size when the second
  reuses the first one's freed inode; the stale value then lasts until the next write. The plan's key is kept;
  `fsa` writes the file rarely and the size usually differs.
- Console commands outside jobs (scheduled commands, one-offs) keep APP_URL, which is the instance URL from the
  state, never the tunnel. Scheduled work that sends mail does so through queued jobs.
- Sub-path instance URLs keep the path in links, but routing under a prefix is still unsupported.
