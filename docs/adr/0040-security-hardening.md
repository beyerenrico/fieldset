# 0040: Security hardening for 1.0 (headers, opaque preview, uploads, logs)
- Status: accepted
- Date: 2026-10-22

## Context
§11 targets OWASP ASVS Level 2. It asks for CSP, HSTS and secure SameSite cookies, upload validation with an optional ClamAV hook, structured JSON logs and optional Sentry-compatible error reporting. Where things stood before M8:
- Only `SpaController` sent a CSP (for `/app` and `/form-preview`). Attachment downloads set their own sandbox CSP. Admin pages had none.
- The builder preview iframe used `sandbox="allow-scripts allow-same-origin"` (QUESTIONS #10). A renderer XSS would have run with the designer's admin session and could have reached the Field App's IndexedDB (M6) on the same origin.
- Behind a TLS-terminating proxy Laravel trusted no `X-Forwarded-*` headers. URLs came out as `http://`, and per-IP rate limits counted the proxy's address.

What the frameworks need (checked in vendor/, Filament 5.10.2, Livewire 4.4.7, Horizon 5.50.1):
- Filament and Livewire put `Vite::cspNonce()` on every inline script. That includes the theme script, `filamentData`, the sidebar, the Livewire script tag and `livewireScriptConfig`.
- Alpine's standard build evaluates expressions with `Function()`, so `'unsafe-eval'` is needed. Livewire's `csp_safe` build does not parse Filament's (or our) inline `x-data` objects with methods.
- Filament renders `style` attributes, so `style-src 'unsafe-inline'` is needed.
- Horizon inlines its bundle with `Horizon::cspNonce()` and compiles in-DOM Vue templates, so it needs `'unsafe-eval'` too.
- Scramble's docs page loads Stoplight Elements from unpkg (Ajv uses eval). Laravel's `/up` page loads Tailwind from a CDN.

## Decision
### Security headers (one place)
`App\Http\Middleware\Security\SecurityHeaders` is the outermost global middleware, so early answers get headers too: 413 from LimitApiPayload, the maintenance 503, CORS preflights and the 400 for an unknown Host. It reads the scheme on the way out, after the inner `TrustProxies` has run. `Surface` sorts each request by path. `ContentSecurityPolicy` holds every policy. `SpaController` no longer sets headers. A CSP that a controller sets itself is kept (attachments: `default-src 'none'; sandbox`).

| Surface | Paths | CSP (abridged) |
|---|---|---|
| Html | admin, /platform, auth, Livewire, everything else | `default-src 'self'; script-src 'self' 'nonce-…' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; media-src 'self' blob:; frame-src 'self'; worker-src 'self' blob:; manifest-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'` |
| FieldApp | /app/* | unchanged from M6: `script-src 'self'`, `style-src 'self' 'unsafe-inline'`, `connect-src/worker-src/manifest-src 'self'`, `frame-ancestors 'none'` |
| Preview | /form-preview/* | `default-src 'none'; script-src 'self' <origin>; style-src 'self' <origin> 'unsafe-inline'; img-src … data: blob:; font-src … data:; connect-src 'none'; object-src/base-uri/form-action 'none'; frame-ancestors 'self' <origin>; sandbox allow-scripts` |
| Api | /api/* | `default-src 'none'; frame-ancestors 'none'` plus `Cross-Origin-Resource-Policy: same-origin` |
| Horizon | /horizon/* | nonce plus `'unsafe-eval'`, Bunny fonts for its stylesheet |
| Vendor | /docs/api, /docs/api.json, /up | baseline only: `object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'` |

Notes on the table:
- `upgrade-insecure-requests` is added behind HTTPS.
- While `npm run dev` runs (`public/hot`), the Vite dev server origin, and its `ws:` variant in `connect-src`, are allowed on Html.
- The nonce is new on every request (`Vite::useCspNonce()`).

Every response also gets these headers:
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: same-origin`
- `X-Frame-Options: DENY`, or `SAMEORIGIN` on the preview
- `Permissions-Policy` that denies accelerometer, camera, display-capture, geolocation, gyroscope, magnetometer, microphone, midi, payment, serial and usb. The Field App gets `camera=(self), geolocation=(self)` for photos and opt-in GPS.
- `Cross-Origin-Opener-Policy: same-origin` on Html, FieldApp and Horizon

Caddy adds `nosniff` to static files.

### HTTPS, cookies, proxies
- HTTPS is expected when `$request->isSecure()` or when APP_URL is https. `isSecure()` honours `X-Forwarded-Proto` from a trusted proxy. The APP_URL check is a safety net for a proxy nobody configured.
- `SecureCookies` sets `session.secure = true` for HTTPS requests before StartSession and the CSRF cookie run. SameSite stays `lax` and HttpOnly stays on, both from config.
- HSTS: `max-age=31536000` by default. `security.hsts.enabled` takes null (automatic), true or false; `max_age`, `include_subdomains` and `preload` are also configurable.
- Trusted proxies:
  - bootstrap/app.php trusts `X-Forwarded-For`, `-Host`, `-Port` and `-Proto`.
  - Which proxies to trust is read per request from `config('trustedproxy.proxies')`, which is Laravel's own fallback key, set by `TRUSTED_PROXIES` (IPs/CIDRs or `*`).
  - Empty trusts nothing.
  - Config is not loaded yet when `withMiddleware` runs, so `trustProxies(at: config(...))` is not an option.
  - `*` is only safe when the app port can be reached solely through the proxy. Otherwise clients forge `X-Forwarded-For`, which bypasses throttles and fakes audit IPs.
- Trusted hosts:
  - Laravel's TrustHosts applies outside local and testing, with `TrustedHosts::patterns()`: the APP_URL host, loopback names (for the compose health check `curl localhost:8080/up`) and `TRUSTED_HOSTS`.
  - Password-reset and invitation links are built from the request host. Without this check a forged `Host` or `X-Forwarded-Host` would send the reset token to an attacker's domain. That gap predates M8.
  - Unknown hosts get 400.

### Builder preview on an opaque origin (closes QUESTIONS #10)
- Both builders embed `/form-preview/` with `sandbox="allow-scripts"`. The response also carries CSP `sandbox allow-scripts`, so the document is opaque even if it is opened directly or embedded without the attribute. It cannot read the admin's cookies or DOM, and storage throws there. A static test makes sure the preview's import graph never touches storage.
- Protocol (`resources/field-app/src/preview/protocol.ts`, mirrored and contract-tested in builder-v2):
  - Builder to preview: posts to the iframe's window with target origin `'*'`, because no origin string names an opaque origin.
  - Preview to builder: posts its ready message to `window.parent` with `location.origin`. That is still the URL's origin inside the sandbox.
  - The builder accepts a ready message only when `event.source` is its iframe window and `event.origin === 'null'` (`isPreviewReady`). An unsandboxed preview is refused.
  - The preview accepts updates only from `window.parent` with the builder origin.
- The preview's module script and stylesheet (Vite emits `crossorigin`) are CORS requests with `Origin: null`. Caddy sends `Access-Control-Allow-Origin: *` for `/form-preview/assets/*` only: public build files, no credentials.
- `connect-src 'none'`, so the preview build drops Vite's fetch-based modulepreload polyfill.
- Preview PDF is a Livewire download, so it is unaffected.

### Uploads
- UploadInspector already covered content sniffing, a per-kind MIME allow-list (the stored extension comes from the sniffed type), size, dimension and decompression-bomb limits, and GD re-encoding.
- New: an `UploadScanner` interface (`#[Bind(ConfiguredUploadScanner)]`). It uses `NullUploadScanner` unless `uploads.clamav.enabled`, in which case it uses `ClamdUploadScanner` (clamd `INSTREAM` over TCP, 64 KiB chunks).
- The original bytes are scanned after the type check and before re-encoding.
- Infected files get 422 `file_infected`, and a `upload.infected` warning is logged with the signature but no content.
- No verdict gives 503 `scanner_unavailable`, which the Field App retries. `uploads.clamav.fail_open` turns that into accepting the file.
- A file above clamd's `StreamMaxLength` gets 422 `file_too_large`, also when failing open, so padding cannot skip the scan. clamd sends "size limit exceeded" and hangs up, and the client reads that reply even when the write fails.

### Logs and error reporting
- `JsonLogFormatter` writes one JSON object per line: `@timestamp`, `level`, `message`, `channel`, `service`, `environment`, `request_id` (from Context, set by AssignRequestId), redacted `context` and `extra`, and exception traces with paths relative to the app.
- `LogRedactor` combines the audit Redactor's key patterns with credential headers. It runs after normalization, and objects become arrays first, so their keys are redacted too.
- `ExceptionMessage` makes exception messages safe for logs and for Sentry:
  - Database errors keep the SQLSTATE, the server's first line and the SQL with `?` placeholders. Bound values and PostgreSQL's `DETAIL` are dropped.
  - Other messages go through the audit ErrorText scrubber and lose URL query strings, which carry webhook and signed-link tokens.
- It works today without a config change: `LOG_STACK=stderr` plus `LOG_STDERR_FORMATTER=App\Support\Logging\JsonLogFormatter`.
- Sentry-compatible reporting uses no SDK. It is a `sentry` log channel (`CreateSentryLogger`) whose handler posts envelopes to the DSN (Sentry, GlitchTip and others).
  - Each event carries the exception chain, frames without arguments, the `request_id` tag, the user id, and redacted context.
  - It sends no request data.
  - It never throws: failures go to PHP's error_log, and the handler cannot recurse.
  - The official `sentry/sentry-laravel` stays an option for operators who want tracing. It is not installed.

## Consequences
- Until `config/uploads.php`, `config/security.php`, `config/trustedproxy.php` and the logging channels exist, the matching environment variables do nothing: the code falls back to its defaults (no ClamAV, automatic HSTS, no trusted proxy). The requirements marked "done" below assume those files are in place.
- The builder sends updates to its iframe's window with `'*'`. If a renderer XSS navigated the frame to another (still sandboxed, opaque) page, that page would receive later unsaved definitions. Accepted: the data is the form itself, which such a script could leak anyway. A MessageChannel handshake would close this.
- Admin `script-src` keeps `'unsafe-eval'` for Alpine and Horizon. An HTML injection could still run Alpine directives, so Blade escaping remains the primary defence, and the nonce only stops injected `<script>` elements.
- Third-party UIs (API docs, `/up`) get only the baseline policy. The API docs load unpkg scripts without SRI.
- Filament's default avatar provider (ui-avatars.com) is blocked by `img-src` on purpose: it would send user names to a third party. The panels need a local avatar provider.
- Static files keep needing the Caddy rule for the preview. Serving `public/` with another web server means adding the same header for `/form-preview/assets/*`.
- The new settings live in `config/security.php`, `config/uploads.php`, `config/trustedproxy.php` and the logging channels `json` and `sentry`. Their defaults are in code, so a missing file is safe.

## OWASP ASVS 4.0.3 L2 self-review (surfaces touched in M8-T03)
| Req | Status | Evidence |
|---|---|---|
| V3.4.1–3.4.3 Secure, HttpOnly, SameSite cookies | done | SecureCookies behind HTTPS; config http_only, same_site=lax |
| V3.4.4 `__Host-` cookie prefix | open (optional) | operators may set `SESSION_COOKIE=__Host-…` behind HTTPS |
| V4.2.2 / V13.2.3 CSRF | done (unchanged) | PreventRequestForgery on web/panels; Sanctum stateful API |
| V5.3.3 Output encoding | unchanged | Blade escaping; CSP is defence in depth only |
| V7.1.1–7.1.2 No credentials or secrets in logs | done | LogRedactor after normalization; ExceptionMessage (no SQL bindings, DETAIL or URL queries); `mask_bindings_in_exception_messages` requested for pgsql |
| V7.1.3–7.1.4 Security events with time and correlation id | done | `upload.infected`; request_id in every JSON line |
| V7.3.1 Log injection | done | JSON encoding, one record per line |
| V9.1.1 TLS to clients | done | HSTS, `upgrade-insecure-requests`, trusted proxies |
| V10.3.2 SRI for third-party code | open | Scramble docs (unpkg) only; admin-only page |
| V12.1.1 / V12.1.3 File size limits | done | UploadInspector, image pixel and side limits |
| V12.2.1 File content type check | done | finfo sniffing, per-kind allow-list |
| V12.3.1–12.3.3 No user-controlled paths or names | done | path = org/submission/uuid.ext from the sniffed type |
| V12.4.1 Uploads outside the web root | done | private disk, signed or authorized downloads |
| V12.4.2 Malware scan | done (opt-in) | ClamdUploadScanner via `UPLOADS_CLAMAV_ENABLED` |
| V12.5.2 Uploaded files never executed or rendered as HTML | done | nosniff, sandbox CSP, attachment disposition |
| V12.6.1 SSRF | done (unchanged) | SsrfGuardedHttpClient; clamd and Sentry hosts are operator config |
| V14.3.2 Debug mode off in production | doc | `APP_DEBUG=false` in the production env template |
| V14.4.1 Content-Type with charset | done | SPA shells `text/html; charset=utf-8`; JSON API |
| V14.4.3 CSP | done (caveat) | per surface; `'unsafe-eval'` on admin and Horizon |
| V14.4.4 nosniff | done | every response, plus static files via Caddy |
| V14.4.5 HSTS | done | automatic behind HTTPS, configurable |
| V14.4.6 Referrer-Policy | done | `same-origin` |
| V14.4.7 Framing | done | `frame-ancestors 'none'` (preview: self only) plus X-Frame-Options |
| V14.5.2 Origin checks | done | preview protocol checks window identity and origin |
| V14.5.3 Strict CORS allow-list | done | `*` only for public preview build assets, no credentials |
| V14.5.x / V5.1 Host header | done | TrustHosts with the APP_URL host, loopback and TRUSTED_HOSTS outside local/testing |
| V1.14.6 / V14.2.x No new dependencies | done | Sentry reporting and ClamAV client without packages |
