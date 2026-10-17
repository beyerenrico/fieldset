# 0064: Releases built and tested locally; publishing is a human step
- Status: accepted
- Date: 2026-10-17

## Context
E18. §15.1 forbids real external accounts, real ACME, real tunnels, pushes to a real registry and pushing a `v*`
tag. Publishing is outward-facing and partly irreversible: a public GHCR package cannot be made private again
(ADR 0050).

## Decision
- The run builds releases into `dist/` and tests them against a harness compose project (plan KTD23): a local
  registry, a static release server for `dist/`, Pebble with challtestsrv as ACME CA and DNS, Traefik for proxy
  mode, a fake tunnel container, and memory-limited containers for E16.
- The harness builds with a buildx builder on `network=host`, pushes to the local registry and signs with a local
  key pair, which only harness builds accept (ADR 0060).
- Install journeys form a separate Pest suite, `Install`, excluded from the default run and the coverage gates.
  They drive real Docker, with resources prefixed `fsa-test-` and removed after each journey.
- Publishing to GHCR and GitHub Releases is a human step per `RELEASING.md` (first version in M9, completed in
  M13), or CI on the first pushed `v*` tag.
- The first public publish is a pre-release that does not move `latest`. Then comes the post-publish checklist
  (R52): make the GHCR package public, pull every manifest digest anonymously, `cosign verify` with the identity
  `fsa` expects, a one-liner install on a throwaway VPS against Let's Encrypt staging, a real tunnel, and the
  local CA trusted on one iOS and one Android device.

| Component (§5) | Version / image, verified 2026-10-17 | License |
|---|---|---|
| CNCF Distribution registry | `registry:3` (3.1.2 on Docker Hub; distribution v3.1.2, 2026-09-24) | Apache-2.0 |
| Pebble and challtestsrv | `ghcr.io/letsencrypt/pebble:2.10.1`, `ghcr.io/letsencrypt/pebble-challtestsrv:2.10.1` (2026-04-20) | MPL-2.0 |
| CoreDNS | `coredns/coredns:1.14.7` (2026-08-19) | Apache-2.0 |
| Traefik | `traefik:v3.7.15` (2026-10-15) | MIT |
| shellcheck, hadolint, actionlint, Trivy (dev only) | 0.11.0, 2.15.1, 1.7.12, 0.76.0 | GPL-3.0, GPL-3.0, MIT, Apache-2.0 |

Sources: GitHub releases and Docker Hub/GHCR tag listings; licenses from GitHub repository metadata. §5 names
"Pebble + CoreDNS", while the plan uses challtestsrv as Pebble's DNS. CoreDNS is added only if a journey needs a
resolver beyond challtestsrv. Pebble's ports and roots follow the plan's sources and were not re-checked.

## Alternatives considered
- **Publish during the run:** outward-facing and irreversible, and forbidden by §15.1.

## Consequences
- `v2.0.0` is tagged locally only. `FINAL_REPORT_V2.md` lists the human publish steps and marks the checklist as
  not yet verified.
- Until a human runs the checklist, these stay unverified: real Let's Encrypt, a real quick tunnel, phone trust on
  devices, anonymous GHCR pulls and keyless signing in GitHub Actions.
- The GPL-3.0 linters run only in CI and never ship in the image.
