# JOURNAL (append-only, ≤10 lines per entry)

## 2026-10-16 bootstrap
- Verified stack versions on Packagist/npm (all match §5; TS latest is 7.0.2, pin decided at install).
- Wrote AGENTS.md, CLAUDE.md, ADRs 0001–0015 (placeholders, stack, D1–D13), PLAN (M0/M1 detailed),
  STATE, QUESTIONS, LEARNINGS. Commit: bootstrap.

## 2026-10-16 M0 core (T01, T04–T08, T10–T12)
- Laravel 13.37 + Pest 5 + Larastan + Pint + Rector; Postgres/Redis/Gotenberg/Mailpit in Docker.
- Tenancy: Context-hidden org id, throwing scope, worker test proves restore-before-unserialize.
- Filament 5 panel w/ Organization tenant, registration/profile; Fortify headless under /auth;
  shared TOTP + recovery codes (ADR 0016); API envelope, X-Organization-Id, /me.
- Roles: capability catalog + per-org seeded roles, literal §8.6 matrix test (ADR 0017, Shield removed).
- Audit: EnrichActivity (org/actor/ip/ua/request id), Redactor, append-only model, auth listener.
- 149 tests green, PHPStan lvl 8 clean.

## 2026-10-16 M0 gate
- Members/Groups/Roles resources, invitations, app:install, demo seed, CI, licenses, Playwright E2E.
- Reviewer subagents (security, correctness): 3 high + test-proof gap fixed (93d7328): role escalation
  guard, single-use invitations, email-change verification, real Context-before-unserialize proof.
- Gate: 190 tests, PHPStan 0, coverage 90.08 % (tenancy 100 %), E2E 3/3. Report: milestones/M0.md.
- Drafted Spec 1.0 + conformance suite; ADR 0021 own JsonLogic evaluator.

## 2026-10-16 M1 implementation
- Spec 1.0 + 250 conformance cases; parallel subagents built form-schema-js (389b967) and
  form-schema-php (cd6b21c); both pass all cases, parity fuzzed (~40k inputs).
- Forms domain: forms/form_versions/form_access, immutability trigger, publish/rollback/retire/diff,
  import/export, 3 templates, lossless BuilderMapper (`_source`, ADR 0022), Filament builder pages.
- Fixes: absent-sentinel leak in FieldCodec, idempotent trigger migration, worker --memory in tests.
- 2525 tests green; PHPStan lvl 8 app / lvl 10 SDK + Forms/Schema.

## 2026-10-16 M1 gate
- Three reviewer subagents (security, correctness, SDK conformance). Fixed: clone identity/refs, typed
  defaults, numbering removal, save halt, boolean/option rules, nested groups, audit diffs, grant drift,
  diff auth; spec hardening (regex subset, limits, calc order) + 140 cases, both SDKs re-aligned.
- Gate: PHP 2837 tests, JS 604, coverage 95.62 % (SDK 99.69, Schema 100), E2E 4/4. Report milestones/M1.md.

## 2026-10-16 M2 implementation
- Backend API (151f575): submissions with client UUIDs, events, attachments, gap-free numbers,
  idempotency keys, devices, projector, status machine (ADR 0025/0026), SPA/preview serving, contract doc.
- PDF importer (db19a37, ADR 0024), Scramble OpenAPI gated (32c41ac), M3 breakdown in PLAN.
- Frontend subagent: form-schema-react renderer, Field App, preview entry (8b3f0e5; 214 tests, ~97 % cov).
- E2E (5cb36c5): Field App phone journey found a contract mismatch (submission responses not wrapped
  in `{ submission }`) → fixed backend. Preview shows title; admin E2E reuse sessions (login throttle).

## 2026-10-16 M2 gate
- Reviews (security, backend correctness, frontend races/a11y): 5 high + ~20 medium/low fixed via two
  parallel fix subagents (f6f8ccf, b084e9f) and directly (0925fa3 device sessions, 2613bde readOnlyIf).
- E2E found 3 real bugs: unwrapped `{ submission }`, repeater table widening phones, template key race.
- Gate: PHP 3016 tests, coverage 94.77 %, PHPStan 0/0, JS 606/110/164, conformance 391, E2E 9/9 ×2.
  Report milestones/M2.md. ADR 0027; QUESTIONS #9–11.

## 2026-10-17 M3 implementation
- Three parallel subagents (own test DBs, strict file ownership): mail (ede6e34), admin inbox +
  templates + CSV invitations (63d5aca), documents: branding, designs, Gotenberg renditions, builder
  PDF preview (7abcf4b, ADR 0028). Shared routes/web.php committed per batch by staging a split copy.
- Contract for send-to-customer (b56114a); backend + Field App agents implement it in parallel.

## 2026-10-17 M3 gate
- Integration: send to customer (API + Field App + admin), inbox documents, company details single
  source, demo branding; E2E journey submit → PDF → send → Mailpit → inbox.
- Reviews (security, correctness, UX/a11y): 3 high + ~25 medium/low fixed by two fix subagents
  (9589753, c3051c6). Gate also fixed tooling: Mailpit for worker, coverage container services, CI Rector
  drift (0f68661).
- Gate: PHP 3346 tests, coverage 95.09 %, PHPStan 0/0, Rector/Pint clean, JS 606/110/213, E2E 12/12.

## 2026-10-17 M4 gate + MVP 0.1.0
- Three parallel agents (workflows, notifications, Field App) → 2c5404c, 619e833; demo workflow;
  E2E journeys 1, 4, 5. E2E found 3 real bugs (empty builder keys, stale version history, status in
  input_hash → renderer 1.2.0).
- Reviews: no critical/high; ~25 medium/low fixed (47fcef4, 58d6e8e).
- Gate: PHP 3594 tests, coverage 94.84 %, PHPStan 0/0, Rector/Pint clean, JS 606/110/297, E2E 18/18 ×2.
  CHANGELOG + release notes; tag v0.1.0.

## 2026-10-17 M5 gate
- Two parallel agents (reports, dashboards) → beb328b, 521fa13; integration: pins, Filament export route
  guard, trigram search (a1dc346); E2E M5 journey; custom views inline-styled (no theme, LEARNINGS #42).
- Reviews: no critical; 3 high + ~20 medium/low fixed by one fix agent (34dcabe), which also found scheduled
  mails failing on lazy loading for orgs with several reports.
- Gate: PHP 3766 tests, coverage 94.98 %, PHPStan 0/0, Rector/Pint clean, JS 606/110/297, conformance
  390/391, E2E 20/20 ×2 on fresh seeds.

## 2026-10-18 M6 gate (offline-first)
- Architecture agent → ADR 0035; foundation (Dexie db, triggers, stubs) by me; three parallel agents
  (sync core, PWA/catalog/attachments, backend/session) + an integration agent.
- E2E found 3 real bugs: offline new draft (a371724), renderer focus steal since M2 (3f38652), login
  limit → third demo worker. Reviews: 4 high security/correctness + 4 high UX; all fixed (c597dde, d6479da).
- Gate: PHP 3799, coverage 95.00 %, PHPStan 0/0, Rector/Pint clean, JS 608/111/557, conformance
  391/392, E2E 24/24 ×2 (--workers=2: antivirus load ~100). Release 0.2.0.

## 2026-10-18 M7 gate (SSO + Builder v2)
- Two design agents → ADR 0036 (SSO), 0037 (Builder v2); skeletons by me/an agent; five parallel agents
  (SSO core/admin/Field App+Keycloak, Builder server/client) + an E2E fixer.
- E2E found: admin login step actions, libcurl *.localhost (pinning), discovery throttle per IP, palette
  drop index + inspector not following inserts. Reviews: 1 high security (IdP repoint by Admin), 3 high
  UX/correctness; all fixed (c1b0c5a, 2a10f1b, 67b582e, c2a00a5).
- Gate: PHP 4303, coverage 95.10 %, PHPStan 0/0, Rector/Pint clean, JS 608/111/614/243, conformance
  391/392, E2E 34/34 ×2 with Keycloak.

## 2026-10-19 M8 phase 2
- G3: CSP guard on all journeys (no violations found), 36 axe scans + keyboard walkthroughs (2 fixes,
  2 accepted exceptions), anonymized PDF 410, canvas builder default (ADR 0044), web push deferred (0045).
- Orchestrator: dashboard links follow the builder default; 4xx ApiExceptions not reported; dev Postgres
  max_locks_per_transaction=256 (parallel drop flake). QUESTIONS #5, #8 resolved.
- Started G6 (perf, Docker-exclusive), G7 (docs 1.0) and the three gate reviews.

## 2026-10-19 M8 gate reviews + fix batch
- Reviews: security 2 medium/6 low, correctness 6 medium/6 low, UX 2 high/9 medium/6 low; all fixed by
  F1 (privacy), F2a (audit), F2b (platform/security) plus orchestrator follow-ups, each with tests.
- Decisions: chain stays unkeyed (shipped heads = tamper evidence, ADR 0038); admin idle 30 min /
  absolute 12 h (ADR 0040); app port on loopback, TRUSTED_PROXIES=172.16.0.0/12; users.password_known.

## 2026-10-23 M8 gate
- G6 perf: 100k submissions; fixed saved reports, reviewer inbox, explorer search, API list, Field App
  repeater evaluation (b4de720). Exceptions accepted in ADR 0046 (classic builder, devtools-throttled first
  load, 501st row). Docs: CHANGELOG/release notes numbers, explorer search, audit REVOKE (verified on PG).
- Gate found: CSP E2E expected the old label "Klassischer Builder"; `composer audit` failed on a newly
  abandoned dev-only package (ignored by name, verified with Composer 2.10.3 in the CI image).
- Gate: PHP 4652, coverage 95.11 %, PHPStan 0/0, Rector/Pint clean, JS 611/111/623/246, conformance
  391/392, E2E 75/75 ×2 with Keycloak. Version 1.0.0.

## 2026-10-23 Release 1.0.0
- Merged m8-hardening (28fad62), tag m8-done. Final full gate on main: PHP 4652, coverage 95.11 %,
  PHPStan 0/0, Rector/Pint clean, JS 611/111/623/246, conformance 391/392, licenses/audits clean,
  E2E 75/75 ×2. Release date aligned to 2026-10-23 (ADRs 0038 amendment, 0046 precede it).
- FINAL_REPORT.md written; STATE "Run complete"; tag v1.0.0.

## 2026-10-17 v2 bootstrap, M9-T01, M9-T02
- Bootstrap: BUILD_PROMPT_V2 + plan committed (cb4a292), AGENTS.md points at both, M9–M13 in PLAN.md.
- ADRs 0047–0064 for E1–E18 (versions checked today); QUESTIONS 22–26. Binary sizes are ~2× the plan's estimate.
- U1 image: allow-list .dockerignore, toolchain purge + flattened base, per-arch pinned binaries (Docker CLI
  29.9.1, Compose 5.6.0, PGDG client 16.15, age 1.3.2 + batchpass, cosign 3.1.3), ops/backup roles skip optimize.
  Size 2.12 GB → 648 MB (arm64 native, amd64 emulated), cosign kept (ADR 0065). Install suite added,
  excluded by `defaultTestSuite`. Image contract journey 6/6 on both archs.

## 2026-10-18 M9-T03 instance domain core (U2)
- app/Domain/Instance: DockerGateway (CLI argv via CommandRunner), versioned InstanceState, InstanceDirectory
  with marker and safe writes, per-service EnvFiles, InstanceLock (holder container ID + start time), journal,
  Supervisor, CommandResult/ExitCode (+1 internal error, ADR 0066), FsaCommand base with secret-flag guard.
- 224 tests, coverage 97.6 % for the namespace, PHPStan 0, Rector 0. tests/Arch added to the Unit suite.

## 2026-10-18 M9-T04 compose renderer (U3)
- ComposeRenderer (per-service definitions; edge/tunnel/backup/proxy extension points), ResourceBudget (small
  1728/1728 MiB worst case, estimates until U15), RuntimeWriter (0755/0644, gate kept on re-render),
  InputGrammar on every state value. Compose file is JSON (no symfony/yaml), all env in 0600 env files.
- Valkey now password-protected with a labelled volume. 352 instance unit tests, real `compose config` journey.

## 2026-10-18 M9-T05 sign-in links (U6), M9-T06 release pipeline (U7)
- U6: app:install --owner-link/--password-file/--json, identity:login-link(s) for fsa one-offs, fragment+POST
  landing, Horizon behind operator + /platform session (ADR 0068). Feature suite 1752/1752. Fixed the nightly
  audit date-range test (display time zone).
- U7: release.yml (buildx SBOM/provenance, cosign keyless, Trivy, anonymous pull), release:manifest and
  release:version, distribution/manifest.schema.json + release.json, harness (registry:3, release server,
  local cosign key), CI linters + install-journeys job, RELEASING.md, ADR 0067. Harness journey 7/7.
- Trivy gate red on upstream findings (QUESTIONS 27), decided at the M9 gate.

## 2026-10-18 M9-T07 install.sh, shim, manifest verification (U4)
- distribution/install.sh + shim.sh (dash/shellcheck clean, truncation-safe), host-facts env contract, ops
  container user/mount rules, nonce + mounted-path guard (LaunchGuard), file-flag parent mounts, open_url via a
  0600 file. ManifestVerifier runs the pinned cosign image against the built-in identity; harness flavour
  (`build=harness`, image `FSA_BUILD`) is the only way to use FSA_TRUST_KEY or http. ADR 0070, QUESTIONS 28.
- Bootstrap journey 8/8 (fsa install replaced by a probe until U5), Unit suite 3546/3546, coverage 98.34 %.
- Trivy research: all 34 app-image findings fixable by building Compose/age from source with Go 1.27.2 (+ x/net,
  grpc bumps); third-party pins already newest. Decision for the gate below (M9 supply-chain task).

## 2026-10-18 M9-T08 lifecycle commands (U5)
- fsa install (journaled phases doctor→secrets→state→pull→up→migrate→app_install→start→smoke→done, resumable,
  idempotent, port fallback), doctor (12 checks incl. permissions, DNS preflight), status, logs, version,
  uninstall (--keep-backups, --orphans), login-link via socket-less one-offs. ADR 0072.
- J1/J12/Bootstrap 17/17 (J1 install 45 s), Unit+Feature 5421/5421, coverage 98.12 %. `--version` clash fixed
  in the fsa wrapper. QUESTIONS 29–31.

## 2026-10-18 M9-T09 gate M9 → 2.0.0-alpha.1
- Five reviewer subagents: 6 high findings fixed (wrapper entry path, `--orphans` on a live instance, host /tmp
  over DOCKER_CONFIG in the socket container, three docs), most mediums/lows fixed, rest in QUESTIONS 27–33.
- Supply chain: cosign dropped, Compose/age built from source (app image 0 Trivy findings, 507/497 MB), scoped
  dated exceptions for third-party images (ADR 0071). DOCKER_DEFAULT_PLATFORM honoured: J1/J12 pass under amd64.
- Gate: PHP 5560 (cov 95.41 %, Instance 98.15 %) / 5590 host, journeys 33/33 arm64 + J1/J12 amd64, E2E 79/79 ×2,
  static/JS/conformance/licenses green. alpha.1 built, signed, installed and uninstalled locally (599 s).

## 2026-10-18 M10-T01 runtime state in the app (U10)
- App\Domain\Instance\Runtime: strict runtime.json grammar, reader with mtime/inode/size cache, request-origin
  URL root for allowed hosts, fail-closed gate with per-operation smoke token (internal network only, /up from
  loopback exempt), tunnel peer path policy (allow-list in ADR 0074), expired tunnel 503, __Host- cookie,
  JobProcessing listener, RuntimeCheck on /platform health. Full suite 5726/5726, Instance coverage 98.08 %.
- Mailpit forward_auth spike: works with edge-injected Basic auth (LEARNINGS 103).

## 2026-10-18 M10-T03 proxy mode and stack file (U9)
- fsa install --behind-proxy traefik|caddy|port: only the app joins the external network, exactly one trusted
  proxy IP (resolved via DockerGateway, never DNS; `--trusted-proxy`, `--proxy-network`), catcher on loopback.
  `release:manifest --stack-file` renders an env-only stack file from the same pins (signed asset). ADR 0076.
- J3 5/5 native (221 s) and amd64 emulated; J1 regression green; Instance coverage 98.21 %.

## 2026-10-18 M10-T02 edge service and domain mode (U8)
- Caddy edge (UID 65532, NET_BIND_SERVICE only, read-only root, fixed address on a /28 outside Docker's pool =
  the app's only trusted proxy), CaddyfileRenderer, fsa install --domain with ACME flags (LE prod default,
  --acme-staging, --acme-ca/-root), `/_mail/` behind forward_auth to /_fsa/catcher-auth (operator /platform
  session with MFA, `platform.catcher_opened`), edge-injected Basic auth to Mailpit. ADR 0075.
- J2 8/8 native (194 s) and amd64 emulated (pre-merge); J1+J2+J3 26/26 after the U9 merge; unit 1042 green.

## 2026-10-18 M10-T04 fsa config (U11)
- `fsa config show|set`: appliers for domain (+ ACME options), url, port, trusted-proxy, mode local, acme-email,
  acme-ca; journaled snapshot→preflight→apply→verify→audit, revert on failure (`reverted:true`, exit 4; exit 8 if
  the revert fails), `changed:false` without Docker calls, `--yes` for URL changes with SSO/signed-link impact
  list, `instance.config_changed` audit via one-off. ADR 0077.
- ConfigDomainSwitch 9/9 ×2 (local→domain 40 s, failed ACME reverts), J1/J2/J3 regressions green, full suite
  6002/6002, Instance coverage 98.03 %.

## 2026-10-18 M10-T05 gate M10 → 2.0.0-alpha.2
- Five reviewers: 2 high (config cert wait without progress/interruption safety; exit 4 retryable for every
  revert) fixed in ADR 0078 (PHP loop with NDJSON per attempt, signal handlers revert, exit 5 rolled_back), plus
  ~30 mediums/lows (runtime URL scheme/port, edge headers, CA-root label, isolated migrations, launcher flags,
  config safety, drift check) in ADR 0079 and amendments.
- Gate: PHP 6089 (cov 95.55 %, Instance 97.77 %), journeys 62 native + J1/J2/J3/J12 amd64, E2E 79/79 ×2, static
  green. alpha.2 built, signed, installed, uninstalled (LocalPreRelease 7/7). M11 broken down.

## 2026-10-18 M11-T02 demo data and password rotation (U13)
- DemoSeeder with an injectable password source; `fsa install --demo` (demo phase), `fsa demo [--open]
  [--rotate-passwords]`; random demo passwords (secrets) whenever reachable beyond loopback, `password` only for
  loopback-only local; rotation revokes sessions/remember/API tokens; `app:demo-passwords --check` for U14. ADR 0081.
- J05 7/7 (400 s), J01 regression green, Instance coverage 97.81 %.

## 2026-10-18 M11-T01 capture-mode experience (U12)
- Capture banner (two variants, R59; operators see the fsa command), MailCheck warns in capture mode,
  `fsa config set mail smtp|capture` (password via stdin/file, test mail verify, revert with exit 5, catcher kept
  until --remove-catcher), `config set operator-email [--revoke-previous]`, `config show --catcher-login`. ADR 0080.
- J04 9/9 native (383 s) and amd64 emulated (865 s), axe 3/3; Instance coverage 97.43 %. Gap: install --smtp-*.

## 2026-10-18 M11-T03 phone HTTPS (U14)
- Local CA (`--phone-https local-ca`, `--lan-address`, `config set phone-https|lan-address`), trust page served by
  the app (plain HTTP + HTTPS, no session), `fsa tunnel start|stop|renew` with expiry cleanup, fake tunnel harness. ADR 0082.
- cloudflared is not a trusted proxy (its X-Forwarded-Host made Symfony answer 400 before the host pin).
- J06 12/12 native (646 s), amd64 emulated 10/12 then 12/12 (port-80 reset, unexplained); J01+J02 13/13;
  Instance coverage 96.99 %. Interrupted once by the usage limit; resumed from the saved WIP.

## 2026-10-18 M11-T04 E16 measurement (U15)
- J10: domain mode + capture mail, whole stack pinned to 2 CPUs (cpuset on every container), compose memory limits
  only; smoke E2E, 10-page PDF, 1.0 perf scripts at 100k. No OOM kill, no restart. ADR 0083.
- Met: install 47 s (+6 s pulls) vs 300 s, PDF p95 4.0 s vs 10 s, API 23/23 < 300 ms. Missed: admin pages 23/31
  < 500 ms (saved reports, explorer search, reviewer inbox; Postgres cache ~100 MiB vs 1.27 GB data) — QUESTIONS 41.
- small budget retuned from measured peaks (+~30 MiB each; Horizon 1 worker; Postgres 160; sum 1728/1728). 6 runs.
