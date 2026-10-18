# 0075: The edge and domain mode (Caddyfile, network, catcher auth, ACME, install)
- Status: accepted
- Date: 2026-10-18
- Refines: ADR 0052 (Caddy edge), ADR 0053 (modes), ADR 0054 (catcher), ADR 0069 (renderer), ADR 0072 (install)

## Context
Plan unit U8 (PLAN M10-T02): R16, R66, F1, J2, KTD10. ADR 0072 stopped `fsa install --domain` with
`unsupported_mode` after the DNS preflight. This unit renders domain mode, enables it in `fsa install`, puts the
mail catcher behind a `/platform` session with MFA, and tests the whole path against Pebble. The Mailpit spike
(LEARNINGS 103, `storage/agent-logs/spike-mailpit-forward-auth/`) fixed how the catcher works behind `forward_auth`.

Facts this relies on (checked 2026-10-18 against `caddy:2.11.7-alpine`, `axllent/mailpit:v1.31.6` and
`ghcr.io/letsencrypt/pebble:2.10.1` by running them):
- The Caddy image ships `/data/caddy` and `/config/caddy` as 1777 and `setcap cap_net_bind_service=ep` on the binary;
  a new named volume mounted at `/data/caddy` inherits 1777, so a non-root UID can write it.
- Docker sets `net.ipv4.ip_unprivileged_port_start=0` inside container network namespaces, so Caddy binds 80/443
  as non-root even with `no-new-privileges` and every capability but NET_BIND_SERVICE dropped. Rootless Docker still
  needs the host-side sysctl for RootlessKit to publish ports below 1024.
- A quoted site address (`"forms.example.com" {`) adapts; Caddy treats `.test` names as public (ACME, not
  `tls internal`); with a global `acme_ca` Caddy uses only that issuer; a failed order is retried after 60 s.
- Mailpit sends its own CSP (nonce scripts, `frame-src 'self'`).
- Pebble's ACME endpoint uses the static minica certificate of its image (SAN localhost, pebble, 127.0.0.1); its
  issuing root is new on every start (management API `/roots/0`). Its default validation ports are 5002/5001.

## Decision
**Renderer (ops side, `App\Domain\Instance\Compose`).**
- `ComposeRenderer::MODES` is local and domain. `EdgeService` joins `defaultDefinitions()` and renders only in
  domain mode. Definitions may now contribute instance-directory files (`ServiceSpec::$files`, written 0644 in
  0755 folders by `RenderedProject::writeTo()`): the edge's `edge/Caddyfile`.
- Edge service: the pinned `caddy` digest; `user: 65532:65532`; `cap_drop: [ALL]`, `cap_add: [NET_BIND_SERVICE]`,
  `no-new-privileges`, read-only root with tmpfs for `/tmp` and `/config/caddy` (config autosave is off); publishes
  80/tcp, 443/tcp and 443/udp on every host address and is the only service that publishes anything in domain mode
  (app and Mailpit publish nothing); `depends_on: app (healthy)`; healthcheck `wget` on a loopback-only site
  (`127.0.0.1:8081/healthz`). The instance's `edge/` folder is mounted read-only as a directory (an atomic rewrite
  is seen); a label carries the Caddyfile's SHA-256, so a changed Caddyfile recreates the edge.
- Certificates: volume `edge-data` at `/data/caddy` with the explicit name `<project>_edge-data` and the instance
  label. Nothing but uninstall removes it: re-renders, `config set` (U11) and an install retry keep it.
- Network: `edge`, a fixed private /28 from 10.213.0.0/16 (`EdgeNetwork`). Docker's default pools never hand out
  that range. Install picks the first candidate derived from the instance UUID that overlaps no existing Docker
  network (`DockerGateway::networkSubnets()`, new), stores it as `edge_subnet` and never re-chooses it. The edge
  sits at the subnet's second host address; app and, in capture mode, Mailpit join the network (the edge reaches
  `mailpit:8025` there); queue, scheduler, Postgres, Valkey and Gotenberg do not. The IPAM config sets the gateway
  (first address) and `ip_range` to the upper half of the subnet: Docker does not reserve static addresses from its
  dynamic pool, so without the range the app, started before the edge, took the edge's address and the edge failed
  with "Address already in use" (found by J2).
- Trusted proxy: app, queue and scheduler get `TRUSTED_PROXIES=<edge address>` in their env files, nothing else.
  The runtime file is unchanged (ADR 0074 resolves URL and scheme from it).
- Env split (amends ADR 0069's table): the edge's env file holds only `FSA_CATCHER_AUTHORIZATION=Basic …`
  (capture mode); Mailpit gets `MP_WEBROOT=/_mail/` in domain mode.
- Settings grammar (`InputGrammar`): `acme_email` (e-mail), `acme_ca` (https URL), `acme_ca_root` (SHA-256 hex of
  `edge/acme-ca-root.pem`), `edge_subnet` (private IPv4 CIDR, /16–/29, aligned).

**Caddyfile (`CaddyfileRenderer`, `EdgeSite`).** Built as a tree and serialized; values from the state are always
double-quoted tokens and refused (exit 1 `unsafe_caddyfile`, a renderer bug) when they contain a control
character, `"`, `\`, a backtick or a brace, so nothing can end a token or line or reach `{$ENV}` substitution or
`{placeholder}` expansion. Code literals are checked against a plain-token pattern; the only placeholder is the
edge env variable. Shape:
```
{
	admin off
	persist_config off
	email "<acme_email>"                     # only with --acme-email
	acme_ca "<directory>"                    # Let's Encrypt production unless --acme-staging / --acme-ca
	acme_ca_root "/etc/caddy/acme-ca-root.pem"   # only with --acme-ca-root-file
}
"<domain>" {
	handle /_fsa/* { respond 404 }
	redir /_mail /_mail/ 308                 # capture mode only, from here …
	handle /_mail/* {
		forward_auth app:8080 { uri "/_fsa/catcher-auth"; header_up -Authorization; header_up -Upgrade; header_up -Connection }
		header { defer; X-Frame-Options "SAMEORIGIN"; X-Content-Type-Options "nosniff"
			+Content-Security-Policy "frame-ancestors 'self'"; Referrer-Policy "no-referrer"; Cache-Control "no-store" }
		reverse_proxy mailpit:8025 { header_up Authorization "{env.FSA_CATCHER_AUTHORIZATION}"; stream_timeout 5m }
	}                                        # … to here
	handle { reverse_proxy app:8080 }
}
http://:8081 { bind 127.0.0.1; respond /healthz "ok" 200 }
```
- Automatic HTTPS with Caddy's own HTTP→HTTPS redirect (308) and HTTP-01/TLS-ALPN on 80/443. The global `acme_ca`
  is always set, which also means no ZeroSSL fallback: the issuer is exactly the configured directory.
- The app is proxied unchanged (Host kept, X-Forwarded-* set fresh by Caddy, which trusts no incoming ones), so the
  app's CSP, HSTS, cookies and the preview CORS header pass through.
- No access log (Caddy's default). Sign-in link tokens are in the URL fragment anyway; the app keeps its own logs.
- The catcher headers follow the spike, with two changes: `X-Frame-Options: SAMEORIGIN` instead of KTD10's `DENY`
  (Mailpit's Raw tab frames itself; with `frame-ancestors 'self'` browsers ignore XFO anyway), and the CSP is
  *added* to Mailpit's own policy (`+`, deferred) rather than replacing it, so Mailpit's nonce script policy stays.

**Catcher auth (app side).** `GET /_fsa/catcher-auth` (`CatcherRoute::AUTH_PATH`, shared with the renderer;
`CatcherAuthController`, logic in `App\Domain\Platform\Auth\CatcherAccess`):
- Allowed only for an active operator (`PlatformAdmins::check`) whose session carries a valid `/platform` mark
  (`PlatformSession::isValid`, within `platform.session_hours`), whose MFA is confirmed (secret and confirmation
  set; enrolling requires a valid TOTP code, so an operator who enrolled after signing in passes), and whose
  session password hash (`password_hash_web`, when present) matches the current password. The user id is read from
  the web guard's session key directly, never through `guard->user()`, so a remember-me cookie cannot sign anyone in
  or fire login events here.
- The session is read-only (`ReadOnlySession`, a separate middleware: Laravel's `withoutMiddleware()` also drops
  subclasses of an excluded one): it starts from the decrypted cookie and never saves, sets no cookie, stores no
  previous URL and collects no garbage. Mailpit fires many parallel requests through `forward_auth`; writing the
  session back would race with `/platform` and age flash data. The route keeps only EncryptCookies and
  SubstituteBindings of the web group.
- Audit `platform.catcher_opened` (DE/EN labels) once per session: an atomic `Cache::add` on the SHA-256 of the
  session id, kept for `platform.session_hours`, decides which request writes it. A failing audit fails the request.
- Denied: a navigation (`Sec-Fetch-Mode: navigate`, or no Sec-Fetch-Mode and an HTML `Accept`; never with
  `Sec-WebSocket-Key` or `X-Requested-With: XMLHttpRequest`) gets 302 to the `/platform` login, everything else 401.
  All answers are `no-store`. The path is not on the tunnel allow-list (404 there) and the edge answers `/_fsa/*`
  with 404 from outside.

**`fsa install --domain`.**
- Flags: `--domain`, `--acme-email`, `--acme-staging` (Let's Encrypt staging), `--acme-ca <https URL>`,
  `--acme-ca-root-file <PEM>` (input file flag: the launchers mount its directory read-only). `--acme-staging` with
  `--acme-ca`, ACME flags without `--domain`, `--port` with `--domain` and an IP as domain exit 2. The CA root must
  be PEM certificates only (≤ 64 KiB, parsed by OpenSSL); it is written to `edge/acme-ca-root.pem` (0644) in the
  state phase and the state keeps its SHA-256. A resume without the flag needs the file already written.
- Doctor: the DNS check (A and AAAA separately, ADR 0072) stays and runs first. The ports check in domain mode
  probes 80 and 443 on `0.0.0.0` (443/udp is not probed); a rootless engine that may not publish them is reported as
  such with the sysctl (`privileged_ports` in the probe), never as "in use". On an installed instance whose edge
  runs, 80/443 count as served.
- The doctor phase decides the mode (`InstallMode`: domain, `https://<domain>`, ACME inputs, edge subnet) and
  journals it, so a resume keeps it and refuses another `--domain` or ACME value (`flag_already_applied`). The state
  phase applies it; runtime `instance_url` is `https://<domain>`, `local_url` null. A finished domain instance
  compares domain and ACME flags like the others (`use_config_set` on a difference).
- Smoke test: the edge, on its own network at its fixed address with the domain as SNI and Host: HTTPS until it
  holds a certificate (40 attempts 5 s apart, `--retry-all-errors`), then the 308 redirect. The HTTPS check skips
  chain verification because the edge has exactly one issuer and no self-signed fallback for the domain. No
  certificate in time: exit 4 `certificate_failed`, retryable, with the edge's log; nothing is removed, so the
  certificate volume (ACME account, order state) stays and `fsa install` resumes at the smoke test.
- Install and `fsa status` report `port: null` and `catcher_url: https://<domain>/_mail/` in domain mode.
- Uninstall in domain mode prefixes its confirmation with, and adds the warning `certificates_removed` about, Let's
  Encrypt's duplicate-certificate limit (plan Risks); the certificate volume goes with the other labelled volumes.

**Harness (J2).** The `acme` profile of `tests/Install/Harness/compose.harness.yaml` runs Pebble and challtestsrv
2.10.1 (pinned by index digest, ADR 0064) on a /24 from 10.214.0.0/16 that overlaps no existing network.
challtestsrv answers every A query with a fixed address on that network, and the journey attaches the instance's
edge there (`docker network connect --ip`) as soon as Compose creates it, before it starts (it waits for the app to
become healthy). Pebble validates HTTP-01 on port 80 (its config file sets 80/443), the edge resolves `pebble` on
that network, and `acme_ca_root` is Pebble's minica HTTPS root copied out of the image. J2 verifies the served
chain against the issuing root read from Pebble's management API.
- The ops container runs on the engine's default network with the engine's resolver and cannot ask challtestsrv.
  The doctor's DNS answers therefore come from `FSA_TEST_DNS` (`host=ip[,ip][;…]`), which the shim passes like the
  other `FSA_*` variables and which only release-harness images honour (`BuildFlavor`, read from the image). J2 maps
  the domain to the LAN address the shim reports (else a public address, which the check accepts with its NAT
  warning on a host without one).
- The `/platform` session for the catcher check is created inside the app container by
  `tests/Install/Harness/operator-session.php` (MFA enrolled, web-guard id and PlatformSession mark in the session,
  the encrypted `__Host-` cookie printed): driving Filament's Livewire login and TOTP challenge over plain HTTP would
  test Filament rather than the edge.

## Alternatives considered
- **Edge on the default network:** simpler, but every container there could then reach the edge and, worse, the
  app would have to trust a whole shared subnet; the forged-header journey check would fail.
- **Trusting the edge network's subnet instead of a fixed address:** needs no fixed subnet, but the app cannot learn
  a Docker-chosen subnet before the compose file is written.
- **Mailpit without its own credentials behind `forward_auth`:** one secret less, but R66 asks for both layers; the
  edge injects Mailpit's credential, so the browser still sees one.
- **Replacing Mailpit's CSP with `frame-ancestors 'self'`** (the spike's config): drops Mailpit's script policy.
- **An init container to chown a volume at `/data`:** an extra service with root and CHOWN; mounting at the image's
  1777 `/data/caddy` makes it unnecessary.
- **CoreDNS or `--dns` for the ops container in the harness:** would need the launcher to join a harness network or
  a DNS server on the host's port 53; the build-flavor-gated `FSA_TEST_DNS` keeps the launcher's network unchanged.
- **ZeroSSL as a fallback issuer (Caddy's default without `acme_ca`):** more resilient against one CA's outage, but
  makes the issuer unpredictable and contacts a second CA, which §10 wants named; Let's Encrypt only, configurable.

## Consequences
- U11 (`config set domain`, ACME settings) re-renders the Caddyfile; the hash label recreates the edge, and the
  certificate volume stays. Switching domain → local removes the edge container (`--remove-orphans`) but keeps the
  volume until uninstall.
- U14 (local CA) reuses `EdgeService`, `EdgeNetwork` and the renderer with a `tls internal` site.
- U9 must keep `TRUSTED_PROXIES` mode-specific (`ServiceEnvironments::trustedProxies()`).
- One domain-mode instance per host (ports 80/443).
- Unverified until the human checklist (ADR 0064): real Let's Encrypt (production and staging), renewal over months,
  HTTP/3 from a browser, and Mailpit's live updates through a real network path with the 5-minute stream timeout.
