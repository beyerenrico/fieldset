# 0052: TLS terminates at a separate Caddy edge service
- Status: accepted
- Date: 2026-10-17

## Context
E6. As non-root, FrankenPHP needs `CAP_NET_BIND_SERVICE` for ports 80 and 443 and write access to `/config/caddy`
and `/data/caddy` (Appendix A, Context7). The app's own Caddyfile runs with `auto_https off` and `admin off`
(Appendix C). Domain mode and local phone HTTPS need certificates, and the mail catcher needs auth in domain mode.

## Decision
- An `edge` service runs the official Caddy image, `caddy:2.11.x`, pinned by digest in the manifest. 2.11.7 is
  current: github.com/caddyserver/caddy release of 2026-10-03, Docker Hub tags `2.11.7` and `2.11.7-alpine`,
  Apache-2.0 (verified 2026-10-17).
- The edge runs in domain mode and with `--phone-https local-ca`. Plain local mode and proxy mode have no edge
  (ADR 0053).
- The app keeps serving plain HTTP on 8080 inside the stack, as non-root, with no privileged port or added
  capability. The edge runs as non-root with only `NET_BIND_SERVICE` (plan KTD10). It sits on a dedicated
  edge-to-app network with a fixed address, and the app trusts exactly that address.
- Each mode gets a generated Caddyfile with quoted values, never hand-edited. The edge only proxies, so the app's
  own headers (preview CORS, CSP rewrites of the SPA shells) pass through unchanged.
- Domain mode: ACME with an HTTP-to-HTTPS redirect, a configurable ACME directory and `--acme-staging` (R16).
  Journeys point Caddy at Pebble through `acme_ca` and `acme_ca_root`. The root is Pebble's HTTPS CA, not its
  issuing CA (KTD10).
- Local CA (ADR 0057) uses `tls internal`: root 3600 days, intermediate 7 days, leaf 12 hours (Appendix A). Caddy
  serves IP addresses over HTTPS from its internal CA (caddyserver.com/docs/automatic-https, verified 2026-10-17,
  closing the Appendix A item). Clients connecting by IP need `default_sni`, which "sets a default TLS ServerName
  for when clients do not use SNI" (caddyserver.com/docs/caddyfile/options, verified).
- In domain mode, `/_mail/` goes through `forward_auth` to an app endpoint that requires a `/platform` session with
  MFA (plan R66, ADR 0054). The edge adds `X-Frame-Options: DENY`, `nosniff` and a CSP there. (Amended
  2026-10-18 by ADR 0075: `X-Frame-Options: SAMEORIGIN` with CSP `frame-ancestors 'self'`, added to Mailpit's own
  policy, because Mailpit's Raw tab frames itself.)

## Alternatives considered
- **FrankenPHP's built-in ACME:** the app container would need the capability and writable Caddy directories,
  and it would differ between modes.
- **Traefik as the edge:** no local-CA story and a second configuration language. Traefik stays a proxy-mode test
  target (ADR 0064).

## Consequences
- Certificates, the local CA and the catcher auth live in one place, and the app container is identical in
  every mode.
- The certificate volume survives `config set`. In domain mode, uninstall warns about Let's Encrypt's
  duplicate-certificate limits before it removes the volume (plan Risks).
- Installing the root into a trust store fails inside containers, so the root is copied out for the trust page
  (Appendix A).
- The R6 log errors (Caddy autosave and storage permissions) are fixed separately in the app image, with writable
  XDG directories (plan U1).
