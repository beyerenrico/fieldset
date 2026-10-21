# Final report: form-schema-app 2.0.0


Autonomous build per `docs/agent/BUILD_PROMPT_V2.md` (2.0) and `docs/agent/BUILD_PROMPT.md` (product spec), milestones
M9–M13, released as **2.0.0** (tag `v2.0.0`, local only, not pushed; E18). Gate reports: `docs/agent/milestones/M9.md` …
`M13.md`. Decisions: `docs/adr/` 0047–0091. Items for human review: `docs/agent/QUESTIONS.md`.

## What was built
2.0 changes how the app is installed and run. One command installs it with only Docker on the host. One tool, `fsa`,
runs the whole lifecycle. The Form Schema Spec stays at 1.0 and the API at `/api/v1` (E1, ADR 0047).

| Area | Delivered | Milestone / pre-release |
|---|---|---|
| Image and release | One image for every role, 2.12 GB → ~0.5 GB. Multi-arch (amd64, arm64) with SBOM, provenance and keyless cosign signatures. A signed release manifest pins every image by digest. `release.yml` includes a Trivy gate. A local harness has its own registry, release server and signing key | M9 · `2.0.0-alpha.1` |
| `fsa` core | The image is the installer: a throwaway ops container runs `install.sh` and the shim. Every command shares one JSON envelope with exit codes 0–10, a lock and journal, and resume. Commands: `install`, `doctor`, `status`, `logs`, `version`, `uninstall`, and `login-link` (single-use sign-in links instead of printed passwords) | M9 |
| Modes | Local; domain (a Caddy edge with ACME and the catcher behind the `/platform` session); proxy (Traefik, caddy-docker-proxy, port); a stack file for running without `fsa`. `fsa config show/set` applies, verifies and reverts on failure. Runtime state lives in the app (URL, gate, tunnel policy) | M10 · `2.0.0-alpha.2` |
| Mail, data, phones | Capture mail with a protected Mailpit and a banner. `config set mail`, `install --smtp-*`. `--demo` and `fsa demo` with random passwords whenever the instance is reachable beyond loopback. Phone HTTPS through a local CA with a trust page, or a quick tunnel that expires after 4 h. E16 measurement (J10) | M11 · `2.0.0-beta.1` |
| Lifecycle | Daily backups inside the stack (optional age encryption, HMAC). `restore`, `restore --drill`, `install --from-backup`. `upgrade`: health-gated, with automatic rollback. `rollback`, `reset`, and `adopt` for a 1.0 install | M12 · `2.0.0-rc.1` |
| Operability | `/platform/instance` page; opt-in update check (off by default); mailed backup alerts. The agent guide (R51) and its doc-test (J11); every guide rewritten for `fsa`; `RELEASING.md` completed. The E2E suite runs against an `fsa` instance; all install journeys run in CI; a `pg_dump` release check | M13 · **2.0.0** |

### Install with one command
Quoted from `README.md:57`. It works only once a stable release is published (`RELEASING.md:32-34`):

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/form-schema-app/releases/latest/download/install.sh | sh -s -- --org "Example Ltd" --owner-email owner@example.com
```

Before 2.0.0 is published, pre-releases use the `download/v<version>/` form with `--version`
(`docs/guide/install.md:30`). Agents follow `docs/guide/agent-guide.md`.

## Measured results against §2.3, E16 and E17
Measurement setup (`J10-envelope.json`, ADR 0083): Docker Desktop on Apple silicon (arm64, native). The whole stack was
pinned to 2 CPUs (cpuset `8,9`), and the memory limits sum to the E16 budget. **Not a VPS.**
Evidence paths are relative to `docs/agent/milestones/evidence/`.

| Criterion | Target | Measured | Verdict | Source |
|---|---|---|---|---|
| Speed: install → owner sign-in link | ≤ 300 s on E16 hardware, pull excluded | 48.5 s without pull (domain mode, Pebble ACME) | met (Docker Desktop, not E16 hardware) | `M13/J10-install.json:4-8`; M13.md:95 |
| Speed: pull, reported separately | report size and time at 100 Mbit/s | 838.7 MB (arm64), 67.1 s at 100 Mbit/s; amd64 not measured | reported | `M13/J10-install.json:22-33` |
| Speed: local install (J1) | none (context) | 46.9 s native arm64; 67.4 s amd64 emulated | — | `M13/J01-install.json:4`, `M13/J01-amd64-install.json:4` |
| Speed: local demo | ≤ 120 s with images present | 50.4 s for `install.sh --demo` (a new demo is `install --demo`, ADR 0081); `fsa demo` on an installed instance is not timed | met | `M13/J05-local-install.json:4`; M13.md:97 |
| Agent-ready: CI runs the guide (J11) | every runnable block passes | 13/13 | met | M13.md:65, 80 |
| Agent-ready: fresh subagent, guide only | every recipe completed | 8 recipes passed ("VPS install" only on its DNS-failure path, exit 3). "Change domain" passed after a precondition the guide did not name (now documented). "Phone demo" was reviewed only (no real tunnel). The repository was present on the machine (deviation) | met per M13.md:62, with these caveats | M13.md:88-94; `M13/R51-agent-run.md` |
| Safe: forced upgrade failure | automatic rollback, data identical | crashing target and unhealthy target both exit 5 and roll back. Under AE7, 58 acknowledged writes and none lost (the test asserts the database equals the safety backup). Proxy mode passed too | met (between harness releases `rc.1-test` → `rc.3/rc.4-test`, ADR 0086) | `M13/J07-upgrade.json`; M12.md R35, AE7 |
| Safe: backup restores on another host | restore on a second host | `install --from-backup` with a new domain into a second directory **on the same host**; the R51 "move" recipe also ran on one machine | partly met: no second host | `M13/J08-from-backup-domain.json`; M12.md F6 |
| Small: E17 image size | ≤ 1.0 GB uncompressed per arch | 507,086,219 B arm64 / 497,455,098 B amd64 (measured in M9). `ImageContractJourneyTest` asserts ≤ 1,000,000,000 B on each install-suite run | met | ADR 0071 (Measurements); `tests/Install/Journeys/ImageContractJourneyTest.php:133-138` |
| E16 memory | limits sum ≤ 2 GB minus OS headroom | 1,728 of 1,728 MiB (2,048 − 320). No OOM kill, no restart. App peak 361.8 of 384 MiB; backup 77.7 of 128 MiB unreclaimable | met | `M13/J10-envelope.json:25-27`; `M13/J10-peaks.json:6`; `M13/J10-backup.json:24` |
| E16 CPU / disk | 2 vCPU / 20 GB | 2 CPUs pinned; disk **not measured** | CPU met; disk not verified | `M13/J10-envelope.json` |
| Small: 1.0 PDF target | 10-page PDF ≤ 10 s | queued p95 3.8 s, inline p95 819 ms | met | `M13/J10-pdf.json:35-38` |
| Small: 1.0 API target | every series p95 < 300 ms | 22 of 23. Miss: reviewer `submissions?status=in_review` 431.4 ms (cause not found, QUESTIONS 74) | **missed** | `M13/J10-api.json:125-129`; M13.md:97-98 |
| Small: 1.0 admin-page target | p95 < 500 ms | 25 of 31. Misses: saved reports (3.7 s, 2.2 s), explorer searches (3,988 ms, 1,805 ms), classic builder (1.0 s), reviewer inbox (512 ms) | **missed** (ADR 0083, QUESTIONS 41) | `M13/J10-admin-pages.json`; M13.md:98-99 |

2.0.0 build figures (`M13/prerelease-2.0.0.json`, revision fba09cd, built 2026-10-21 with the local harness, not published):
- App image uncompressed: arm64 509,320,532 B (the native `fsa-test-u1` build of the same Dockerfile and lockfiles at
  0e0e396; `ImageContractJourneyTest` asserted ≤ 1 GB in the 2.0.0 run). amd64 was not measured for 2.0.0
  (M9: 497,455,098 B) (E17)
- App image compressed: amd64 168,162,482 B / arm64 160,613,793 B (rc.1: 168,141,284 B / 160,592,593 B)
- Build time: 476 s (rc.1: 476.5 s)
- Multi-arch index digest: `sha256:43e7aa3c4ac271f12060dbd10fd0412c2170ae9d547efca3298bdb1c361facc4`
- Tags: `2.0.0`, `2.0`, `2`, `latest`; the release is also served from `latest/download` (stable tag rule, checked by
  `LocalPreReleaseTest` since 0e0e396)
- Install from the 2.0.0 artifacts: 46.9 s (rc.1: 48.1 s); `fsa version` reports 2.0.0 at fba09cd

## Tests and coverage
M13 gate (M13.md:67-103):
- **PHP (Pest):** 7387/7387 native, 36,566 assertions, 5 m 44 s (tree 8bd1287, after the R51 fixes).
- **Coverage** (CI image with pcov, tree 0f0cea5):
  - 94.95 % line coverage in total (32231/33947 statements).
  - SDK 99.69 %, Forms/Schema 100 %, Identity/Tenancy 100 %.
  - `app/Domain/Instance` 95.29 % (gate 90 %).
- **Static analysis:** PHPStan 0 errors, Pint clean, Rector 0 files, shellcheck clean, ESLint and tsc clean.
- **JS (Vitest):** form-schema-js 611, renderer 111, Field App 623, builder-v2 246.
- **Conformance:** PHP 391, JS 392.
- **Licenses:** npm 660, Composer 207, image binaries 7 components: all OK.
- **Audits:** npm audit 0 vulnerabilities. `composer audit` reports no advisories; it exits 2 only for one abandoned
  dev package.
- **Install journeys, native arm64:** 150 passed, 7 skipped (`LocalPreReleaseTest` without
  `FSA_PRERELEASE_VERSION`), 1 h 44 m, no leftovers.
  - amd64 emulated: J1 5/5.
  - After the R51 fixes: J11 + J08 27/27.
- **Dogfood E2E** (against a local `--demo` `fsa` instance): 71 passed, 8 skipped (dev-only services), 0 failed.
- **E2E on the dev stack** (with Keycloak):
  - Run 1: 79 passed, 8 skipped.
  - Run 2: 78 passed, 1 failed, 8 skipped. The failure was the `m7-builder-v2` flake (QUESTIONS 50); the file then
    passed 14/14 on repeat.
- **R51 agent run:** see the table above. Attempt 1 failed because of a lab-sheet error. Every finding from attempt 2
  was fixed (8bd1287) or logged (QUESTIONS 73).
- **Security and other reviews:** five reviewer subagents, no critical findings. Both high findings were fixed in
  1843f97: off-host backups need the APP key, and agent-guide commands now use `<dir>/fsa`.
- **Trivy (2026-10-21, Trivy 0.76.0, release.yml's settings):** the app image (`fsa-test-u1`, same Dockerfile and
  lockfiles as 2.0.0) has 0 fixable CRITICAL/HIGH with no exception file. mailpit, gotenberg, caddy, postgres and
  valkey pass with their files. cloudflared had a new HIGH, CVE-2026-63209 (klauspost/compress v1.18.0 in the
  binary). cloudflared 2026.10.2 still ships it, so it got a 30-day exception to 2026-11-20 (bac1caf, QUESTIONS 76).
  After that, all seven pass.

Final 2.0.0 run on `main` (§15.10 steps 1–2):
- Full gate on `main` after the M13 merge (66b9001): pint, rector, phpstan, JS, conformance, lint, typecheck, licenses,
  npm audit, shellcheck, hadolint, actionlint all exit 0; PHP suite 7387/7387; E2E on the dev stack 79 passed, 8 skipped.
- All install journeys against 2.0.0 (`FSA_RELEASE_VERSION=2.0.0 FSA_PRERELEASE_VERSION=2.0.0`), first run at 0e0e396:
  158 tests, 149 passed, 2 failed, 7 errors, 6796 s. Causes and fixes:
  - `LocalPreReleaseTest` (7 errors): `release:check-pg-dump` could not pull the arm64 image over the amd64 copy of
    the same digest ("cannot overwrite digest", Docker's classic store). This was a real `release.yml` bug and is
    fixed in ad0c61d: the image is removed between platforms.
  - J07 refusal (exit 2, not 7): the test relied on `2.0.0-alpha.1-test` being published, but the run published 2.0.0
    instead. J07 now publishes its own older release (ad0c61d).
  - Dogfood E2E: one click timeout in m6-offline journey 3, at host load 8. It passed on the rerun (QUESTIONS 76).
  - The new stable-tag assertion compared tags with a numeric sort; fixed in fba09cd.
- Reruns: LocalPreRelease, J07 and Dogfood passed 17 of 18; the remaining failure was the sort, and `LocalPreReleaseTest`
  then passed 7/7 at fba09cd. No `fsa-test-` resources were left behind after any run.
- PHP suite on `release-2.0.0` (the same tree as `main` after the merge): 7389/7389 (316 s); phpstan 0, rector 0, pint clean.

## Deviations from the specification
Important ones only. Each milestone report lists the full set under "Deviations".

| Area | Deviation | ADR |
|---|---|---|
| Release | The Compose file is JSON inside `compose.yaml` (no YAML dependency) | [0069](../adr/0069-compose-renderer-and-resource-budget.md) |
| Release | No cosign in the image (`fsa` uses the pinned cosign image); Compose and age are built from source | [0071](../adr/0071-image-binaries-from-source-and-scan-policy.md), [0070](../adr/0070-install-script-and-shim.md) |
| Release | Third-party images pass the scan with dated, scoped Trivy exceptions instead of zero fixable findings | [0071](../adr/0071-image-binaries-from-source-and-scan-policy.md) |
| Release | Release tooling is `release:manifest` / `release:version`, not `fsa:*`. The stack file is a generated, signed release asset, not a committed file | [0067](../adr/0067-release-pipeline-and-harness.md), [0076](../adr/0076-proxy-mode-and-stack-file.md) |
| `fsa` contract | Exit 1 added for internal errors. A reverted `config set` is exit 5 `rolled_back`. Missing install input is exit 2 | [0066](../adr/0066-fsa-command-architecture.md), [0078](../adr/0078-config-safety-and-agent-contract.md), [0072](../adr/0072-fsa-lifecycle-commands.md) |
| `fsa` contract | `--yes` is required on every URL change, not only `domain` | [0077](../adr/0077-fsa-config.md) |
| Modes | `acme_ca` is always set (no ZeroSSL fallback). The catcher allows `SAMEORIGIN` framing. J2 uses challtestsrv instead of CoreDNS | [0075](../adr/0075-edge-and-domain-mode.md) |
| Modes | `--proxy-network` and `--trusted-proxy` added to proxy mode | [0076](../adr/0076-proxy-mode-and-stack-file.md) |
| Mail | The capture banner shows to every member. SMTP keeps the catcher until `--remove-catcher`. STARTTLS is required by default | [0080](../adr/0080-capture-mode-and-mail-config.md) |
| Demo | `fsa demo` works only on an installed instance; a new demo is `install --demo`. `config set domain` and the local CA refuse while any account has `password` | [0081](../adr/0081-demo-data-and-passwords.md), [0055](../adr/0055-empty-first-data-and-demo-passwords.md) |
| Phones | The local CA keys live on the edge volume, not in `<instance>/certs/`. cloudflared is not a trusted proxy | [0082](../adr/0082-phone-https-local-ca-and-tunnel.md), [0074](../adr/0074-instance-runtime-state-in-the-app.md) |
| Lifecycle | `upgrade` without `--to` targets `latest`, not the newest within the major. Workers do not refuse to start behind the gate. J7 runs between harness releases | [0086](../adr/0086-upgrade-and-rollback.md) |
| Lifecycle | `install --encrypt` is refused (encryption is turned on later). `install --from-backup` gives a new instance UUID. An encrypted final backup moves only with `--accept-unauthenticated` | [0084](../adr/0084-backups.md), [0085](../adr/0085-restore.md), [0073](../adr/0073-uninstall-final-backup.md) |
| Lifecycle | `reset` resumes only through `fsa reset` | [0088](../adr/0088-reset.md) |
| Lifecycle | `adopt` copies the 1.0 volumes (R60 instead of R46's in-place takeover); Postgres 16 on Alpine only | [0087](../adr/0087-adopt.md) |
| Operability | The update check reads `latest`, so pre-releases never show as updates. `backup_silent` is shown but not mailed | [0089](../adr/0089-instance-page-and-update-check.md) |
| Operability | Recovery fields are not unified; the guide documents one reading order. J11 never runs a real upgrade (the R51 run did) | [0090](../adr/0090-agent-guide-doc-test.md) |
| Operability | The R51 subagent ran with the repository on the machine (§15.6 asks for none) | none (M13.md:134) |
| Resources | E16 was measured on Docker Desktop with a shared 2-CPU cpuset. Admin pages and one API series miss. `small` runs one Horizon worker and Postgres without JIT or parallel query | [0083](../adr/0083-e16-resource-budget-measurement.md) |
| Resources | Memory limits double under amd64 emulation | [0062](../adr/0062-minimum-hardware.md), [0070](../adr/0070-install-script-and-shim.md) |

## Open questions and blocked items
- **Blocked tasks:** none. In `docs/agent/PLAN.md` M9–M13, M13-T04 (this gate and release) is ticked with this report.
- **Open QUESTIONS that matter for the release decision:**
  - 25: the first public GHCR publish is irreversible, and the signer identity is built into every `install.sh` and
    image.
  - 27: confirm the Trivy exception policy for third-party images. 51 entries expire on 2026-11-17 (gotenberg 47,
    caddy 3, cloudflared 1), 1 cloudflared entry on 2026-11-20 (QUESTIONS 76) and 25 postgres entries on 2027-01-16 (`distribution/trivy/`).
  - 41, 74: E16 admin pages (25/31) and one API series (431 ms) miss the 1.0 targets at 100k submissions on 2 GB. The
    options are to document the data size that needs 4 GB, tune queries, or raise E16.
  - 42: margins are ~30 MiB per slot. The 320 MiB OS headroom is an estimate; `medium` and `large` are unmeasured.
  - 22, 40: phone HTTPS is demo-grade. Android trust of a user-installed CA and what real cloudflared forwards are
    unverified.
  - 35: caddy-docker-proxy labels, the Portainer/Coolify stack-file import and Traefik per-router certresolver are
    unverified.
  - 26: backups stay on the instance's host. Losing the identity file or its passphrase makes encrypted backups
    unreadable.
  - 44: the local CA keys are on the edge volume. M12 recorded no decision on carrying that volume in backups.
  - 53: the app's database user is still a Postgres superuser.
  - 65: `upgrade` without `--to` targets `latest`; there is no signed release list.
  - Low and deferred: 69, 73, 75 (review deferrals).
- **Needs humans:** real devices and screen readers, real external services, a real VPS (see the checks below).

## Publishing the first release (human steps)
From `RELEASING.md`. The autonomous build never pushes a `v*` tag or publishes (E18, ADR 0064).

1. **One-time repository setup** (`RELEASING.md:65-76`):
   1. Add a tag ruleset for `v*` that restricts creation, update and deletion to the maintainers.
   2. Create the `release` environment and restrict its deployments to tags matching `v*`.
   3. Allow the workflow permissions `packages: write`, `id-token: write` and `contents: write` (Settings → Actions →
      General).
   4. Leave the GHCR package private until the first pre-release passes the checks below. **Making it public is
      irreversible.**
2. **Prepare `main`** (`RELEASING.md:80-87`):
   - The milestone gate passed.
   - `CHANGELOG.md` has the version's entry with its date. It currently reads `2.0.0 — unreleased` (CHANGELOG.md:8).
   - `distribution/install.sh` and `distribution/shim.sh` are present.
   - Run locally: `vendor/bin/pest --testsuite=Install`, the guide checks in `tests/Unit/Docs`, and check the Trivy
     exception dates.
3. **Tag and push a pre-release first** (E18). `RELEASING.md:91-92` gives this example:
   ```sh
   git tag -s v2.0.0-alpha.1 -m "form-schema-app 2.0.0-alpha.1"
   git push origin v2.0.0-alpha.1
   ```
   The run already holds local, unpushed tags `v2.0.0-alpha.1` … `v2.0.0-rc.1` on earlier milestone commits (rc.1
   was built from c57b1ce, M12.md). Choosing a fresh pre-release tag on the 2.0.0 commit is the human's decision
   (I'm inferring; RELEASING.md does not cover this).
4. **Watch the `Release` workflow** through these jobs: prepare, image, manifest, sign, scan, tag, publish (a draft),
   verify-public, finalize (`RELEASING.md:97-109`).
5. **First release only:** `verify-public` fails while the package is private. Make the package public (Package
   settings → Danger zone → Change visibility), then re-run the failed jobs (`RELEASING.md:111-113`).
6. **Run the post-publish checklist** below on the pre-release.
7. **Then publish the stable release** with the same steps and the tag `v2.0.0`. Only a stable tag moves `latest` and
   makes the README one-liner work (`RELEASING.md:26-34`).

## Real-world checks before announcing 2.0.0
All **not yet verified**. None of them can run inside the autonomous build (§15.1).

From the `RELEASING.md` post-publish checklist (`RELEASING.md:190-234`):
- [ ] A real provider's SMTP server, once with STARTTLS (587) and once with implicit TLS (465): the test mail and an
      invitation arrive. Not yet verified.
- [ ] The German capture banner read by a real screen reader (VoiceOver or NVDA). Not yet verified.
- [ ] Stable releases only: the update check finds the published release and shows its notes link. Not yet verified.
- [ ] The GHCR package `form-schema-app` is public (irreversible). Not yet verified.
- [ ] Anonymous pull of every digest in `release-manifest.json` on amd64 and arm64. Not yet verified.
- [ ] `cosign verify` and `cosign verify-blob` with the pinned identity, from outside CI. Not yet verified.
- [ ] The one-liner on a throwaway VPS against Let's Encrypt staging, following the agent guide's "VPS install"
      recipe. Not yet verified.
- [ ] A real cloudflared quick tunnel reached from a phone: the hostname, the forwarded headers and throttling. Not
      yet verified.
- [ ] The local CA on one iOS and one Android device: trust page, CA trusted, Field App installed, offline use and
      sync. Not yet verified.

Also outstanding:
- [ ] A real ACME issuer: a Let's Encrypt production certificate after `fsa config set acme-ca production`. Only
      Pebble was used (J2, J10). Not yet verified.
- [ ] A real GHCR push and keyless cosign signing in GitHub Actions. The harness signs with a per-run local key
      (ADR 0067). Not yet verified.
- [ ] A real VPS on E16 hardware (2 vCPU, 2 GB RAM, 20 GB disk): install time, memory peaks, and the 1.0 targets at
      100k submissions. J10 ran on Docker Desktop, and disk was never measured. Not yet verified.
- [ ] The CI `install-journeys` job on amd64 (ubuntu-24.04, `.github/workflows/ci.yml:212`). It has only run
      locally, and amd64 only under emulation. Not yet verified.
- [ ] The Trivy exceptions for gotenberg, caddy and cloudflared expire on **2026-11-17** (cloudflared's second one on
      2026-11-20). After that, `scan` fails
      unless the pins are refreshed or the entries are renewed in a reviewed commit (RELEASING.md:120-124).
      Not yet verified.
