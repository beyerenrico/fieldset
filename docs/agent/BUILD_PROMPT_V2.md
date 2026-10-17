---
tags: [prompt, product-spec, docker, distribution]
source: "docs/agent/BUILD_PROMPT.md (v1.0 build), brainstorm 2026-10-17"
created: 2026-10-17
date: 2026-10-17
topic: docker-distribution-v2
status: draft
---

# Development Prompt v2: form-schema-app 2.0, install anywhere with Docker

> [!info] How to use this document
> This prompt continues the autonomous build of form-schema-app from release **1.0.0** to **2.0.0**. The agent never stops to ask. It keeps its memory in the repository and hands over to itself across context compaction (§15).
>
> 1. Optional: go through **§0 Decisions** and change any default. The agent applies whatever §0 says.
> 2. Replace `beyerenrico` (the GitHub user or organization that will publish images and releases), or leave it. If it is left in, the agent uses `form-schema-app` and records that in an ADR.
> 3. Run it in the existing repository (tag `v1.0.0` on `main`), on a machine with Docker, PHP 8.4, Node and network access to package and image registries. Grant tools through a permission allow-list.
> 4. Start a Claude Code session in the repository and say: _"Read docs/agent/BUILD_PROMPT_V2.md in full and start the autonomous build it describes."_ Do not start in plan mode.
> 5. Optional end condition: `/goal Every M9–M13 task in docs/agent/PLAN.md is done or documented as blocked, and docs/agent/FINAL_REPORT_V2.md exists`.
> 6. To resume after an interruption, open a new session and say "Resume per AGENTS.md".
> 7. Facts were checked on **2026-10-17**. Appendix A lists sources; Appendix C lists the repository facts this prompt relies on.

## 0. Decisions (defaults apply unless changed here)

The agent treats each row as decided, records it as an ADR during bootstrap (§15.9), and logs any it considers risky in `docs/agent/QUESTIONS.md` without waiting. The v1 decisions D1–D13 stay in force.

| #   | Decision                       | Default (used if unanswered)                                                                                                                                                                                                                                                                        | Alternatives                                                                      | Why it matters                                                                                                                          |
| --- | ------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| E1  | Version                        | **2.0.0**. SemVer major because installing and operating the app changes. The Form Schema Spec stays 1.0 and the API stays `/api/v1`                                                                                                                                                                | 1.1.0                                                                             | Signals the new install path and the breaking change for checkout-based installs                                                        |
| E2  | Management approach            | **The image is the installer.** All management logic is Laravel console code in the app image. It runs in a throwaway "ops" container of the pinned image that has the Docker socket and the instance directory mounted. A thin POSIX `install.sh` only checks for Docker and starts that container | (a) Shell installer plus shell helper. (b) Standalone CLI binary (Go)             | Only Docker is needed on the host. The installer is tested with Pest under the existing gates. Each version ships its own upgrade logic |
| E3  | Command name                   | **`fsa`**: a shim script in the instance directory, optionally linked into the `PATH`                                                                                                                                                                                                               | `formctl`                                                                         | Short to type and easy for an agent to call                                                                                             |
| E4  | Registry and release host      | Images on **GHCR** (`ghcr.io/beyerenrico/form-schema-app`), release assets on **GitHub Releases**. Both can be overridden (`FSA_REGISTRY`, `FSA_RELEASE_URL`) for mirrors and tests                                                                                                                 | Docker Hub; self-hosted registry                                                  | Free for public projects, multi-arch and signing support, close to the source                                                           |
| E5  | Platforms                      | Images for **linux/amd64 and linux/arm64**. Hosts: Ubuntu 22.04/24.04, Debian 12, macOS with Docker Desktop or OrbStack. Windows through WSL2 is documented but not tested                                                                                                                          | amd64 only                                                                        | Apple Silicon laptops and ARM VPS (e.g. Hetzner CAX) are common                                                                         |
| E6  | TLS termination                | A separate **`edge` service on the official Caddy image** in domain mode and for local phone HTTPS. The app keeps serving plain HTTP on 8080 inside the stack, without privileged ports or added capabilities                                                                                       | FrankenPHP's built-in ACME (needs `CAP_NET_BIND_SERVICE` and writable Caddy dirs) | One place for certificates, the local CA and the mail catcher's auth. The app container stays unchanged across modes                    |
| E7  | Modes                          | **local** (default without `--domain`), **domain** (`--domain`), **proxy** (`--behind-proxy`)                                                                                                                                                                                                       | One mode with many flags                                                          | Each target in §2.3 gets one predictable shape                                                                                          |
| E8  | Mail default                   | **Capture mode** until SMTP is configured. A bundled mail catcher (Mailpit) receives everything, its UI is protected, and admins see a banner                                                                                                                                                       | Require SMTP at install; log only                                                 | A working instance in minutes without a mail account, and no silent non-delivery                                                        |
| E9  | First data                     | Empty, with the first organization and owner. **`--demo`** adds the demo data. In domain and proxy modes demo users get random passwords, shown once                                                                                                                                                | Always demo data                                                                  | Clean by default; a public demo with the password `password` would be an open door                                                      |
| E10 | Owner access                   | A **one-time sign-in link** (single use, 60 minutes) instead of a printed password. `--owner-password-file` exists for automation                                                                                                                                                                   | Generated password printed once                                                   | No reusable secret ends up in a terminal log or an agent transcript                                                                     |
| E11 | Phone HTTPS on a local install | Off by default. `--phone-https local-ca` uses the instance's own CA, a certificate for the LAN address and a trust page with a QR code. `--phone-https tunnel` uses a quick tunnel without an account, and **stops after 4 hours** unless renewed                                                   | Only one of the two                                                               | Offline mode and install-to-home-screen need HTTPS. The user picks between a third party and installing a CA certificate                |
| E12 | Upgrades                       | Within the installed major by default. **Always a pre-upgrade backup, a health gate and automatic rollback** (previous image digests plus restore of that backup). Migrations stay forward-only                                                                                                     | Manual rollback only                                                              | An upgrade must never leave a broken instance behind                                                                                    |
| E13 | Backups                        | **Daily at 03:30 instance time, keep 7**, in the instance directory. They run inside the stack without the Docker socket. **`--encrypt`** uses a passphrase (age format, scrypt)                                                                                                                    | Weekly; offsite targets                                                           | Covers the common case with no extra service; encryption is opt-in                                                                      |
| E14 | Supply chain                   | buildx multi-arch images with **SBOM and provenance attestations** and **keyless cosign signatures** from the release workflow. A **release manifest** pins every image by digest                                                                                                                   | Unsigned images                                                                   | Users and agents can check what they run                                                                                                |
| E15 | Update check                   | **Off by default**. When on, a daily job reads the release manifest URL. Nothing else leaves the server                                                                                                                                                                                             | On by default                                                                     | "Self-hosted means private" (§2.4)                                                                                                      |
| E16 | Minimum hardware               | **2 vCPU, 2 GB RAM, 20 GB disk** for domain mode with capture mail and without Keycloak; measured, not assumed                                                                                                                                                                                      | 4 GB                                                                              | The "small VPS" promise needs a number                                                                                                  |
| E17 | Image size budget              | App image **≤ 1.0 GB uncompressed per architecture**, down from 2.12 GB today (Appendix C)                                                                                                                                                                                                          | No budget                                                                         | Pull time on a small VPS and the "minutes" promise                                                                                      |
| E18 | Releases without accounts      | The run builds and tests releases against a **local registry and a local release server**, a local ACME CA (Pebble) with local DNS, and a fake tunnel. **Publishing to GHCR and GitHub is a human step** (`RELEASING.md`), or CI on the first pushed `v*` tag                                       | Publish during the run                                                            | §15.1 forbids real external accounts; publishing is outward-facing                                                                      |

---

# PROMPT START

## 1. Your role and mission

You are a senior Laravel and platform engineer. You will take **form-schema-app** from 1.0.0 to **2.0.0**: published multi-arch images plus a self-managing install command, so that a technical owner or a coding agent can stand up, run and maintain an instance with nothing but Docker on the machine.

**You work fully autonomously.**

- No human is available. Nobody answers questions, approves plans or reviews code.
- You make every decision from this document and from `docs/agent/BUILD_PROMPT.md` (v1, still the product spec), record it, and keep going.
- Everything you need to continue must live in files in the repository, not in the conversation.

Read this whole document before you write any code. §13 says how to work. §15 says how to run without a human.

## 2. Context

### 2.1 Where the product stands

1.0.0 is complete (see `docs/agent/FINAL_REPORT.md`): Laravel 13 + Filament 5 admin, React offline-first Field App, Form Schema Spec 1.0, workflows, documents, reports, SSO, audit log, GDPR tools, `/platform` operator panel. 4,655 PHP tests, 95 % coverage, 75 E2E journeys.

### 2.2 Problem

Installing 1.0 means cloning the repository, checking out a tag and building the image on the server (`docs/guide/install.md:40`: "There is no prebuilt image in a registry"). The build needs Node, Composer and internet access, and the image is 2.12 GB. TLS needs a reverse proxy that the admin sets up on the host, because the app listens on loopback only. Upgrades and the backup scripts run from that checkout. Mail needs an SMTP account before invitations or "send to customer" work.

For the owner, that turns "let me spin up an instance" into an afternoon. For a coding agent, it is a long interactive procedure with prompts, hand-edited files and no machine-readable result. A clean install is hard to repeat and a broken upgrade is hard to undo, so people stop trying things.

### 2.3 Goal and success criteria

One command gives a working instance on any of three targets, and the same tool runs the whole lifecycle:

| Target                                                                             | Result                                                         |
| ---------------------------------------------------------------------------------- | -------------------------------------------------------------- |
| Fresh VPS with a DNS name                                                          | HTTPS instance with a valid certificate                        |
| Local machine (incl. Apple Silicon)                                                | `http://localhost` instance; optional trusted HTTPS for phones |
| Existing Docker host with a proxy or platform (Traefik, Caddy, Coolify, Portainer) | App joins it; the proxy does TLS                               |

Success criteria (measured in the M13 gate):

- **Speed:**
    - Up to 5 minutes from the install command to a usable owner sign-in link on E16 hardware. Image download time is excluded and reported separately (pull size and time at 100 Mbit/s).
    - Up to 2 minutes for `fsa demo` on a laptop with images present.
- **Agent-ready:** an agent completes every recipe in the agent guide (R51) from the guide alone, non-interactively, parsing JSON. CI proves it by executing the guide.
- **Safe:** an upgrade with a forced failure rolls back automatically and leaves data identical to before. A backup restores on another host.
- **Small:** the app image meets E17, and the stack runs on E16 hardware with the 1.0 performance targets for admin pages and the API still met at the 1.0 test volume.

### 2.4 Principles (use these to break ties)

1. **Only Docker on the host.** No PHP, Node, Git or package installs on the target machine.
2. **One command, no surprises.** Defaults work; every prompt has a flag; re-running is safe.
3. **Data safety over convenience.** Back up before anything destructive. Roll back automatically.
4. **Agents are first-class users.** Deterministic, machine-readable, no secrets on the command line or in transcripts.
5. **Self-hosted means private.** Nothing phones home by default. Third parties (tunnel, update check) only on explicit opt-in.
6. **Same quality bar as the app.** Management logic is PHP under the same tests, static analysis and review as the product.

### 2.5 Glossary (use these terms in code, UI and docs)

| Term               | Meaning                                                                                                                          |
| ------------------ | -------------------------------------------------------------------------------------------------------------------------------- |
| Instance           | One running installation: its compose project, volumes and instance directory                                                    |
| Instance directory | The folder that holds an instance's generated compose file, settings, secrets, state, backups and the `fsa` shim                 |
| Mode               | How the instance is reached: local, domain or proxy (E7)                                                                         |
| `fsa`              | The management command. On the host it is a shim that starts the ops container                                                   |
| Ops container      | A throwaway container of the pinned app image that runs `fsa` commands with the Docker socket and the instance directory mounted |
| Edge               | The Caddy service that terminates TLS and fronts the mail catcher (E6)                                                           |
| Capture mode       | Mail from the instance mailer goes to the bundled mail catcher instead of an SMTP server (E8)                                    |
| Release manifest   | JSON per release: version, image digests, asset checksums, notes URL, oldest version it upgrades from                            |
| Pre-upgrade backup | The backup every upgrade takes before changing anything; the rollback source                                                     |
| Drill              | A restore into a scratch database that verifies a backup without touching the instance                                           |
| Adopt              | Converting a clone-based 1.0 install into an instance                                                                            |
| Stack file         | A standalone compose file for Portainer or Coolify, configured only through environment variables                                |

## 3. Actors

- **A1 Technical owner (primary).** Stands up instances in minutes for themselves: on a VPS for real use, on a laptop for demos, on an existing Docker host. Comfortable with a terminal, not interested in hand-editing compose files.
- **A2 Coding agent acting for the owner (primary).** Runs commands non-interactively, reads JSON and exit codes, follows the agent guide. Must never need a secret on the command line and should see as few secrets as possible.
- **A3 SMB administrator (secondary).** Copies the one-liner from the README onto a VPS and follows the printed next steps.
- **A4 Platform Operator (existing role, ADR 0041).** Watches version, mail mode, backups and updates in `/platform`.
- **A5 Admins and field workers (existing).** Affected only by the capture banner, URLs and sign-in links.

## 4. Scope

### 4.1 In scope for 2.0.0

Published multi-arch images and a release pipeline. `install.sh` and the `fsa` command with install, doctor, status, logs, config, demo, reset, upgrade, rollback, backup, restore, adopt, login-link, tunnel and uninstall. The three modes, capture mail, local phone HTTPS, the `/platform` instance page, the opt-in update check, the agent guide and rewritten operations docs.

### 4.2 Explicit non-goals

Kubernetes or Helm. One-click images on hosting marketplaces. Native Windows. Managed hosting. Offsite backup targets beyond a local folder. Air-gapped bundles. Managing many instances from one place. Zero-downtime upgrades. Changes to the Form Schema Spec or the `/api/v1` contract. Appendix B lists candidates for later.

## 5. Stack additions (verify every version per §13.2 and record it in an ADR)

| Component                                                      | Use                                           | License (check)                      |
| -------------------------------------------------------------- | --------------------------------------------- | ------------------------------------ |
| Docker CLI + Compose plugin (static binaries in the app image) | Run by `fsa` in the ops container only        | Apache-2.0                           |
| Caddy (official image)                                         | Edge: ACME, local CA, mail catcher auth       | Apache-2.0                           |
| Mailpit (already used in dev)                                  | Capture mode                                  | MIT                                  |
| cloudflared (official image)                                   | Optional quick tunnel                         | Apache-2.0                           |
| age (binary in the image)                                      | Backup encryption                             | BSD-3-Clause                         |
| cosign (binary in the image, CI)                               | Signing in CI; optional verification by `fsa` | Apache-2.0                           |
| CNCF Distribution registry image                               | Local registry in tests                       | Apache-2.0                           |
| Pebble + CoreDNS                                               | Local ACME CA and DNS in tests                | MPL-2.0 / Apache-2.0                 |
| Traefik                                                        | Proxy-mode tests                              | MIT                                  |
| shellcheck, hadolint, actionlint, Trivy                        | CI linters and image scan (dev only)          | GPL-3.0 / GPL-3.0 / MIT / Apache-2.0 |

Dev-only tools never ship in the image. The license check (§10) covers everything that does, including binaries copied into the image.

## 6. Architecture

### 6.1 Overview

```
host
 ├─ install.sh (POSIX sh) ──► docker run <app image@digest> fsa install …
 └─ <instance dir>/fsa (shim) ──► docker run --rm  <app image@digest> fsa <cmd>
                                   mounts: docker socket, instance dir (same absolute path)
                                     │
                                     ▼ docker compose -p <instance>
   ┌──────────────────────── compose project ─────────────────────────┐
   │ edge (Caddy)* ─► app (FrankenPHP :8080)   queue   scheduler       │
   │ backup (same image, no socket)   postgres   redis (Valkey)        │
   │ gotenberg (internal network)   mailpit*   tunnel*                 │
   └───────────────────────────────────────────────────────────────────┘
   * by mode or option
```

### 6.2 The ops container (E2)

- `fsa` is a console entry point in the app image (it may wrap `artisan`). Its domain code lives in `app/Domain/Instance`, or a name you record in an ADR. It drives Docker only through an interface, with a recording fake for unit tests and the real CLI in install journeys (§11).
- The shim mounts the instance directory **at the same absolute path** inside the ops container, because the Docker daemon resolves bind mounts on the host. Files it writes belong to the invoking host user.
- The Docker socket is mounted **only** into the ops container, for the duration of one command. No long-running service ever gets it. `DOCKER_HOST` is honoured (rootless Docker, Colima, OrbStack).
- `install.sh` stays under ~150 lines. It checks Docker and Compose, resolves the release manifest, verifies the app image digest, writes the shim and hands over to `fsa install`. It contains no product logic.
- Before running a command for a newer target version, `fsa` re-execs itself in that version's ops image. The target version's code performs its own upgrade (R36).

### 6.3 Instance directory

Default `/opt/form-schema-app` when run as root on Linux, `~/form-schema-app` otherwise, or `--dir`. Contents:

- the generated compose file, never hand-edited;
- the settings file and a secrets file with mode 0600 (APP_KEY, database, Valkey and mail catcher credentials, optional backup key);
- instance state (version, mode, pinned digests, history of upgrades);
- `backups/`, `certs/` (local CA), the `fsa` shim and a `README.txt` naming the commands.

### 6.4 Modes (E7)

|                           | local                                                  | domain                           | proxy                                         |
| ------------------------- | ------------------------------------------------------ | -------------------------------- | --------------------------------------------- |
| URL                       | `http://localhost:<port>` (8080, next free if taken)   | `https://<domain>`               | as given by `--url`                           |
| Published ports           | loopback only                                          | 80, 443 (and 443/udp)            | loopback port, or none when joining a network |
| TLS                       | none, or edge with local CA (`--phone-https local-ca`) | edge with ACME                   | the existing proxy                            |
| Mail catcher UI           | loopback port                                          | `/_mail/` behind edge basic auth | loopback port                                 |
| Trusted proxies and hosts | the edge, when present                                 | the edge                         | from `--behind-proxy` and `--url`             |

### 6.5 Releases and channels

- A `v*` tag triggers the release workflow. It builds and pushes multi-arch images (tags `X.Y.Z`, `X.Y`, `X`, `latest`) with SBOM and provenance attestations, signs them keylessly, and publishes the assets.
- The assets are `install.sh`, the release manifest, the stack file, the agent guide and checksums.
- The release manifest pins the app image and every third-party image by digest. `fsa` installs and upgrades only from manifest digests, never from moving tags.

### 6.6 Compatibility

- The data model only moves forward. A 1.0.x database upgrades through the normal migrations.
- The Spec (1.0), the API (`/api/v1`) and the Field App contract do not change.
- Checkout-based installs keep working for development, but the operations docs no longer describe them.
- `scripts/backup.sh` and `scripts/restore-drill.sh` stay for development checkouts; `fsa backup` and `fsa restore --drill` replace them for instances.

## 7. `fsa` command reference

| Command                      | Purpose                                                                   | Key flags                                                                                                                                                                                                                                      |
| ---------------------------- | ------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `install`                    | Create and start an instance                                              | `--domain`, `--behind-proxy traefik\|caddy\|port`, `--url`, `--port`, `--org`, `--owner-email`, `--owner-name`, `--locale`, `--demo`, `--phone-https local-ca\|tunnel`, `--smtp-*`, `--encrypt-backups`, `--from-backup`, `--dir`, `--version` |
| `doctor`                     | Preflight and health checks                                               | `--post` (after install or upgrade)                                                                                                                                                                                                            |
| `status` / `logs`            | Services, health, version, mode, URL, mail mode, last backup, disk / logs | `--since`, service name                                                                                                                                                                                                                        |
| `config show` / `config set` | Read or change settings (R30, R31)                                        | per setting                                                                                                                                                                                                                                    |
| `login-link`                 | One-time sign-in link for an admin user                                   | `<email>`                                                                                                                                                                                                                                      |
| `demo`                       | Local instance with demo data, capture mail, opens the browser            | `--phone-https`                                                                                                                                                                                                                                |
| `reset`                      | Back to a fresh state                                                     | `--demo`, `--no-backup`                                                                                                                                                                                                                        |
| `upgrade` / `rollback`       | Change version, health-gated / return to the previous version             | `--to`, `--major`                                                                                                                                                                                                                              |
| `backup` / `restore`         | Back up / restore or drill                                                | `--encrypt`, `--include-secrets`, `--drill`                                                                                                                                                                                                    |
| `tunnel`                     | Start, stop or renew the quick tunnel                                     | `start\|stop\|renew`                                                                                                                                                                                                                           |
| `adopt`                      | Convert a 1.0 checkout install                                            | `<path>`                                                                                                                                                                                                                                       |
| `uninstall`                  | Remove the instance                                                       | `--keep-backups`                                                                                                                                                                                                                               |
| `version`                    | Versions of fsa, the instance and the newest known release                |                                                                                                                                                                                                                                                |

Every command takes `--json`, `--yes` and `--dir`.

Exit codes (default; change only with an ADR):

| Code | Meaning                                                                                  |
| ---- | ---------------------------------------------------------------------------------------- |
| 0    | Success. JSON says whether anything changed (`"changed": false` for no-ops)              |
| 2    | Invalid usage or input                                                                   |
| 3    | Preflight failed (doctor)                                                                |
| 4    | Health check failed, nothing rolled back (install)                                       |
| 5    | Upgrade failed and was rolled back; the instance runs the previous version               |
| 6    | Confirmation required (no TTY and no `--yes`)                                            |
| 7    | Incompatible (newer backup, major upgrade without `--major`, unsupported source version) |
| 10   | Docker or Compose unavailable or too old                                                 |

## 8. Feature requirements

**Images and releases**

- R1. Publish the app image for linux/amd64 and linux/arm64 with OCI labels for version, revision, source and license.
- R2. Each release has a manifest with the version, the digest of every service image, checksums of all assets, the release notes URL and the oldest version it upgrades from.
- R3. The release workflow runs on `v*` tags, attaches SBOM and provenance attestations, and signs the images and the release manifest keylessly (E14).
- R4. Third-party images (Postgres, Valkey, Gotenberg, Caddy, Mailpit, cloudflared) are pinned by digest in the manifest and refreshed only by a deliberate commit.
- R5. The app image meets E17 and contains only what the app needs at runtime: no dev dependencies, tests or agent docs.
- R6. The app image starts without errors in its logs. Today's Caddy autosave and storage permission errors are fixed.

**Install and doctor**

- R7. One command installs an instance on a host that has only Docker Engine and Compose v2: `curl -fsSL <release>/install.sh | sh -s -- <flags>`. The equivalent `docker run` form is documented for people who do not pipe to `sh`.
- R8. `install.sh` is POSIX sh and shellcheck-clean. It checks the release manifest against its published checksum and pulls the app image by the manifest's digest, never by tag.
- R9. Install generates all secrets, writes the instance directory (§6.3) and pins every image digest in the instance state.
- R10. `fsa doctor` checks:
    - Docker and Compose versions, architecture;
    - RAM and disk against E16;
    - free ports, clock skew, registry reachability;
    - in domain mode, whether the domain resolves to this host.

    Each check reports pass, warn or fail with a fix hint. It warns instead of failing when it cannot tell, for example behind NAT.

- R11. Install succeeds only when all services are healthy and a smoke test passes: `/up`, a PDF render through Gotenberg, and a test mail into capture or the configured SMTP.
- R12. Install ends with the URL and a one-time owner sign-in link, as text and as JSON.
- R13. Running install again on an existing instance changes nothing and reports `"changed": false`.
- R14. Install meets the speed criteria in §2.3.

**Modes and TLS**

- R15. Local mode serves `http://localhost:<port>` on loopback. It picks the next free port when 8080 is taken and reports it.
- R16. Domain mode obtains and renews certificates automatically through ACME. It redirects HTTP to HTTPS and keeps the 1.0 security headers. The ACME directory is configurable; `--acme-staging` uses the staging CA.
- R17. Proxy mode runs without the edge. The app listens on a loopback port or joins a named Docker network with Traefik or caddy-docker-proxy labels. Trusted proxies and hosts are set from the chosen setup.
- R18. Each release ships a stack file that works in Portainer and Coolify without `fsa`. It is configured only through environment variables, and its limits (no scheduled backups through `fsa`, manual upgrades) are documented.
- R19. `--phone-https local-ca` issues a certificate for the machine's LAN address from the instance's own CA. It serves a trust page with the CA certificate, a QR code and iOS and Android steps.
- R20. `--phone-https tunnel` starts a quick tunnel and prints its HTTPS URL. It warns that traffic passes a third party and that the instance is reachable from the internet. It stops after 4 hours unless renewed.
- R21. Changing the URL (tunnel start or stop, `config set domain`) updates the app URL and trusted hosts and restarts only the affected services. It warns that signed links and installed home-screen apps keep the old address.

**Mail**

- R22. Without SMTP settings, all mail sent through the instance mailer goes to the bundled mail catcher and none of it leaves the server. Organizations with their own SMTP server keep sending through it.
- R23. The mail catcher UI is reachable only through the edge or a loopback port, behind generated credentials, and is linked from `/platform`.
- R24. In capture mode, admins of organizations that use the instance mailer see a persistent "Mail is captured, not delivered" banner with the command that fixes it. Organizations with their own SMTP in Mail settings (`app/Filament/Admin/Pages/MailSettingsSchema.php`) see no banner.
- R25. `fsa config set mail` takes the SMTP password from stdin or a file, sends a test mail, switches to real delivery and removes the catcher. `--capture` switches back.

**First data and owner access**

- R26. A default install creates the first organization and owner from flags. Missing values are prompted in a terminal and are an error under `--yes` or without a TTY.
- R27. The owner gets a one-time sign-in link (single use, 60 minutes, audited) that leads to setting a password and offers MFA. `--owner-password-file` sets a password instead.
- R28. `fsa login-link <email>` issues a new one-time link for an existing admin user, audited as issued by the instance owner.
- R29. `--demo` adds the demo organization, forms, workflow and users. In domain and proxy modes the demo users get random passwords listed once; in local mode they keep `password`.
- R30. `fsa demo` installs locally with demo data and capture mail, and opens the browser when a desktop session exists.

**Settings**

- R31. `fsa config show` prints the effective settings with secrets redacted.
- R32. `fsa config set` changes each of these, validates it, restarts only what it must, and is idempotent:
    - domain and mode, port, `--url`;
    - operator email (grants the operator role);
    - SMTP and capture;
    - backup schedule, retention and encryption;
    - update check, ACME email and directory, trusted proxies.

**Upgrade and rollback**

- R33. `fsa upgrade` moves to the newest release within the installed major. `--to` picks a version; `--major` is required to cross a major.
- R34. Every upgrade runs in this order: doctor, read the target manifest, pre-upgrade backup, pull the pinned digests, migrations, start, health and smoke test.
- R35. If a step after the backup fails, `fsa` restores the previous digests and the pre-upgrade backup, verifies health, and exits with code 5 and the relevant logs.
- R36. The target version's code performs the upgrade (§6.2).
- R37. `fsa rollback` returns to the previous version from the last pre-upgrade backup. It first says how much data written since the upgrade will be lost.

**Backup and restore**

- R38. `fsa backup` writes the database dump, file storage, settings, a manifest with versions and checksums, and the audit chain heads (`audit:verify --heads`). Secrets are included only with `--include-secrets`, which requires encryption.
- R39. Scheduled backups run daily inside the stack without the Docker socket (E13). Their result shows in `fsa status` and `/platform`, and failures notify operators.
- R40. `--encrypt` encrypts with a passphrase set up once. The passphrase is not stored in the instance unless the operator opts in.
- R41. `fsa restore <backup>` restores after confirmation and refuses backups from a newer version. `--drill` restores into a scratch database and verifies counts, chain heads and one document render without touching the instance.
- R42. `fsa install --from-backup <file>` creates an instance on another host from a backup, including its URL and settings. It needs a backup with secrets, or the old APP_KEY from a file, because encrypted data is unreadable without it. This is how an instance moves between servers.

**Reset, status and teardown**

- R43. `fsa reset` returns the instance to a fresh state (optionally with `--demo`). It keeps settings and secrets, requires confirmation and backs up first unless `--no-backup`.
- R44. `fsa uninstall` removes containers, volumes, networks and the instance directory after confirmation. `--keep-backups` keeps the backups.
- R45. `fsa status` and `fsa logs` work in every mode and never print secrets.

**Adopting 1.0 installs**

- R46. `fsa adopt <path>` converts a clone-based 1.0.x install into an instance. It backs up first in the 1.0 backup format, reuses the install's environment (APP_KEY), volumes and data, and starts the 2.0 images. It leaves the old checkout untouched. Going back means restoring that backup with the 1.0 scripts.

**Platform**

- R47. A new "Instance" page in `/platform` shows version and digests, mode and URL, mail mode with the catcher link, backup schedule and last result, disk usage and update status.
- R48. With the update check on (E15), a daily job reads the release manifest URL and shows "update available" with the notes link and the upgrade command.

**Agent operability**

- R49. Every command supports `--json` (one JSON document on stdout, progress on stderr), `--yes`, and the exit codes in §7.
- R50. Secrets are never accepted as command-line arguments and never printed. The exceptions are the one-time outputs: the sign-in link, demo passwords and generated catcher credentials, which JSON marks as secret.
- R51. An agent guide ships with each release and in `docs/guide/`. It has recipes with exact commands, expected JSON fields and failure handling for:
    - VPS install, local demo, phone demo;
    - upgrade, backup and restore, moving servers;
    - changing the domain, configuring SMTP.

    CI executes its command blocks against the local release.

**Documentation and i18n**

- R52. Rewrite the install, upgrade, backup/restore and configuration guides for 2.0, and move the checkout-based instructions to an archive section. The README quick start becomes the one-liner. Add `RELEASING.md` for maintainers covering the first publish, signing and secrets.
- R53. All new UI text (banner, instance page, trust page, sign-in link flow) exists in DE and EN.

## 9. Key flows and acceptance examples

- F1. Fresh VPS to HTTPS instance
    - **Actors:** A1 or A3
    - **Steps:** one-liner with `--domain` and owner flags → doctor → pull → start → certificate → smoke test → URL and sign-in link → set password.
    - **Covered by:** R7–R14, R16, R27
- F2. Local demo
    - **Actors:** A1
    - **Steps:** `fsa demo` → browser opens on the demo organization → mails visible in the catcher.
    - **Covered by:** R15, R22, R29, R30
- F3. Phone demo with the local CA
    - **Actors:** A1 and a phone
    - **Steps:** `fsa install --phone-https local-ca` → scan the QR code → trust the CA → open the Field App → install to home screen → work offline.
    - **Covered by:** R19, R21
- F4. Agent-driven install
    - **Actors:** A2
    - **Steps:** agent follows the guide → `--json --yes` → parses URL and sign-in link → reports to A1.
    - **Covered by:** R26, R49–R51
- F5. Upgrade with automatic rollback
    - **Actors:** A1, A2
    - **Steps:** `fsa upgrade` → pre-upgrade backup → new version fails its health check → previous version restored → exit 5 with logs.
    - **Covered by:** R33–R36
- F6. Move to another server
    - **Actors:** A1
    - **Steps:** `fsa backup --encrypt --include-secrets` → copy the file → `fsa install --from-backup` on the new host → change DNS.
    - **Covered by:** R38, R40, R42
- F7. Adopt a 1.0 install
    - **Actors:** A1
    - **Steps:** `fsa adopt /srv/form-schema-app` → backup → 2.0 instance runs on the same data.
    - **Covered by:** R46

- AE1. **Covers R24.**
    - **Given:** capture mode, organization A on the instance mailer, organization B with its own SMTP.
    - **Then:** A's admins see the banner and B's admins do not. After `fsa config set mail`, A's banner disappears.
- AE2. **Covers R29.**
    - **Given:** `--demo`.
    - **Then:** in local mode demo users sign in with `password`. In domain mode `password` is refused and each demo user has the random password printed once.
- AE3. **Covers R13.**
    - **When:** install runs twice with the same flags.
    - **Then:** the second run exits 0 with `"changed": false` and touches no container.
- AE4. **Covers R15.**
    - **Given:** port 8080 is taken.
    - **Then:** local mode uses the next free port and reports it in text and JSON.
- AE5. **Covers R10.**
    - **Given:** the domain resolves to another IP.
    - **Then:** doctor fails with a DNS hint and install exits 3 before starting any service or pulling the other images.
- AE6. **Covers R20.**
    - **Given:** a tunnel started 4 hours ago and not renewed.
    - **Then:** it is stopped, the URL switches back to local, and `fsa status` says so.
- AE7. **Covers R35.**
    - **Given:** the target version's health check fails.
    - **Then:** the previous digests run again, the database equals the pre-upgrade backup, and the exit code is 5.
- AE8. **Covers R41.**
    - **Given:** a backup made by a newer version.
    - **Then:** restore exits 7 and changes nothing.
- AE9. **Covers R50.**
    - **When:** an SMTP password is passed as a flag value.
    - **Then:** the command exits 2 with a hint to use stdin or a file.

## 10. Non-functional requirements

**Security**

- The Docker socket is root-equivalent. Only the ops container gets it, only for one command, and the docs say so plainly.
- `install.sh` is short and readable. Its checksum is published, and a download-inspect-run alternative is documented.
- The secrets file has mode 0600. Generated secrets use a CSPRNG. Valkey gets a password (1.0 runs without one).
- Sign-in links are single use, short-lived, rate-limited and audited.
- The local CA key stays in the instance directory with mode 0600. The trust page explains that a trusted CA can vouch for any site, and how to remove it from the phone afterwards.
- The tunnel is off by default and expires (E11).
- In domain mode only the edge publishes ports. Postgres, Valkey, Gotenberg and Mailpit never publish ports.
- The milestone security reviews cover the ops container, secret handling, the supply chain, the tunnel and the local CA.

**Supply chain**

- Signed multi-arch images with attestations (E14).
- Trivy finds no critical or high vulnerability with an available fix in the app image at release time.
- The license check includes binaries copied into the image.

**Resources**

- E16 and E17 are measured, and the evidence is stored.
- Gotenberg, Postgres and the app get memory limits that fit E16. The 1.0 performance targets for admin pages and the API hold at the 1.0 test volume on E16 limits.

**Portability**

- Install journeys pass on linux/amd64 (CI) and on Apple Silicon (local run).
- WSL2 is documented but not tested.

**Privacy**

- No outbound calls by default.
- Opt-in features (update check, tunnel, ACME) name what they contact in the docs and in `fsa config show`.

**i18n and accessibility**

- New UI is in DE and EN and passes the existing axe checks (WCAG 2.2 AA).

## 11. Testing and quality gates

All v1 gates stay (`docs/agent/BUILD_PROMPT.md` §12):

- coverage of at least 80 % overall and 95 % for `Domain/Forms/Schema` and tenancy;
- Larastan level 8 (10 for the schema code), Pint, Rector;
- strict TypeScript, ESLint, Prettier, Vitest;
- the conformance suite;
- the 75 E2E journeys.

Additions:

- **Coverage:** at least 90 % for the instance domain.
- **Linters:** shellcheck (`install.sh`, shim), hadolint (Dockerfiles), actionlint (workflows), and the Trivy scan.
- **Test substitutes (no external accounts):**
    - a local registry container;
    - a static release server for `dist/`;
    - Pebble and CoreDNS for ACME and DNS;
    - a Traefik container;
    - a fake tunnel container;
    - memory-limited containers for E16.
- **Install journeys** (real Docker; CI job on amd64 plus a local run on arm64):
    - J1. Local install, sign-in link works, a smoke E2E passes, uninstall leaves nothing behind.
    - J2. Domain mode against Pebble and CoreDNS: valid chain, HTTPS redirect, only ports 80 and 443 published.
    - J3. Proxy mode behind Traefik on a shared network, plus the stack file started without `fsa`.
    - J4. Capture mode: invitation mail in the catcher, banner per organization (AE1), switch to SMTP (Mailpit standing in as the SMTP server).
    - J5. Demo in local and domain modes (AE2), reset.
    - J6. Phone HTTPS: local-CA certificate for the LAN address trusted by Playwright, service worker registers. Tunnel mode with the fake tunnel, including expiry (AE6).
    - J7. Upgrade from `2.0.0-rc.1` to `2.0.0` (both built locally), and a forced failure that rolls back (AE7).
    - J8. Backup, destroy, restore; drill; `--from-backup` on a second instance; encrypted backup round trip.
    - J9. Adopt a 1.0.0 install built from tag `v1.0.0`, then verify its data in 2.0.
    - J10. E16 limits: install, run the smoke E2E, re-run the 1.0 performance scripts (`scripts/perf/`) for admin pages and the API, and record memory peaks and timings.
    - J11. The agent guide doc-test: execute every command block, check exit codes and JSON fields.
    - J12. Idempotence and errors: AE3, AE4, AE5, AE8, AE9.
- **Dogfooding:** in the M13 gate the E2E suite runs against a local-mode `--demo` instance installed by `fsa` from the local release. Journeys that need dev-only services or settings (Keycloak SSO, insecure IdPs, config-backed DNS) keep running on the dev stack. The gate report lists which journeys ran where.

## 12. Delivery plan

Each milestone ends demoable and passes the gate in §15.6. Pre-releases are built and tested locally (E18) and tagged locally only.

| Milestone                       | Contents                                                                                                                                                                                                                                                                                                                                                                                                                                                | Pre-release     |
| ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------- |
| **M9 Release foundation**       | Image slimming (E17) and the log fix (R6), OCI labels, multi-arch build, release workflow with attestations and signing (validated by actionlint, run locally without signing), release manifest. Test harness: local registry and release server. `fsa` console app and ops container, `install.sh` and shim. Local-mode install, doctor, status, logs, uninstall, JSON and exit codes. One-time sign-in links. Non-interactive `app:install`. J1, J12 | `2.0.0-alpha.1` |
| **M10 Modes**                   | Edge service, domain mode (Pebble journeys), proxy mode (Traefik, caddy-docker-proxy, port), stack file, `config set` for domain, port, URL and trusted proxies. E16 measurement. J2, J3, J10                                                                                                                                                                                                                                                           | `2.0.0-alpha.2` |
| **M11 Mail, data and phones**   | Capture mode with protected catcher and per-organization banner, `config set mail`, `--demo` passwords by mode, `fsa demo`, `reset`, phone HTTPS with local CA and trust page, tunnel with expiry, URL switching. J4–J6                                                                                                                                                                                                                                 | `2.0.0-beta.1`  |
| **M12 Lifecycle**               | Backups (scheduled, encrypted, chain heads), restore, drill, `--from-backup`, health-gated upgrade with automatic rollback, `rollback`, adopt 1.0. J7–J9                                                                                                                                                                                                                                                                                                | `2.0.0-rc.1`    |
| **M13 Operability and release** | `/platform` instance page, opt-in update check, agent guide and its doc-test, docs rewrite, `RELEASING.md`, security review, E2E suite against an `fsa` install, final gate. J11 and all journeys                                                                                                                                                                                                                                                       | **`2.0.0`**     |

## 13. Working agreements

The v1 agreements (`docs/agent/BUILD_PROMPT.md` §14) apply unchanged, plus:

1. **Thin shell.** Shell is limited to `install.sh` and the shim. All logic is PHP behind the Docker interface (§6.2).
2. **Verify, don't guess.** Check versions and behaviour of Docker, Compose, Caddy, Mailpit, cloudflared, age, cosign, Pebble and buildx against official docs before use, and record them in ADRs (§5). Appendix A lists what is already checked.
3. **Back up before destroying.** Every destructive command backs up first unless the user skips it explicitly.
4. **Never weaken data safety for speed.** If the size budget, time budget or E16 cannot be met, record the measured numbers and an ADR. Do not trade away a backup, health gate or security control.
5. **Scope discipline.** Nothing from §4.2 or Appendix B gets built in this run.

## 14. Definition of Done (per feature)

- [ ] Requirements (R-IDs) and acceptance examples are met, with automated tests, and covered by an install journey where they touch Docker
- [ ] `--json`, `--yes` and exit codes are covered by tests
- [ ] No secret appears in argv, logs or JSON except the marked one-time outputs (tested)
- [ ] Audit events are emitted for sign-in links, operator grants and restores
- [ ] DE and EN texts exist for new UI
- [ ] Docs, agent guide and ADRs are updated
- [ ] Static analysis, linters and coverage thresholds are clean
- [ ] The task is ticked in `PLAN.md`, `STATE.md` and `JOURNAL.md` are updated, and everything is committed (§15.5)

## 15. Autonomous operation

### 15.1 Ground rules

- **No human is available.** Never end a turn with a question, never wait for approval. Things a human should see go to `docs/agent/QUESTIONS.md` with the option you chose.
- **The repository is your memory.** Anything not written to a file can be lost at the next compaction.
- **Never stop early.** The run ends only when §15.10 holds.
- **Stay inside the repository and its Docker resources.**
    - You may create containers, networks and volumes for test instances. Prefix their names with `fsa-test-` and remove them after each journey.
    - Never touch Docker resources you did not create, except the dev stack of the repository.
    - Mount the Docker socket only into ops containers of test instances.
    - Use the substitutes in §11. Never use real external accounts, real ACME, real tunnels, or push to a real registry. Tests pull instance images from the local registry. Pulling base images from public registries during builds is fine.
- **Never push a `v*` tag** to any remote. That would publish a release. Publishing stays with a human (E18).
- **Never weaken, skip or delete a test, and never lower a gate, to get green.**

### 15.2 Memory files

The v1 memory files continue:

- `AGENTS.md`;
- `docs/agent/PLAN.md`, `STATE.md`, `JOURNAL.md`, `LEARNINGS.md`, `QUESTIONS.md`;
- `docs/adr/` (continue numbering after the highest existing ADR);
- `docs/agent/milestones/M9.md` … `M13.md`, with evidence in `docs/agent/milestones/evidence/Mx/`.

This document is saved as `docs/agent/BUILD_PROMPT_V2.md` and never edited. `docs/agent/BUILD_PROMPT.md` stays the product spec for everything 2.0 does not change.

### 15.3 Restart protocol (new session, or after compaction)

1. Read `AGENTS.md`, `docs/agent/STATE.md` and `docs/agent/PLAN.md`.
2. Read the last five `JOURNAL.md` entries and `LEARNINGS.md`.
3. `git status`, `git log --oneline -15`. Finish or discard uncommitted changes per `STATE.md`. If `STATE.md` does not describe them, save them on a branch first.
4. List leftover `fsa-test-*` Docker resources from an interrupted journey and remove them.
5. Start the dev services and run the fast suite. Correct `STATE.md` if it does not match reality.
6. Re-read the sections of this document the current task depends on.
7. Continue with the first "Next actions" item in `STATE.md`.

### 15.4 Handover protocol

Update `STATE.md` (at most 60 lines, template as in v1 §16.4) and commit:

- after every task;
- before long or risky steps;
- when the conversation has grown long;
- before ending a session.

Name files, branches, commands and test-instance names exactly.

### 15.5 Work loop

For each task: re-read its R-IDs and acceptance examples, write failing tests (unit with the fake Docker interface, then an install journey when Docker is involved), implement, run focused tests, then the full suite and static analysis, check §14, and commit with the task ticked, `STATE.md` updated and a `JOURNAL.md` entry.

Git: one branch per milestone (`m9-release`, `m10-modes`, `m11-mail-phones`, `m12-lifecycle`, `m13-release`). Merge into `main` with `--no-ff` after the gate and tag `mX-done`. Never force-push or rewrite `main`.

### 15.6 Milestone gate

1. **Everything green:**
    - all §11 gates;
    - the milestone's install journeys on amd64 and arm64 (when the host has only one architecture, run the other under emulation for J1 and record it);
    - the 75 existing E2E journeys.
2. **Demo:** walk the milestone's flows (§9) with real commands, and save transcripts (JSON outputs) and screenshots under `evidence/Mx/`.
3. **Review** the diff against `main` with independent reviewer subagents, one per concern: correctness, security (§10), data safety, agent operability and docs accuracy. Fix every critical and high finding, and log the rest.
4. **Report** `docs/agent/milestones/Mx.md`:
    - what was built;
    - R-ID → test or journey table;
    - gate results with measured numbers;
    - deviations with ADR links;
    - open questions.
5. **Advance:** build the milestone's pre-release locally (§12), merge, tag, and break down the next milestone in `PLAN.md`.

### 15.7 When you are stuck

After three genuinely different failed attempts:

1. Write what you tried to `LEARNINGS.md`.
2. Mark the task `blocked` with the reason.
3. Implement the documented minimum, isolate the problem behind the Docker interface with a fake, or move to the next independent task.

Revisit blocked tasks at each gate. Platform-specific gaps (for example a Docker Desktop quirk) are documented in `docs/guide/troubleshooting.md` and `QUESTIONS.md`, not worked around silently.

### 15.8 Context hygiene

Keep tool output small. Send journey logs to `storage/agent-logs/` and read the tail. Use subagents for broad searches and reviews. Write conclusions to files before moving on.

### 15.9 Bootstrap (your first actions)

1. Save this document verbatim as `docs/agent/BUILD_PROMPT_V2.md` if it is not already there.
2. Update `AGENTS.md` (still at most 80 lines): point to both prompts and add the `fsa` and journey commands. Commit.
3. Verify the §5 versions and write ADRs for E1–E18, using the defaults unless §0 has been changed. Log risky ones in `QUESTIONS.md`.
4. Append M9–M13 to `PLAN.md` with task titles, and break M9 down fully. Each task gets an ID (`M9-T01`), its R-IDs and journeys, and a test approach.
5. Write `STATE.md` pointing at the first M9 task, create branch `m9-release` and commit.
6. Start the work loop. Do not wait for anyone.

### 15.10 Completion

The run is complete when every M9–M13 task is ticked, or every remaining one is `blocked` with a documented reason and no unblocked task remains. Then:

1. Run the full gate once more on `main`.
2. Build the 2.0.0 release artifacts into `dist/`, verify them with all install journeys against the local registry and release server, and tag `v2.0.0` locally (not pushed).
3. Write `docs/agent/FINAL_REPORT_V2.md`:
    - what was built, and how to install with one command;
    - measured results against §2.3, E16 and E17;
    - test and coverage results;
    - deviations with ADRs;
    - open questions and blocked items;
    - the exact human steps to publish the first release (`RELEASING.md`).
4. Set `STATE.md` to "Run complete" and commit.

# PROMPT END

---

## Appendix A: Sources and verification notes (checked 2026-10-17)

Verified through Context7 against the projects' own documentation:

- **Mailpit:**
    - Supports a UI password from an environment variable (`MP_UI_AUTH`, inline htpasswd) or a file (`MP_UI_AUTH_FILE`).
    - Can serve its UI under a path (`MP_WEBROOT`).
    - Accepts a host allow-list (`MP_ALLOWED_HOSTS`).
- **Caddy:**
    - `tls internal` issues certificates from a local CA. The root lives 3600 days, the intermediate 7 days and leaves 12 hours.
    - Installing the root into a trust store fails inside containers, so the root must be copied out (the trust page in R19 does that).
- **FrankenPHP:**
    - As non-root it needs `setcap CAP_NET_BIND_SERVICE` to bind ports 80 and 443, plus write access to `/config/caddy` and `/data/caddy`. Without that capability it must use a port ≥ 1024.
    - This explains the permission errors in the current app logs (R6) and is why E6 puts TLS in a separate edge.

To verify at bootstrap (unverified here):

- that Caddy's internal CA issues certificates for IP addresses;
- the terms and limits of account-less cloudflared quick tunnels (documented by Cloudflare for testing);
- Pebble and CoreDNS setup for ACME tests;
- the current CNCF Distribution registry image tag;
- keyless cosign signing in GitHub Actions;
- buildx SBOM and provenance attestation flags;
- the minimum Docker Engine and Compose versions for the Compose features used (`--wait`, profiles);
- the age passphrase mode and its license;
- the Docker socket path on Docker Desktop for macOS and OrbStack.

## Appendix B: Candidate extensions (out of scope until approved)

- Offsite backup targets (S3-compatible storage, rclone)
- Air-gapped install bundle (images saved to a tar)
- Managing many instances from one place, for IT providers (relates to v1 Appendix B5)
- One-click images on hosting marketplaces; an official Coolify template
- Kubernetes and Helm
- Zero-downtime upgrades
- Host hardening helpers (firewall, unattended upgrades)
- Keycloak as an optional production profile

## Appendix C: Repository facts this prompt relies on (verified 2026-10-17)

| Fact                                                                                                                                                               | Source                                                                                                  |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------- |
| No registry image; install clones, checks out a tag and runs `docker compose build`                                                                                | `docs/guide/install.md:40`, `:59`, `:62`, `:136`                                                        |
| app, queue and scheduler share one image built from `docker/app/Dockerfile` (`form-schema-app:local`), read `.env`, app published on `127.0.0.1:${APP_PORT:-8080}` | `compose.yaml:6-11`, `:46`, `:58-72`                                                                    |
| The app's Caddy has `auto_https off` and `admin off`                                                                                                               | `docker/app/Caddyfile:3-4`                                                                              |
| Mailpit runs only in the dev overrides                                                                                                                             | `compose.dev.yaml` (no Mailpit in `compose.yaml`)                                                       |
| Backup and restore-drill scripts locate the repository root and read its `.env`                                                                                    | `scripts/backup.sh:38`, `:113-114`; `scripts/restore-drill.sh:46`, `:154`                               |
| CI builds the image but never pushes it; no tag-triggered or multi-arch workflow                                                                                   | `.github/workflows/ci.yml:4`, `:142`                                                                    |
| Final image based on `dunglas/frankenphp:1-php8.4-bookworm`, runs as `www-data`; migrations only with `RUN_MIGRATIONS=true`; no OCI labels                         | `docker/app/Dockerfile:22`, `:45`; `docker/app/entrypoint.sh`                                           |
| `app:install` takes `--organization --name --email --password --locale` and does nothing when an organization exists (including soft-deleted)                      | `app/Console/Commands/InstallCommand.php:24-34`                                                         |
| Demo users use the password `password`; the demo seed is skipped in production                                                                                     | `database/seeders/DemoSeeder.php:30-42`; `database/seeders/DatabaseSeeder.php:12-19`                    |
| Pinned images: `gotenberg/gotenberg:8.38.1-chromium`, `postgres:16-alpine`, `valkey/valkey:8-alpine` (service named `redis`)                                       | `compose.yaml`                                                                                          |
| Organizations can configure their own SMTP server in Mail settings                                                                                                 | `app/Filament/Admin/Pages/MailSettingsSchema.php:32-47`; `app/Domain/Mail/Actions/SaveMailSettings.php` |
| `audit:verify --heads` prints each chain head as JSON                                                                                                              | `app/Domain/Audit/Console/VerifyAuditLogCommand.php:18`                                                 |
| `app:platform-admin {email}` grants the operator role to an existing user                                                                                          | `app/Console/Commands/PlatformAdminCommand.php:19-20`                                                   |
| The app healthcheck calls `/up`; queue uses `horizon:status`                                                                                                       | `compose.yaml:47-51`                                                                                    |
| No outbound call by default (Sentry only when configured; Gotenberg internal; webhooks customer-configured)                                                        | `app/Support/Logging/SentryHandler.php:43`; `app/Domain/Platform/Health/Checks/GotenbergCheck.php:31`   |
| App image 2.12 GB uncompressed, Gotenberg 1.17 GB (local arm64 build, `docker images`)                                                                             | measured 2026-10-17                                                                                     |
| The PHP SDK reads the spec files (meta-schema, roles) at runtime, so image slimming must keep `spec/form-definition/1.0`                                           | `packages/form-schema-php/src/Support/SpecPaths.php:20-25`                                              |
