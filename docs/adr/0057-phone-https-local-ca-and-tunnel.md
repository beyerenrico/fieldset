# 0057: Phone HTTPS on a local install: local CA or quick tunnel, both demo-grade
- Status: accepted; amended 2026-10-18 by ADR 0082 (trust page served by the app, cloudflared not a trusted proxy,
  demo passwords before the local CA)
- Date: 2026-10-17

## Context
E11. Offline mode and install-to-home-screen need HTTPS. The plan amends E11 with R61 (demo-grade, mutual
exclusion, tunnel recommended on Android), R65 (path policy) and KTD10–KTD12 (runtime hostname and expiry).

## Decision
- Off by default. The two options exclude each other and exclude domain and proxy modes (exit 2, R61).
- **`--phone-https local-ca`:** the edge (ADR 0052) issues a `tls internal` certificate for the chosen LAN address
  (`--lan-address`, default: the shim's best candidate), with `default_sni` set to it.
  - It binds 80 and 443 on that address only, never `0.0.0.0`. Port 80 serves only the trust page and the root
    certificate, without sign-in, and answers everything else with 308 to HTTPS.
  - The trust page shows the root's SHA-256 fingerprint, which `fsa` also prints. It has the iOS and Android steps,
    recommends the tunnel on Android, explains how to remove the CA, and offers a QR code. A changed LAN address
    leads to an offer to re-issue the certificate. The CA key is 0600, and turning the option off deletes it.
- **`--phone-https tunnel` / `fsa tunnel start|stop|renew`:** an account-less cloudflared quick tunnel. The image is
  `cloudflare/cloudflared:2026.10.x`, pinned by digest. 2026.10.1 is current (GitHub release of 2026-10-15, Docker
  Hub tag `2026.10.1`, Apache-2.0, verified 2026-10-17).
  - cloudflared reaches the app from a fixed address on its own network, the only trusted proxy for the tunnel
    host. It runs with `restart: "no"`, because every restart creates a new hostname.
  - `tunnel start` reads the hostname from the metrics endpoint `/quicktunnel` (cloudflared
    `metrics/metrics.go`, verified by GitHub code search). It writes hostname and expiry to the runtime directory.
    The hostname must match `^[a-z0-9-]+\.trycloudflare\.com$`.
- The tunnel stops after **4 hours** unless renewed.
  - After that, the app answers the tunnel host with 503 and `Retry-After` (no socket needed), `fsa status`
    reports it read-only, and the next mutating `fsa` command removes the container and restores the local URL.
  - This settles BUILD_PROMPT_V2's deferred item "Tunnel expiry and URL switch need the Docker socket".
- The tunnel host serves only the Field App, the API, sign-in and assets, never `/platform`, Horizon or the API
  docs; `/admin` only with `--tunnel-admin` (R65, app middleware keyed on the TCP peer). With non-demo
  organizations, `tunnel start` needs `--yes`. Demo passwords rotate first (ADR 0055).
- Verified 2026-10-17 (developers.cloudflare.com, TryCloudflare): "Quick Tunnels are for testing and
  development", "no uptime guarantee", 200 in-flight requests (beyond that 429), no SSE, a new hostname each time.

## Alternatives considered
- **Offer only one option:** the local CA needs a CA installed on the phone, and the tunnel involves a third party
  and internet exposure. The user picks.

## Consequences
- A trusted CA can vouch for any site, and the page says so (§10). A LAN attacker could swap the root on the
  plain-HTTP page; the fingerprint comparison is the mitigation.
- Unverified: whether Android trusts a user-installed CA for PWA installs (plan Risks), and whether a
  name-constrained root works on phones. `RELEASING.md` tests one iOS and one Android device. Journeys use a fake
  tunnel (ADR 0064). A URL change leaves unsynced phone data behind, and `fsa` warns about it.

## Amendment 2026-10-18: phone HTTPS as built (ADR 0082)
- The app serves the trust page and the root (`/phone-trust`, `/phone-trust/root.crt`); the edge's port-80 site only
  passes those paths on. fsa copies the public root into `runtime/local-ca-root.crt`, so the edge never serves files
  from Caddy's `pki/` directory, which holds the keys.
- Reverses "the only trusted proxy for the tunnel host": cloudflared keeps its fixed address, the TCP peer the app pins
  host and scheme for, but it is not in TRUSTED_PROXIES (a trusted `X-Forwarded-Host` got a 400 before the pinning,
  found by J6). Tunnel clients share its address for throttles and audit IPs.
- "Demo passwords rotate first" holds for `fsa tunnel start`; `fsa config set phone-https local-ca` refuses while an
  account has `password` (exit 3 `default_password_in_use`) and names `fsa demo --rotate-passwords`.
- The re-issue offer is the `lan_address_changed` warning (doctor, status) naming `fsa config set lan-address <ip>`;
  the CA stays. An expired or stopped tunnel is removed by the next `fsa config set` (any key but `operator-email`),
  `fsa demo` or `fsa tunnel`.
