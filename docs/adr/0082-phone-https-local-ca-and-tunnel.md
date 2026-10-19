# 0082: Phone HTTPS: the local-CA edge, the trust page and the quick tunnel
- Status: accepted
- Date: 2026-10-18
- Refines: ADR 0057 (phone HTTPS decision), ADR 0074 (runtime tunnel block), ADR 0075 (edge, fixed-address networks),
  ADR 0077/0078 (`config set` machinery), ADR 0081 (demo passwords before exposure)

## Context
Plan unit U14 (PLAN M11-T03): BUILD_PROMPT_V2 E11, §7 (`install --phone-https local-ca|tunnel`, `--lan-address`,
`fsa tunnel start|stop|renew`), R19, R20, R21, R29, R61, R65, F3, AE6, J6; KTD10, KTD11, KTD12. The app side of the
tunnel (runtime block, peer pinning, R65 path policy, 503 after expiry) exists since U10 (ADR 0074). Facts this relies
on, checked 2026-10-18 by running them: Caddy 2.11.7 (`caddy:2.11.7-alpine`) issues a `tls internal` certificate for
an IP site with SAN `IP Address:<ip>` and the root under `/data/caddy/pki/authorities/local/` (root and intermediate
with keys, 0600 for Caddy's UID); `default_sni <ip>` lets clients without SNI (every client connecting by IP) complete
the handshake; with an explicit IP site Caddy's automatic HTTP→HTTPS redirect for that host wins over a catch-all
`http://` site, so `auto_https disable_redirects` is needed for "port 80 serves only the trust page"; `handle` takes one
matcher, so the trust paths need a named matcher. Docker Desktop (29.2, arm64) publishes on a host LAN address
(`-p 192.168.178.24:80:80`) and then not on loopback. cloudflared 2026.10.1's image runs `cloudflared --no-autoupdate`
as 65532; its metrics server answers `/quicktunnel` with `{"hostname":"…"}` (empty until connected; source
`metrics/metrics.go`, not run against Cloudflare: §15.1 forbids real tunnels in the run).

## Decision
**State.** Phone HTTPS is the setting `phone_https` (`local-ca` or `tunnel`), local mode only (`InstanceSettings::
phoneHttps()` is null in other modes). The grammar keeps it plain text so a newer value reads (ADR 0081 counts any value
as exposure); only the two values render anything, and the flags accept only those. `lan_address` (IPv4 unicast, not
loopback/link-local/multicast) and `tunnel_subnet` (private /28 from 10.215.0.0/16) are kept when the option goes off.

**Local CA (R19).** Local mode plus the edge (`InstanceSettings::rendersEdge()`): the URL becomes `https://<LAN
address>`, the app keeps its loopback port and joins the edge network, TRUSTED_PROXIES is the edge's fixed address, the
edge gets no env (no catcher there; the catcher stays on loopback). The edge publishes 80/tcp and 443/tcp on the LAN
address only. Caddyfile (`CaddyfileRenderer::render(LocalCaSite)`): global `admin off`, `persist_config off`,
`skip_install_trust`, `auto_https disable_redirects`, `default_sni "<ip>"`, `pki { ca local { name "form-schema-app
local CA" } }`; site `"https://<ip>"` with `tls internal`, `/_fsa/*` 404, everything else to the app without
X-Forwarded-Port; site `"http://"` with `@phone_trust path /phone-trust /phone-trust/*` to the app and everything else
`redir "https://<ip>{uri}" 308` (the only placeholder besides the env one, after a validated IPv4 address).
- The LAN address is `--lan-address`, else the launcher's best candidate (`LanAddress::best`: the first private IPv4 of
  FSA_LAN_ADDRESSES, else the first usable IPv4); none known is exit 2 `lan_address_required`.
- The CA's public root is copied from the certificate volume into `runtime/local-ca-root.crt` (0644) by a throwaway
  container of the pinned Caddy image, as Caddy's UID, without network, reading `root.crt` only (`LocalCaStore`); it is
  accepted only when it is exactly one CA certificate and nothing else (`Runtime\LocalCaRoot`, shared with the app).
  The smoke test (install) and `config set` verification (`LocalCaEdge`) then check HTTPS to the LAN address verified
  against that root without SNI, and port 80 (`/up` → 308, the trust page → 200), with `curl` from the app image on the
  edge network aimed at the edge's fixed address (`--connect-to`).
- **Trust page served by the app** (`PhoneTrustController`, `resources/views/phone-trust.blade.php`, DE/EN): it has
  the locales, Blade, the QR library (BaconQrCode) and the CSP machinery; a Caddy-served static page would need fsa to
  render HTML, its translations and a QR code. Routes `/phone-trust` and `/phone-trust/root.crt`
  (`Runtime\PhoneTrustRoute`, shared with the renderer), 404 unless the runtime says local CA (instance URL exactly
  `https://<IPv4>`, a valid root). No session, cookies, CSRF or auth-guard access (locale from `?lang` or
  Accept-Language), so a remember-me cookie never signs anyone in there; no throttle (Laravel's resolves the user).
  `AllowPlainHttpPage` marks a plain-HTTP request as not HTTPS, so neither HSTS nor `upgrade-insecure-requests` reach
  the http page (the phone does not trust the CA yet). The page shows the root's SHA-256 fingerprint (what install,
  `config set` and `fsa status` print), iOS steps (profile, then full trust), Android steps with the tunnel
  recommendation, the unsynced-data warning, how to remove the CA, a QR code of the Field App URL, and a trust check
  (an image from the HTTPS origin; CSP `img-src` names that origin) that reveals the Field App link once trusted.
- `fsa config set phone-https local-ca|off` (`PhoneHttpsApplier`, through ADR 0077's apply/verify/revert): the URL
  change needs `--yes`; preflight: 80/443 free on the address unless the own edge serves them (`LocalCaPorts`), no
  account on the demo password (`config set` cannot hand out passwords, so demo accounts are rotated first with
  `fsa demo --rotate-passwords`; exit 3 `default_password_in_use`), the edge subnet. `off` removes the edge and, in the
  SettingHooks completion step after verification, deletes `pki/` and `certificates/local/` from the volume; the
  runtime root goes with the render. `fsa config set lan-address <ip>` re-issues for a new address (the CA stays);
  with the CA off it only records the address. `fsa doctor` (`lan_address` check) and `fsa status` warn
  `lan_address_changed` with that command when the address is no longer one of the host's.

**Quick tunnel (R20, R65, KTD12).** A compose service `tunnel`, rendered while `phone_https` is `tunnel`: the pinned
cloudflared image, `tunnel --metrics 0.0.0.0:20241 --url http://app:8080`, user 65532, read-only, every capability
dropped, no env, nothing published, `restart: "no"`, on the network `tunnel` (fixed /28, `ip_range` upper half, ADR
0075's lesson) at the subnet's second address; the app joins that network. cloudflared is **not** a trusted proxy
(this reverses ADR 0074's suggestion): the runtime pins host and scheme of every request from its address anyway, and
a trusted `X-Forwarded-Host` — one cloudflared or a client through it sends, as the fake's Caddy does on every request —
would make Symfony's host check answer 400 in HandleCors before the runtime middleware pins the host (found by J6).
Tunnel clients therefore share cloudflared's address for throttles and audit IPs.
- `fsa tunnel start [--tunnel-admin]` (`TunnelOperation`): local mode without the local CA (else exit 2
  `invalid_combination`); one read-only one-off (`app:demo-passwords --check`, now also `organizations {total,
  non_demo}`) — another account on `password` exits 3 `default_password_in_use`, organizations besides the demo need
  `--yes` (exit 6). Under the lock with a journal (operation `tunnel`): demo accounts still on `password` are rotated
  first (`DemoData::rotation`, passwords listed once as secrets), the image is pulled when missing, the stack changes
  through `ConfigRollout` (workers stop, `up --wait --remove-orphans`, KTD19), the hostname is read from `/quicktunnel`
  by a `curl` one-off on the tunnel network (30 attempts, 2 s apart; it must match the app's grammar), runtime.json gets
  `{hostname, expires_at: now + 4 h, peer, admin}`, the app is verified and the workers start. A failure after the
  stack changed reverts to the previous stack and clears the block (exit 5, `reverted: true`); the rotation stays and
  its passwords are still shown.
- `renew` reads the hostname again (a changed one is recorded, with a warning) and sets a new 4-hour expiry; it needs
  an active tunnel (exit 2 `no_tunnel`). `stop` clears the runtime block first, then removes cloudflared and the
  app's tunnel network (the subnet stays); without a tunnel it is `changed: false`. A start beside a running tunnel
  answers with it (`changed: false`).
- **Expiry (AE6).** Nothing runs at the 4-hour mark: the app answers the expired host with 503 (ADR 0074), `fsa status`
  reports `tunnel.state: expired` (or `stopped` when cloudflared exited) without changing anything, and the next
  mutating command — `config set`, `demo`, `tunnel …` — first removes it in its own journaled operation (action
  `expire`, `TunnelSweeper`) and says so (`tunnel_removed`), also when the command itself then fails. Only a tunnel the
  state records is swept; a stray runtime block is `fsa tunnel stop`'s to clear.
- `fsa install --phone-https tunnel` installs in local mode, then starts the tunnel as `tunnel start` with the install's
  command line as the confirmation; a tunnel that fails leaves the installed instance and makes the command fail with
  the tunnel's exit code (`InstallTunnel`).

**Harness.** `FSA_TEST_TUNNEL=image=<fsa-test-…>;ttl=<10–14400>` (passed by the shim, honoured only by harness images,
`HarnessTunnel`) swaps cloudflared for a fake built by the journey (`tests/Install/Harness/fake-tunnel`: the pinned Caddy
image with a copy of the binary without file capabilities, which `no-new-privileges` with every capability dropped
cannot execute): `/quicktunnel` names a random `fsa-test-….trycloudflare.com`, port 8443 forwards to the app with that
Host from the peer address. J6 uses the host's LAN address (the shim's rule) and Chromium with
`--ignore-certificate-errors-spki-list` (root and served chain) standing in for a phone that trusts the CA.

## Alternatives considered
- **The tunnel as a container outside compose (`docker run`):** no app network change, but then every `compose up` of
  another command could not tell it apart, and the app still has to join a network cloudflared reaches; rendering it
  from the state keeps one authority and reuses the rollout and revert.
- **Writing `expires_at` into the past in the journey instead of a harness TTL:** tests the app's 503 but not fsa's own
  expiry arithmetic and status; the TTL knob is harness-only like FSA_TEST_DNS.
- **Rotating demo passwords in `config set phone-https local-ca` like `tunnel start`:** `config set` has no secrets
  channel in its envelope; it refuses with the rotation command instead (ADR 0081's "or" path).
- **Serving the root certificate from Caddy (`file_server` on the pki directory):** one path mistake would serve
  `root.key` next to it; the app serves a copy that holds no key.

## Consequences
- Turning the local CA on again creates a new CA (phones trust anew). A turn-on that fails and reverts leaves the CA it
  created on the volume until the next `off` or uninstall (the completion hook runs only after a verified change).
- Starting or stopping a tunnel recreates the app (it joins or leaves the tunnel network) and restarts queue and
  scheduler as the rollout's workers: a short interruption, announced by the URL-change warnings.
- On Docker Desktop the doctor's probe of 80/443 on the LAN address succeeded while another container already published
  the same address and port (observed in J6 with a leftover container; the later binding then serves nothing): a taken
  LAN port is not always detected there.
- Unverified until the human checklist (`RELEASING.md`, ADR 0064): a real quick tunnel against Cloudflare (hostname
  from `/quicktunnel`, the Host cloudflared forwards), iOS and Android trusting the root, and Android PWA installs with a
  user CA.

## Amendment 2026-10-19: the Android tunnel advice turns the local CA off first (M11 gate)
The trust page exists only in local-CA mode, where `fsa tunnel start` exits 2 `invalid_combination` (`TunnelRules`).
Its Android tunnel recommendation (`phone_trust.android.tunnel`, DE/EN) therefore names both steps: turn the CA off
with `fsa config set phone-https off --yes`, then run `fsa tunnel start`. A Feature test takes the command from
TunnelRules' refusal hint and finds it on the page before `fsa tunnel start`, in both languages.

## Amendment 2026-10-19: where the local CA's keys live, a deviation from §10 and §6.3 (M11 gate)
BUILD_PROMPT_V2 §10 says "The local CA key stays in the instance directory with mode 0600", and §6.3 lists `certs/`
(local CA) in the instance directory. The implementation keeps the root and intermediate keys where Caddy creates them:
`/data/caddy/pki/authorities/local/` on the edge's certificate volume `<project>_edge-data` (`LocalCaStore`,
`EdgeService`), 0600 for Caddy's UID 65532. There is no `certs/` directory. This is a deviation (QUESTIONS 44).
- **Why.** Caddy's `tls internal` creates the CA in its own pki directory, signs every leaf from there and renews the
  intermediate there, and the edge already mounts the certificate volume at `/data/caddy`. Keys in `<instance>/certs/`
  would need a host directory bind-mounted into the edge, writable for UID 65532 inside the owner's 0700 instance
  directory, or a CA that fsa generates and hands to Caddy (`pki { ca local { root … intermediate … } }`). Both add a
  path that writes or moves private keys, and neither keeps them more confidential.
- **Confidentiality.** On a rootful Linux engine the volume lives in Docker's root-only data directory. Only root and
  whoever can run containers can read it, and they can read a 0600 file in the instance directory just as well. Inside
  the volume the key files are 0600 for Caddy's user. fsa never opens the volume itself: a throwaway Caddy container,
  read-only and without network, copies out the public root alone, which must be exactly one CA certificate. The app
  serves only that copy. `config set phone-https off` deletes `pki/` and the issued certificates, and `fsa uninstall`
  removes the volume.
- **Consequence for M12 (U16 backup, U17 restore).** The CA is not in the instance directory, so a backup of the
  directory and the database does not hold it. A restore without the edge volume gives a local-CA instance a new CA
  with a new fingerprint the next time the edge starts, and every phone must trust it again. U16/U17 decide: either
  backups carry the volume's `pki/` (a private key in the backup, protected like APP_KEY), or the restore result and
  the docs tell the operator to have phones trust the new root.

## Amendment 2026-10-19: start order, recovery, status URL (M11 gate)
- **Start order (`TunnelStart`).** Before the lock: the account check (with a progress event, `accounts`, at most 5 min)
  for exit 3 and the prompt (exit 6). Under the lock, journaled: `accounts` again (counts only; another account on
  `password` exits 3, organizations nobody confirmed exit 6, the demo rotation is decided from this answer) →
  `preflight` (the target state with its subnet and cloudflared's image; `edge_subnet_unavailable` and
  `image_unavailable` are exit 3 with nothing changed) → `demo_rotate` → `start` → [`revert`]. Nothing changes before the
  rotation, so a failed check, pull or subnet search loses no password. From the rotation on every failure is exit 5
  and keeps the rotated passwords in the result (`demo_passwords_rotated`, secrets, `changed: true`): once `start` began
  the stack is reverted; a failure before it (the rotation one-off, a signal received during it) leaves the untouched
  stack alone and closes the journal. The target state is taken after the rotation, so its `demo_passwords_rotated`
  history entry stays. `fsa install --phone-https tunnel` reports the tunnel's `data.demo` on every path.
- **Renew** is read-only until its one write of runtime.json: a failed or interrupted renew is exit 9 with nothing
  changed (it was exit 4 with the journal left open) and the tunnel keeps its expiry.
- **Recovery.** `fsa tunnel stop` finishes any unfinished tunnel journal (a start whose revert failed, a cut-off stop,
  renew or cleanup) and converges, also when the status is `none`; the next mutating command does the same unless a
  live tunnel would go (ADR 0078 amendment). "Only a tunnel the state records is swept" holds for tunnels without an
  unfinished journal.
- **Status URL.** `fsa status` reports an active tunnel's URL as `data.url`, with `data.local_url` (the loopback URL,
  null outside local mode) beside it, as `fsa tunnel` and install do; an expired or stopped tunnel leaves `data.url` local.
- **Hostname wait.** `QuickTunnelHostname` stops at `maxSeconds()` (510 s): every probe container gets at most the time
  that is left, so a hanging probe cannot stretch the wait to 30 × 60 s.
- **Install.** A finished install compared with `--phone-https tunnel` treats the tunnel as transient: an instance with a
  tunnel or none matches, and the result warns `tunnel_not_running` with `fsa tunnel start` (install never starts one on
  a finished instance); a local-CA instance still differs (exit 2).
