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

## 2026-10-19 M11 gap: install --smtp-* and the full R11 smoke test
- `fsa install --smtp-host … [--smtp-username + --password-file|--password-stdin]` installs straight into SMTP mode
  (no catcher); shared `SmtpInput` with `config set mail`; install.sh refuses `--password-stdin` without a terminal.
- Install smoke now renders a PDF (`instance:pdf-test`) and sends a test mail (`instance:mail-test --install`),
  found in Mailpit by Message-ID in capture mode; `config set` verify keeps SmokeTest only. Amendments 0070/0072/0080.
- J01–J06 green natively (J04 10/10 incl. SMTP install); J06 now names its spec (U15 widened fsa-instance).

## 2026-10-19 M11-T05 gate M11 → 2.0.0-beta.1
- Review: 5 reviewers, 4 high (tunnel journal loop, rotation before preflight, capture revert losing SMTP, demo
  `password` exposed by `config set domain`) + 13 medium; fixed by agents A–D in worktrees, merged `--no-ff`.
- Gates: static/JS/licences green; coverage 95.51 % (Instance 97.36 %); Install suite native 103/103 (+7 pre-release
  skips); amd64 emulated J1–J6/J12 51/51 and J10 9/9 (J10 now expects ADR 0070's doubled limits).
- E2E 79/79 twice with --workers=2; 5 workers flaked under host load ~12 (Teams, Defender): spec sync fixes, Q49/50.
- Pre-release 2.0.0-beta.1 7/7 (test no longer hard-codes channel alpha). CHANGELOG beta.1 + missing alpha.2.

## 2026-10-19 M12-T01 U16 backups
- One worktree agent: backup service (dropped caps, no socket), `fsa backup`, snapshot-shared dump/heads/refs, HMAC
  + SHA256SUMS last, retention, `backup_runs` via insert-only `fsa_backup`, age encryption, `audit:verify --heads`
  epoch, `config set backup-*`; ADR 0084. J08 backup half 5/5; service peak 68 MiB (< 128 MiB slot, no J10 rerun).
- Two reviewers: 1 high (safety backups encrypted at write), 4 medium (plaintext `.partial`, skip reasons, dead
  service invisible on /platform, first-run space estimate 0), lows (boot-local lock id, prune tombstone, deadlines,
  symlinked-parent identity path, tar `--`, DST double run): all fixed by the same agent, merged; 3 left for U17.
- Full-suite flake: `ownerOf` unordered `first()` (fixed). Q51–52 (key rotation, install --encrypt).

## 2026-10-19 M12-T02 U17 restore
- One worktree agent: `fsa restore` (journaled steps, abandon, resume), safe tar reader, HMAC from outside keys,
  restricted-role restore + schema audit for unauthenticated archives, R64 carry, drill, `install --from-backup`
  (1.0 `.env` allow-list), webhook hold; ADR 0085. Instance coverage 93.34 %.
- Reviewers: 5 high (gate left up after resumed clean-up, unjournaled swap-back, other instance's backup in place,
  gate lowered before full start, extension-member audit bypass), 8 medium, 7 low. Verifier: 1 high (carry took every
  row missing from the live DB as an admin removal), 2 medium, 4 low. All fixed with tests; carry by state only while
  the live DB descends from the backup, else an audit/timestamp window (Q57).
- J08 found the Valkey flush never worked (image appends VALKEY_EXTRA_FLAGS). Merges be7b56b + 45213cd; code equals
  the tested branch: suite 6803/6803, J08 12/12, J01 5/5, J12 3/3, static clean.

## 2026-10-19 M12-T05 U23 reset
- One worktree agent: `fsa reset` (journaled quiesce, ledger, safety backup or audit export, recreate, fresh volume,
  epoch + `instance.reset`, seed re-run, demo policy), resume after the drop; ADR 0088. Instance coverage 93.45 %.
- Review (13): 3 high (old signed URLs resolved new rows after sequences restarted; way back not via reopenPrevious;
  way back unjournaled), rest medium/low (failed drop counted as past it, flush optional, leftovers, carry after a
  reset, confirmation outside the lock, backup during an open reset, hints). Verifier: A1 partial, 2 medium (retake
  decided from live containers; superseded safety backup left plain), 7 low (login-link wrote during a reset, …).
- All fixed with tests; `app:install` became one transaction. Merge a650d68: suite 6874/6874, static clean; J05 10/10.
- Coverage recipe fixed: `scripts/coverage.sh` lacked REDIS_PORT/MAIL_* overrides; `--env-file` broke phpunit env (L140).

## 2026-10-20 M12-T04 U19 adopt
- One worktree agent: `fsa adopt <checkout>` (allow-listed `.env`, KTD20 order, 1.0-format backup, clean Postgres stop,
  copy into labelled volumes, counts + heads + amcheck, start 2.0), go back to 1.0 on any failure before start;
  uninstall of an adopted instance: final backup + writes since adopt; J09 from a v1.0.0 build; ADR 0087.
- Review: 1 high (no check that 1.0 stays stopped: a resume could finish on a stale copy), 4 medium (APP key in the
  plain adopt backup, late baseline, uninstall deleting the adopt backup, Postgres image unchecked), 7 low. Verifier:
  A1/S1 partial, 2 medium (no fence once start began; final backup without the key), 4 low + restore trust widened
  (fingerprint from an unauthenticated manifest; now only for the instance's own adopt backup). All fixed with tests;
  first round not strictly red-first (mutation-checked instead). Merge ea98143: suite 6984/6984, static clean; J09 6/6.

## 2026-10-20 M12-T03 U18 upgrade and rollback
- One worktree agent: `fsa upgrade` (detached supervisor, socket-less child, lock_timeout + liveness, staged target
  files, automatic rollback through U17's atomic path), `fsa rollback` (versioned R64 export), older backups in place
  through the child, J07 between harness releases (AE7 local + proxy); ADR 0086. Instance coverage 92.92 %.
- Security review: 2 medium (safety backups plain on failure paths; staged files trusted) + S3 (U17 SchemaAudit
  ignored missing objects). Adversarial: 5 medium (in-flight requests after the snapshot, rollback re-halting a live
  instance, target env reused, target-only services left, space check without backups), 7 low. Verifier: 1 medium
  (exit 9 left the journal open), 4 low. All fixed with tests; the adopt backup now restores in place via the child.
- Merge 48630b6: suite 7165/7165 (one SearchPlanTest planner flake in a first run, Q68), static clean; J07 8/8.
- Flake fixed: DistributionScripts removed the shared test parent dir under --parallel (e27a329, L148).

## 2026-10-20 M12-T06 gate: review fix rounds A, B, C
- 5 gate reviewers: 5 high (S1 partial-set tampering, D1 safety backups deleted, O1 from-backup journal taken over,
  O3 upgrade outcome lost, DOC12 final-backup advice) + mediums/lows. Fix agents A (backups/security) and B
  (recovery/operability/docs) in worktrees; A merged 27b9a64 (suite 7196/7196, J01/J08/J09/J12 green).
- Verifier of A: R1 high restore-time TOCTOU (pre-existing), R2-R4 medium/low → agent C. A host restart killed B and
  C before their final runs; finished by hand: B's last change committed, merges 60d7f2d (B), b2f719f (C; ADR 0085
  conflict kept both; ImageContractTest expects O10 exit_code). Suite 7269 (5 stale expectations fixed), static clean.
- Deferred: QUESTIONS 69-72; U21 guide list → M13-T02.

## 2026-10-20 M12-T06 gate closed, 2.0.0-rc.1 built locally
- Host load (Spotlight + Defender scanning Docker.raw) stalled Docker; the human added Defender exclusions.
- Install run 1: J10 service list predated the backup service (fixed 76bad60). Run 1's evidence lost to a broad
  `git checkout` of evidence/ (L159); run 2 regenerated it (132/140, one J10 ERR_NETWORK_CHANGED, rerun 9/9).
- J1+J12 amd64 8/8; E2E 79 passed twice; LocalPreReleaseTest 2.0.0-rc.1 7/7. Merge to main, tags local only.

## 2026-10-20 M13-T01/T02: U20 and U21 merged
- U20 (agent, worktree): /platform Instance page, `fsa config set update-check`, backup alert mails (R39), ADR 0089.
  Merge 31a617d, suite 7341. U21 (agent): agent guide, 2.0 guides + 1.0 archive, J11 13/13, guide scan, ADR 0090.
  Merge 511b3bb (docs aligned with U20), suite 7355.
- J11 found a missing --identity-file bind-mounted as a root-owned host directory: fixed 0bcd910 (identity_unreadable).

## 2026-10-20 M13-T03: U22 dogfooding, CI journeys, R39 check, J10 backup (branch m13-u22)
- E2E against a local --demo instance (DogfoodE2ETest, E2E_TARGET/E2E_COMPOSE_PROJECT/MAILPIT_AUTH): 71 passed,
  8 dev-stack-only skips (m7-sso 5, m7-saml 1, m4-onboarding 1, a11y SSO choice 1), Instance page axe passed.
- Dev stack (as found, image predates U20): the 8 pass; only the U20 Instance step fails there (stale image).
- release:check-pg-dump in release.yml and the harness (ADR 0091). CI install-journeys: Playwright, poppler, v1.0.0.
- J10 13/13: backup at 1.0 volume unreclaimable 77.7 MiB of 128 (met), memory.peak 128.3 is page cache, no OOM.

## 2026-10-20 M13-T04 gate
- 5 gate reviewers: 2 high (D1 off-host APP key, A1 bare `fsa`), mediums/lows; fixed 9151b36, bee8620, 1843f97;
  lows logged (QUESTIONS 75). Suite 7377; install journeys 150 passed / 7 skipped (1 h 44 m); J1 amd64 5/5;
  dogfood E2E 71 passed; dev-stack E2E 79 (one builder-v2 flake in run 2, repeat 14/14).
- R51: a fresh agent with only the guide ran every recipe (attempt 1: lab sheet lacked FSA_REGISTRY/FSA_TRUST_KEY).
  15 + 11 findings, none high; one worktree agent fixed F1, F2, F4, F14 and the signature hint red-first plus the
  guide (8bd1287). Suite 7387; J11 + J08 27/27. J10 API reviewer in_review 431 ms (QUESTIONS 74).

## 2026-10-21 2.0.0 release (§15.10)
- Merged m13-release (66b9001, `m13-done`); full gate on main green; suite 7387, dev-stack E2E 79.
- All install journeys against 2.0.0: 149/158 first run. Fixed release:check-pg-dump multi-platform pull (real
  release.yml bug) and J07's older-release setup (ad0c61d), the stable-tag sort (fba09cd); reruns green, 2.0.0 multi-arch
  built at fba09cd (476 s, install 46.9 s). Dogfood m6-offline flaked once at load 8.
- Trivy: cloudflared CVE-2026-63209 unfixed in 2026.10.2 → 30-day exception (bac1caf, QUESTIONS 76). Suite 7389.
- FINAL_REPORT_V2.md written; merged release-2.0.0, tagged `v2.0.0` locally (not pushed). Run complete.

## 2026-10-21 Rename U8: gate for 2.1.0 (branch rename-fieldset)
- ADR 0092 and CHANGELOG 2.1.0 (220617a); journeys accept `FIELDSET_EVIDENCE_MILESTONE=R2.1`.
- Static, licenses and audits clean; suite 7403/7403; Vitest 615/111/622/246; conformance 391/392; E2E 79 + 8 skips.
- Install journeys at 2.1.0-alpha.1-test 151 passed / 7 skipped (1 h 42 m), dogfood 71; 2.1.0 multi-arch 7/7 (495 s);
  J1 amd64 5/5; J1 + J7 at 2.1.0 13/13. No test resources left. fieldset-ci rebuilt (apt fine now).
- Trivy red: CVE-2026-111943 (Go stdlib, published today) in every Go binary; pins as in v2.0.0. Merge and tags held
  (QUESTIONS 77). PDF fixtures left (encrypted variants not reproducible).

## 2026-10-21 Go x509 fix and 2.1.0 cut (branch sec-go-x509, human decision QUESTIONS 77a)
- golang 1.27.3, FrankenPHP rebuilt with Go 1.27.3, Docker CLI 29.9.2 (Go 1.26.10), cloudflared 2026.10.2 (Go 1.27.3;
  libssl entry dropped); postgres/gotenberg/mailpit/caddy have no fixed image: 30-day entries to 2026-11-20 (ADR 0093).
- Trivy green (app both arches, no exceptions). Suite 7404; JS/conformance unchanged; install journeys 151 passed /
  7 skipped (1 h 45 m), dogfood 71; 2.1.0 multi-arch 7/7 (2nd attempt: font CDN timeout); J1 amd64 5/5; J1+J7 at 2.1.0 13/13.
- E2E dev stack 79 / 8 skips (run 1: m6-offline flake). Merged into rename-fieldset and main; tags rename-done, v2.1.0 local.

## 2026-10-21 First CI run on GitHub after the rename (branch ci-services)
- The human renamed the repo and pushed main; CI php-tests failed 16/7404: no Redis (63799) or Mailpit (18025)
  service, no built frontend (Vite manifest, /app shell 503), imagick on the runner made the MFA QR code PNG.
- php-tests now starts valkey and mailpit services and runs `npm ci && npm run build`; Google2FA is bound with an
  SVG backend (new test pins it). Suite 7405/7405, phpstan, rector, pint, actionlint clean. v2.1.0 tag unchanged.

## 2026-10-22 CI green on GitHub (branch ci-e2e-scheduler; the human allowed pushing)
- main d362fc1f: e2e and J03 failed `up --wait` ("scheduler has no healthcheck configured": the runner's Compose and
  `healthcheck: disable: true`); J07 onwards lost the harness release server's host port after `docker network connect`.
- Fixes: scheduler without a disabled check (compose.yaml, AppRoles), release server on the default bridge from the
  start, reveal test under fake timers (js-tests "document is not defined"), V1Install::up() for J9's 1.0 tag stack,
  full redacted Dogfood failures in the assertion message (one m7 drag spec failed once, passed before).
- Verified on side branches, J9 7/7 locally. Merged into main 963c07c0: run 39685402735 green in every job (journeys
  148 passed, 7 skipped, 3 warnings, 2 h 10 m).


## 2026-10-22 Classic-builder slot; install journeys out of push CI (ci-classic-builder-lock, ci-nightly-journeys)
- Run 39700054160 failed two Dogfood specs that open the classic builder together (no HTTP answer); inferred OOM kill at
  small's 384 MiB. ClassicBuilderSlot serializes renders, 503 after 20 s (ADR 0083 amendment). The re-run of the
  unchanged commit passed (intermittent). Branch run 39715804524 green, journeys 148 passed / 7 skipped / 3 warnings.
- The human asked whether the journeys must be in CI; decision "Nightly + release": install-journeys.yml (nightly,
  dispatch, workflow_call), release.yml's image job needs it (ADR 0091 amendment). CI on push about 41 minutes.
- Flakes on the branch, both fixed: the sync backoff test moved the fake clock before the engine scheduled its wake
  (reproduced with a 200 ms delay); dev compose created app, queue and scheduler at once on a new volume (copy race).
- Branch run 39728456302 green; merged into main (c82c31f0, 89abe7e2), pushed; journeys dispatched on main.

## 2026-10-22 Shell without a session (fix-shell-session-race); release prep
- Human steps: v2.1.0 to move onto a green main commit; trademark pre-check (TMview + WIPO: one live US class 9 mark,
  lighting controllers; EU/DE/UK none) and "Go ahead" with Fieldset; old form-schema-app dev resources deleted (77d).
- CI run 39747027552 (docs-only commit) failed m7-sso: the worker's `/app/shell` fetch saved a stale session over the
  SSO start token discovery wrote. `app/shell` now runs without session middleware (ADR 0035 amendment, LEARNINGS 175).

## 2026-10-22 Anonymous pull check (maint-release-anon-pull)
- rc.1 release run 39769444878: journeys, image, sign, scan and the draft passed; `verify-public` failed every arm64
  pull with "cannot overwrite digest". Anonymous manifest fetch of the app image returned 200, so the package is public.
- Reproduced on local Docker 29.2.1 (classic store) with busybox by digest; a dind 29.9.2 (containerd store) does not
  fail. Fix: remove each image after its pull; test runs the step with a classic-store docker stub (LEARNINGS 176).
- Human: "Yes, rc.2 when CI green" (QUESTIONS 77e). Docs site brainstorm and plan written (not committed yet).
- Branch CI 39796441261 failed one e2e (builder-v2 drag and drop: inspector stayed on the previous element). Trace:
  one Livewire request carried `sync` (#[Json], skips render) and `inspect`; the inspector never re-rendered. Real
  bug; human: "Fix first, then rc.2". Branch `maint-release-rc2`: the builder's wire sends one call at a time
  (LEARNINGS 177); the docs site build continues on `maint-docs-site` (U1 done).
- Re-run (attempt 2) of 39796441261 failed m4-versions rollback: the edit page was the classic builder's own 503 after
  its 20 s slot wait (another worker held the render slot). Specs now open classic builder pages through a helper that
  honours Retry-After (LEARNINGS 178); no assertion changed.

## 2026-10-22 rc.2 journeys (maint-j09-renditions)
- `v2.1.0-rc.2` pushed on 6376edb8 (main CI 39813158091 green). Release run 39819962977 failed J09 only (147 passed):
  the drill missed 34 files of 44 renditions because 1.0's queue had rendered 10 demo PDFs. The journey now compares
  missing files with the load generator's renditions (LEARNINGS 179). A new tag (rc.3) needs the human's approval.

## 2026-10-23 rc.3 scan (maint-trivy-chromium156)
- rc.3 (2328d5f3) release run 39848976042: journeys green (J09 fix holds); the Trivy job failed on gotenberg only: 60
  Chromium CVEs x 2 packages, fixed in Debian 156.0.8078.47, no Gotenberg release after 8.38.1. Dated exceptions until
  2026-11-22 per ADR 0071; local Trivy 0.76.0 scan of both platforms: clean with the file, 209 findings without.
- Docs site merged to main (db51746e) after go-live settings (QUESTIONS 78); it publishes after the next release.

## 2026-10-23 rc.4 published, docs site live (maint-state-rc4)
- Main CI 39913717204 green on 209fe4a7; `v2.1.0-rc.4` pushed by name (human: "Yes, rc.4 when green"), local `v2.1.0`
  moved there (not pushed). Release run 39919806861 green end to end; GitHub pre-release with 9 assets.
- Docs run 39940103599 (workflow_run) published the 2.1 copy; `/latest/` shows the rc notice, spec byte-identical at
  its `$id`. cosign verify/verify-blob and anonymous access to all 7 digests x 2 platforms pass from outside CI
  (`evidence/R2.1/postpublish-v2.1.0-rc.4.txt`).

## 2026-10-23 plans out of git, final 2.1.0 docs (maint-final-2.1.0)
- Human decision (QUESTIONS 79): `docs/plans/` and `docs/brainstorms/` untracked and git-ignored, kept on disk; no
  history rewrite. AGENTS.md says plan paths in ADRs and agent records are local only.
- Final 2.1.0 needs a docs commit (QUESTIONS 80): CHANGELOG 2.1.0 dated, Unreleased folded in; 4 GB note in the
  install guide (decision 41/74). Query tuning: human "After final, in 2.1.x".
- Local arm64 Install journeys on aa3f5c1f (before the final, QUESTIONS 77c): 151 passed / 7 skipped, 1 h 45 m, no
  leftovers. Evidence in `R2.1/` (M10-pinned results copied to `R2.1/M10-pinned/`, M10 restored).

## 2026-10-23 Fieldset 2.1.0 published (maint-state-final)
- Main CI 39959490158 green on 2c224db8; human: "Go ahead". Local `v2.1.0` moved from 209fe4a7 to 2c224db8 and pushed
  by name (admin bypass of the tag creation rule). Release run 39970942791 green end to end; GitHub release stable,
  Latest, 9 assets.
- Docs run 39994845764 replaced the 2.1 rc.4 copy with the final; no pre-release banner. cosign, SHA256SUMS, anonymous
  pulls of all 7 digests x 2 platforms, stable tags and the update feed pass (`evidence/R2.1/postpublish-v2.1.0.txt`).
