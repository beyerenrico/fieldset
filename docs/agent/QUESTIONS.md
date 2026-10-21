# QUESTIONS for later human review (never blocks work)

Format: question · chosen option · impact · how to reverse.

1. App name/domain unresolved · used `form-schema-app` / `localhost` (ADR 0001) · docs, meta-schema
   `$id`, defaults · search-replace + republish meta-schema `$id` before 1.0.
2. D1 AGPL/MIT split without CLA or DCO · no CLA; DCO recommended in CONTRIBUTING · relicensing later
   needs all contributors' consent · add CLA bot before accepting outside contributions.
3. D6 no e-invoice output · generic invoice only (ADR 0008) · customers above €800k turnover need
   another tool from 2027 · implement B4 (ZUGFeRD) after tax-advisor review.
4. Risky default: D4 offline UX only in M6 · API offline-ready from M2 (ADR 0006) · field use before
   M6 needs connectivity · none needed if M6 ships before 1.0.
5. Filament MFA challenge failures are not audited (Filament fires no event) · accepted for now ·
   failed admin TOTP attempts leave no trace (Fortify ones do) · add a Filament challenge subclass or
   upstream event when available.
   **Resolved in M8 (ADR 0038):** the admin login writes `auth.mfa_failed` for a wrong code.
6. Self-service registration default · off (`APP_ALLOW_REGISTRATION=false`) after security review ·
   public hosted instance must enable it · set env to true.
7. Unconfirmed Fortify 2FA secret is replaced if the user then enrols in Filament · accepted (the
   unconfirmed secret was never active) · none.
8. Builder v1 page weight · the Filament Builder renders every block schema (incl. nested block pickers
   and logic editors); the service-report editor HTML is ~4 MB · acceptable for v1, Builder v2 (M7) is
   the planned replacement · if needed earlier: lazy-load block bodies or collapse nested pickers.
   **Resolved in M8 (ADR 0044):** the canvas builder (138 kB page) is the default; v1 stays reachable.
9. Devices are user-level, so an admin of org A with `members.manage` who revokes a member's device
   also signs that phone out of org B (M2 security review); since M6 revoking a device also ends all of the user's sessions and the remember-me token (a recaller login is not bound to the device) · accepted: one device = one install of
   the Field App, wipe must clear all local data · an org admin can disrupt a shared user's work in
   another org · scope revocation per org (device × organization) if multi-org field workers matter.
10. Builder preview iframe is same-origin with `sandbox="allow-scripts allow-same-origin"`, which
   gives no isolation from the admin page (M2 security review) · accepted for M2: the preview page
   has a strict CSP (`script-src 'self'`), Markdown has HTML off, images only `data:` · a renderer XSS
   would run with the designer's admin session · M8 task: serve the preview from an opaque origin
   (drop `allow-same-origin`, CORS on preview assets, `source`-checked postMessage).
   **Resolved in M8 (ADR 0040):** the preview runs on an opaque origin (`sandbox="allow-scripts"` plus CSP
   `sandbox allow-scripts`, `connect-src 'none'`, no storage); postMessage targets `'*'` and checks
   `event.source` and the `'null'` origin; Caddy serves preview assets with CORS.
11. `app:reproject` rebuilds `submission_values` from current data, so a `changes_requested`
   submission is skipped until resubmitted (ADR 0027) · accepted · reports built during a full rebuild
   miss those submissions temporarily · keep a snapshot of the last submitted data per submission.
12. Branding is not versioned (ADR 0028): a logo/colour change alters later re-renders' input_hash
   and look, while form and design versions stay pinned · accepted (branding is organization identity,
   not document content) · old PDFs are immutable anyway; re-renders after a rebrand show the new brand ·
   snapshot branding into design versions or renditions if exact re-renders across rebrands matter.
13. Background Sync only wakes open tabs (ADR 0035): the service worker's `sync` event posts to open
   clients; there is no executor inside the service worker · accepted for M6 (a worker-side sender would
   need the XSRF cookie flow and device-status) · on Chromium, an outbox does not drain while the app is
   closed · add a worker-side sender if field feedback shows apps are closed before syncing.
14. Server time decides on sync (ADR 0035): number, period, `submitted_at` and `$today`/`$now` in
   formulas use the server clock when the submission syncs; the device time is kept as
   `client_submitted_at` · accepted (gap-free numbering needs server order) · a form filled on the 31st
   and synced on the 1st gets the new period's number · use `client_submitted_at` for the period if
   customers expect it.
15. Shared devices (ADR 0035): unsynced rows of user A stay hidden and unsent when user B signs in on the
   same device; the device secret belongs to whoever signed in last, so a wipe for A cannot reach them ·
   accepted (field devices are usually personal); B is offered to delete them, keeping is the default · A's unsynced work waits
   until A signs in again · per-user device records if shared devices matter.
16. A wipe requested by deactivating a member's last active membership stays when the member is
   reactivated before the device connects again (M6, EndUserAccess) · accepted (the safe direction) ·
   the device wipes once, unsynced work on it is lost, the member signs in and registers again · lift
   `wipe_requested_at` on reactivation if admins often deactivate by mistake.
17. SSO domains reject public suffixes from a curated excerpt (`PublicSuffixes`), not the full Public
   Suffix List (no PSL package installed) · accepted: the DNS TXT proof is the real control, the suffix
   check only stops obvious mistakes (e.g. `co.uk`) · an exotic public suffix can be claimed but never
   verified without control of its DNS · add a maintained PSL package if admins report confusing cases.
18. SSO from the installed iOS PWA (standalone) leaves the app's scope for the external IdP; iOS may
   open it in a separate browser context with its own cookie jar, so the return to `/app/login?sso=ok`
   might not carry the session (ADR 0036) · untested (no device in this run) · field workers on iPhones
   who installed the app could fail to sign in with SSO · test on a real device in M8/1.0; fallback:
   start SSO in Safari, or use the password option when the organization does not enforce SSO.
19. Web push (§8.9 "comes in v1.0") is deferred past 1.0 (ADR 0045): it needs VAPID secrets, a new crypto
   dependency or hand-written RFC 8291/8292 code, outbound calls to push services, a subscriptions table with
   GDPR handling and an `injectManifest` service worker · accepted for 1.0: in-app (polling) and email cover the
   events · field workers learn about new tasks only when they open the app or read their mail · decide the
   priority and the library (`minishlink/web-push` or own code) for 1.1.
20. Handwritten signatures need a pointer (Field App, M8-T04): the pad has no typed alternative; WCAG 2.1.1
   exempts path-dependent input, and the name, clear and confirm controls work by keyboard · accepted ·
   keyboard-only and switch users cannot sign on their own · add a "type your name as signature" mode if forms
   are filled by users who cannot draw (legal effect to be checked per organization).
21. Three performance results sit at or over a §11 line at 1.0 (ADR 0046, M8-T05): the classic builder page
   (1.05 s, 4.5 MB; not the default since ADR 0044), the Field App first load under Lighthouse's devtools
   throttling (3.5 s; slow 4G 2.1 s), adding the 501st repeater row at 4× CPU (median 203 ms) · accepted for 1.0 ·
   admins who open v1 wait about a second; field workers on very slow links wait longer for the first load ·
   decide whether to retire v1 in 1.1 and which Field App load profile is binding; proposals in
   `docs/agent/milestones/evidence/M8/performance.md`.
22. Phone HTTPS is demo-grade (E11, ADR 0057): whether Android trusts a user-installed CA for PWA installs is
   unverified; quick tunnels have no uptime guarantee, allow 200 in-flight requests, have no SSE and route traffic
   through Cloudflare (verified, developers.cloudflare.com) · both options off by default and mutually exclusive,
   tunnel recommended on Android, 4-hour expiry, path policy on the tunnel host · a phone demo can fail on Android
   with the local CA or drop mid-demo on the tunnel; the instance is reachable from the internet while a tunnel
   runs · test one iOS and one Android device per `RELEASING.md`; if Android refuses the CA, document the tunnel as
   the only Android path.
23. The image size budget is at risk (E17, ADR 0063): the added binaries measure about 210–220 MB uncompressed
   (cosign alone 126–134 MiB), double the plan's "about 100+ MB", so slimming must remove about 1.3 GB from
   today's 2.12 GB · keep KTD8 (cosign in the image), slim first, measure in M9-T02 · a miss means slower pulls on
   a small VPS and strains the "minutes" promise · stop embedding cosign and run the pinned official cosign image
   through the ops container's socket (saves ~130 MB, R58 identity unchanged) with an ADR, or accept a larger
   budget.
   · Resolved: the budget held at 648 MB with cosign (ADR 0065); cosign was then dropped from the image for the
   scan gate, and the image is now ~500 MB (507 MB arm64, 497 MB amd64; ADR 0071).
24. (Superseded by ADR 0083 and question 41: E16 measured in J10.) The hardware floor is a target until J10 measures it (E16, ADR 0062; U15 in M11): Gotenberg OOMs are reported
   on small hosts, and an upgrade or restore needs room for a safety backup, a second database and a second
   storage volume on 20 GB · keep 2 vCPU / 2 GB / 20 GB, tune the limits, record a miss with numbers · owners on
   the smallest VPS may see OOM kills, or upgrades refused for lack of space (exit 3) · raise the published floor
   (e.g. 4 GB RAM, 40 GB disk) if J10 shows no headroom.
25. The first public publish is irreversible (E4, E14, E18; ADRs 0050, 0060, 0064): a public GHCR package cannot
   be made private again (verified, docs.github.com), and the signer identity (`beyerenrico/form-schema-app`,
   `release.yml`, `v*` tags) is built into every shipped `install.sh` and image · publishing stays a human step
   per `RELEASING.md`; the first publish is a pre-release that does not move `latest`, followed by the post-publish
   checklist · a mistake in the first public package stays public; moving the repository or renaming the workflow
   breaks verification for installed versions · review `RELEASING.md` and `release.yml` before the first tag
   push; an identity change needs a new `install.sh` and image release with an upgrade note.
26. Backups stay on the instance's host, and the encrypted-backup key is handed over once (E13, ADR 0059) ·
   default as E13: daily, keep 7, in the instance directory; `/platform` shows that backups are held only on this
   host · losing the disk or host loses the backups with the data; losing the identity file or its passphrase
   makes encrypted backups unreadable · document copying backups off-host; build offsite targets (Appendix B) if
   owners do not.
27. (Open: confirm the exception policy.) The Trivy gate (plan U7: no fixable critical/high in the app image or
   any third-party digest) failed at M9 on findings that no released upstream image fixes. App image: 34 HIGH in
   the vendored cosign, Compose and age Go binaries. postgres: 1 CRITICAL + 24 HIGH (gosu). gotenberg: 87 HIGH.
   caddy: 4 HIGH (reachable). cloudflared: 1 HIGH. valkey and mailpit were clean.
   · Chosen (ADR 0071): what we build is scanned strictly. cosign leaves the image, and Compose and age are rebuilt
   from their verified release tags with Go 1.27.2, which brings the app image to 0, with no exception file.
   Third-party images get per-service, per-finding exceptions in `distribution/trivy/<service>.trivyignore.yaml`,
   scoped by path or purl, each with a statement, expiring after 90 days (gosu: unreachable, upstream policy) or 30
   days (gotenberg, caddy, cloudflared: fixed upstream, no image release yet). `release.yml` applies only the
   image's own file and lists what it suppresses.
   · Impact: releases pass the scan while caddy's 4 reachable Go findings and gotenberg's Chromium findings run in
   production until a fixed image ships, at most 30 days. Expired entries fail the release again.
   · To reverse: delete `distribution/trivy/` and the `--ignorefile` branch of `release.yml`'s scan loop; the gate
   is then strict for every image again. Or shorten the expiry, or drop single entries (for example caddy's).
28. (Resolved by ADR 0071.) fsa verifies release manifests with the pinned official cosign image, like install.sh
   (ADR 0070), so the cosign binary in the app image (~130 MB of E17, ADR 0063/0065) had no user left.
   · Chosen: dropped from `docker/app/Dockerfile`. The harness journey and `tests/Support/Docker/Cosign.php`
   verify with the pinned image, and the image contract tests assert that cosign is absent. Offline use was no
   reason to keep it: keyless verification needs Sigstore's TUF root either way.
   · Impact: the image is 141 MB (arm64) and 150 MB (amd64) smaller, with 21 fewer Trivy findings, and one cosign
   build to keep current instead of two.
   · To reverse: restore cosign's release download (both architectures' checksums), its LICENSE in the `tools`
   stage and its entry in `docker/app/binaries.json`, and revert the image contract assertions.
29. A resumed install after the app_install phase issues a 15-minute admin sign-in link, not the 60-minute
   owner link (U5, ADR 0072) · kept: `app:install` does not re-issue owner links · an owner whose first link
   expired gets a shorter link on resume · make `app:install --owner-link` re-issue an owner link for an owner
   without a password.
30. `fsa uninstall` leaves the empty instance directory, because it is the ops container's bind mount ·
   kept · an empty directory remains · let the shim `rmdir` it after a successful uninstall.
31. (Mostly resolved by ADR 0073.) `fsa uninstall --orphans <uuid>` now accepts only UUIDs install reported as
   orphans of this directory, refuses (exit 7 `orphans_live`) while any of their containers runs, and keeps
   volumes that containers elsewhere still use · still open: no second confirmation beyond `--yes` · a mistaken
   UUID from the install report could still remove a stopped instance's data · require the UUID to be typed
   again (or `--yes-orphans`) if this proves risky.
32. The final backup of `fsa uninstall --keep-backups` (ADR 0073) runs while app, queue and scheduler keep
   running · kept: uninstall stops everything right after the backup · files written during the archive may be
   missing from it · stop the app roles before the final backup once U16's quiesce step exists. Resolved in the M12 gate (D2): the final backup
   now quiesces first (gate up; app, scheduler, backup, then queue stopped).
33. Owner email and name in the state history and archived journals are HMAC digests keyed from APP_KEY
   (APP_PREVIOUS_KEYS still match) · chosen to keep personal data out of host files · a future APP_KEY rotation
   must keep the previous keys, or old digests stop matching · `config set` key rotation (later) must append to
   APP_PREVIOUS_KEYS.
34. The tunnel path policy (R65, ADR 0074) keeps customer document links (`/documents/links/*`), `/admin` (without
   `--tunnel-admin`) and the builder preview off the tunnel host, but jobs started during an active tunnel link to
   it (the public URL is the tunnel) · kept: R65 lists only the Field App, the API, sign-in and assets, and the plan
   wants new jobs on the tunnel · a customer document mail or an admin notification sent during a tunnel demo
   gets 404 there, and dies with the tunnel · add `documents/links/*` (random token, customer-facing) to
   `TunnelPathPolicy::ALLOWED`, and/or build admin links in jobs from the instance URL instead of the public URL.
35. (Drift check resolved by ADR 0078; the unverified items at the end stay open.) Proxy mode trusts exactly one
   proxy IP resolved at install time (ADR 0076) · recreating the proxy container without a fixed IP silently drops
   trust (requests then look like they come from the proxy) · `fsa config set trusted-proxy` re-resolves
   `proxy_container` (U11, ADR 0077), and since ADR 0078 the doctor's `proxy` check and `fsa status` warn
   `proxy_address_changed` when the trusted address no longer matches · give the proxy a fixed IP, or run
   `fsa config set trusted-proxy auto` after recreating it.
   Still open (unverified): caddy-docker-proxy labels (no journey), Portainer/Coolify import of the stack file,
   Traefik per-router certresolver (configure it on the entry point).
36. Domain mode always sets `acme_ca` (Let's Encrypt production by default, ADR 0075), so Caddy has no ZeroSSL
   fallback · chosen for predictable rate limits and staging · an LE outage blocks new certificates · remove the
   explicit `acme_ca` for production to restore Caddy's default issuers. Related: the shim passes `FSA_TEST_DNS`
   through; production images ignore it (harness flavour only) · harmless but visible · drop it from the
   production shim if it confuses. The app also sends `X-Content-Type-Options: nosniff, nosniff` (its Caddyfile
   and SecurityHeaders, 1.0 behaviour) · cosmetic · remove one source.
37. `fsa config set` asks for `--yes` on every URL change (`domain`, `url`, local `port`, `mode`), not only
   `domain` (ADR 0077) · chosen because every URL change invalidates signed links and moves SSO redirects ·
   one more flag for agents · relax to `domain` only if it proves noisy. (`config set operator-email` landed in
   U12, ADR 0080.)
38. The capture banner shows to every admin-panel member, not only admins (ADR 0080; E8 says "admins see a
   banner") · chosen because designers and reviewers also send invitations and resets · harmless information
   (mail is captured) · restrict to admins in CaptureModeBanner if preferred. `config set operator-email
   --revoke-previous` revokes every other operator · simplest safe meaning for a single-owner instance · an
   instance with several operators loses all but the new one · add `--revoke <email>` if needed.
39. `fsa config set phone-https local-ca` refuses (exit 3) while demo accounts still have `password`, whereas
   `fsa tunnel start` rotates them first (ADR 0082) · chosen because the local CA is a mode switch the operator
   plans, the tunnel an ad-hoc exposure · inconsistent for agents · rotate in both cases if preferred. A failed and
   reverted local-CA turn-on leaves its CA on the volume until the next `off` or uninstall · harmless (key never
   leaves the volume) · delete it in the revert if preferred.
40. cloudflared is not a trusted proxy (ADR 0082): all tunnel clients share its address for throttles and audit,
   and what real cloudflared forwards (Host, `X-Forwarded-*`) is unverified (only the fake tunnel was used) ·
   check on the RELEASING.md checklist with a real quick tunnel before 2.0.0 · tighten throttles per token if
   shared limits bite.
41. E16 admin-page targets are missed at the 1.0 test volume (100k submissions, 1.27 GB DB) on 2 GB / 2 CPUs:
   23/31 series < 500 ms; saved reports 946–1,618 ms, explorer search 1,245–1,879 ms, reviewer inbox 550–613 ms
   (ADR 0083; Postgres hit rate 54 %, ~100 MiB cache) · install, PDF and API targets are met · options: accept and
   document the data size from which 4 GB is needed; ADR 0046 follow-ups (cached saved-report results,
   covering/trigram indexes for the explorer); free memory for Postgres; or raise E16 to 3–4 GB. J10 records timing
   misses but fails only on OOM/restarts/failed steps — should the M13 gate enforce timings?
42. `small` runs one default Horizon worker (ADR 0083) · Gotenberg concurrency is 1 anyway · mails and workflows
   queue behind renders, pickup latency up to 3 s · raise to 2 if memory allows on real hosts. Margins are ~30 MiB
   per slot; the backup (128) and tunnel (48) slots and the `medium`/`large` profiles are unmeasured; the 320 MiB OS
   headroom is an estimate (swap guidance, ADR 0062, matters).
43. After an SMTP install whose test mail was refused, a resume can retry but not change server settings or the
   account (ADR 0080 amendment) · keeps resume semantics simple · the fix is uninstall + reinstall, or a capture
   install plus `config set mail smtp` · allow changes on resume if it bites. Every resumed smoke phase sends the
   owner another test mail. `config show` reports `mail.catcher: removed` for an instance that never had one
   (cosmetic; `none` would be clearer).
44. The local CA's root and intermediate keys live on the edge's Docker volume (Caddy's pki directory), not in
   `<instance>/certs/` with mode 0600 as BUILD_PROMPT_V2 §10/§6.3 say (ADR 0082 amendment) · confidentiality is the
   same (root-only volume, 0600 for Caddy's user) · M12 backup/restore must include the edge volume or phones must
   trust a new CA after a restore · bind-mount `<instance>/certs/` as Caddy's pki directory if the spec wording
   matters.
45. STARTTLS is now required for the instance mailer by default, with or without an account (M11 gate, ADR 0080
   amendment); relays without TLS need `--smtp-encryption none` (warning `smtp_plaintext_auth` with an account) ·
   closes a downgrade that leaked the SMTP password · existing plain relays must opt out explicitly · relax to
   "required only with an account" if that is too strict. `config set mail smtp --smtp-host <new>` without
   `--smtp-username` drops the stored account (`smtp_credentials_dropped`) rather than sending it to the new host.
46. `config set domain` and `phone-https local-ca` refuse (exit 3) while ANY account still has `password`, not only
   demo accounts (DefaultPasswordGuard, ADR 0081 amendment) · consistent with the local CA rule · agents must rotate
   or change passwords first. An operator created by `config set operator-email` has no organization, so
   `fsa login-link` refuses them although the owner-link page names it as recovery · a lost link is replaced by
   repeating `config set operator-email` after expiry (≤ 60 min) · let `fsa login-link` serve operators without a
   password. On a legacy DB where a real org holds slug `demo`, the demo gets `demo-2` (E2E/perf assume /admin/demo).
47. Small Postgres now runs `jit=off`, `max_parallel_workers_per_gather=0`, `max_connections=30` (stack clients 17)
   (ADR 0083 amendment) · less memory risk for backends · may move admin-page p95 (QUESTIONS 41); recheck with the
   gate's J10. `config set operator-email` has no Interruption guard; a renew killed outright with a live tunnel is
   resumable only by `fsa tunnel renew`; `config set phone-https off` as a CA-deletion retry runs the full rollout
   (queue/scheduler briefly stopped).
48. M11 review lows logged, not fixed: the fsa SecretRedactor ignores secrets shorter than 4 characters (a 1–3
   character SMTP password would not be masked; such passwords are refused by no rule) · `config show
   --catcher-login`'s hint "Run `fsa install` again" re-renders nothing on a finished instance (results now carry
   `catcher_login_command`) · the test-mail hint names the SMTP settings when no operator exists · low impact ·
   fix when touching those paths.
49. Builder v2 key suggestion (ADR 0037): the client applies `builder-v2:key-suggested` while the key field still
   shows `from`. If a designer has selected the key field's text in the instant before the suggestion lands, the
   suggestion replaces the selection and the typed key is appended to it ("totalline_total"). Seen only in the E2E
   helper's `fill` (M11 gate, E2E run 2), which now retries until the field holds the key · negligible for typing
   people · optional fix: skip the suggestion while the key field has focus.
50. Builder v2 (M7): a selection made while the canvas page's first Livewire requests are still in flight can be
   undone. One of them answers after the selection request, its older snapshot replaces the client's, and the
   next edit (the title) is sent without the selection: the inspector empties and the edit is dropped. Seen once
   in the M11 gate E2E (keyboard spec, trace: select at +0 ms, older request answering 6 ms after the selection's).
   A person must select within ~100 ms of the page loading · the E2E helper now waits for the initial requests ·
   optional fix: queue the builder's initial requests before accepting selection (or carry the selection client-side).
   The same lost update hit the drag spec twice (runs 8 and 10): after a drop the inspector stayed on the previous
   node for 10 s. Both appeared only with the host at load ~12 on 10 cores (Teams, Defender, VS Code PHP server);
   M11 changed no builder code.
51. Backups (U16, ADR 0084): backup key rotation is not built: a new key pair means `config set backup-encryption
   off` then `on`, and older backups still need the old identity · rotating keeps working encrypted archives readable
   only while the operator keeps every identity · optional: a `backup-encryption rotate` that re-seals kept backups.
52. `fsa install --encrypt` exits 2 with a hint to `fsa config set backup-encryption on` after the install (the
   passphrase and identity path need a second, deliberate step) · confirm the two-step flow is acceptable.
53. Restore (U17, ADR 0085): the app's database user is still a Postgres superuser · an unauthenticated archive is
   restored by a restricted role and audited before adoption, but a superuser app user widens the impact of any
   audit gap · optional: a non-superuser app role (owner of its database only) in a later release.
54. An instance installed with `fsa install --from-backup` gets a new instance UUID, so backups of its predecessor
   cannot be restored in place there (only with `--from-backup`) · confirm, or carry the UUID on a move.
55. `fsa restore --drill` renders its sample document with images from the live volume (reported as
   `render_storage: live`), so the drill does not prove the backup's files render · accept or extract storage too.
56. The restore space check measures the instance directory's file system; when Docker's data root is on another
   file system the check can underestimate the space a fresh volume needs · accept or measure both.
57. Restore carry (U17, ADR 0085): when the live database was itself restored, rolled back or reset after the backup,
   changes are carried only if timestamped or named in the audit log after the backup; actions without an audit
   event (hard-deleted users, direct permission removals, deleted membership rows, identities unlinked outside
   `sso.connection_changed`, actor-less group changes, password changes with audit suppressed, wipe requests by
   timestamp only) are then skipped and reported as `carry_partial`. Restoring the same backup twice can drop what the
   first carry re-applied (the carry writes no audit entries) · optional: audit what the carry re-applies.
58. Reset resume (U23, ADR 0088): the plan says an interrupted reset resumes "on the next `fsa` command"; instead
   every other changing command answers exit 9 naming `fsa reset`, `fsa status` shows it, and only `fsa reset`
   continues (other commands would otherwise return the owner link or demo passwords) · accept or require auto-resume.
59. Reset owner (ADR 0088): the fresh instance's owner is the old operator whose e-mail matches the install record's
   keyed digest exactly (an e-mail typed with capitals at install does not match), else the operator granted first
   · accept or normalise case in the digest.
60. Reset keeps webhooks running (no pause: a reset keeps no runs to repeat) and keeps every safety backup it took,
   including one a retake superseded (sealed, never pruned) · accept or prune superseded ones after a good reset.
61. Adopt "writes since adopt" (U19, ADR 0087): the baseline is taken before 2.0 starts, so the count at uninstall
   includes what 2.0 itself did afterwards (renditions and audit entries from 1.0's backlog, chain seals): it
   over-reports rather than hides · accept or subtract system work.
62. Adopt refuses a 1.0 Postgres that is not major 16 on Alpine (no REINDEX path for a libc change), and amcheck covers
   only the unique text indexes after the copy · accept or add a reindex path / check every text index.
63. Upgrade child liveness (U18, ADR 0086): the child counts as hung after 300 s without an active backend statement,
   so a migration doing more than 300 s of PHP-only work between statements is killed and rolled back · accept or
   raise / make configurable.
64. Upgrade scope (ADR 0086): Mailpit and the tunnel are never recreated by an upgrade (a Mailpit digest bump waits for
   the next `config set`); delayed jobs are flushed with Valkey on a rollback; the dropped-jobs file is never deleted;
   queue/scheduler/backup do not themselves refuse to start behind the gate · accept each or schedule.
65. Upgrade target (ADR 0086): without `--to` the target is `latest`; when latest is a new major, exit 7 cannot name the
   newest release within the installed major (the verified feed has no release list; a GitHub listing is unsigned).
   A manual rollback needs a previous release that knows the upgrade-child role · accept or add a signed release list.
66. Upgrade in-flight wait (ADR 0086, review A1): after the gate rises the upgrade waits up to 120 s until no other
   client session holds the database, including an operator's psql (exit 9 names them); it relies on FrankenPHP's
   classic mode · accept or ignore known clients.
67. Staged release files (ADR 0086, review S2/A3): unknown staged files are refused, so a release that renders a new
   kind of file needs the installed release to know it; on a rollback a renamed env key keeps its pre-upgrade value
   (a rotation after the upgrade is lost) · accept or version the stage format.
68. One full-suite run on the merged M12 tree failed SearchPlanTest once (planner chose sequential scans on
   submission_values, submissions and users); it passed alone 3/3 and in the next full run · watch; if it recurs,
   suspect autovacuum updating relation stats during the test.
69. M12 gate deferrals (agent operability, correctness, security reviews): one recovery object across envelopes
   (O5; `data.next` string vs kind, for the U21 agent guide); a retried finished foreground operation is not
   idempotent and the shim's 143/130 exits carry no envelope (O11); restores/rollbacks/resets replace the
   `backup_runs` table, so `/platform` may show a false `backup_overdue` (C8); `--from-backup` and adopt migrate
   without VersionSteps (C9; latent, STEPS is empty); `fsa status` backup alerts read the forgeable `runs.log` (S7)
   · accept each or schedule for M13.
70. An encrypted final backup (uninstall `--keep-backups`) moves only with `--accept-unauthenticated`: its APP key is
   inside the archive, so whoever can write `backups/` and knows the public recipients can forge one (restricted
   restore role limits SQL damage) · accept, or print the APP key fingerprint and let `--from-backup` require it.
71. `--from-backup` keeps a backup's encryption recipients only with `--keep-recipients` (M12 gate S2), and
   `uninstall --keep-backups` fails with exit 4 on an instance without a backup service (pre-rc builds only)
   · accept or relax.
72. On rootless Docker and Docker Desktop container root is the owner, so the root-owned partial set (S1) adds no
   protection there; a FIFO swapped in between the restore copy's lstat and open blocks the prepare one-off until its
   time limit (whoever can do that can also delete the backup) · accept or harden.
73. `fsa upgrade --follow` and `fsa status` `data.last_upgrade` show the last `fsa upgrade` run, so a no-op run after a
   real upgrade (`current: true`) replaces its result (R51 F12; every launch replaces `operations/supervisor.json`) ·
   kept, documented in the agent guide's upgrade recipe · an agent that attaches late sees "nothing changed" ·
   keep the last result that changed something (or skip the supervisor for a no-op) if preferred.
74. J10 at the M13 gate: one API series missed its 300 ms p95 for the first time: a reviewer's
   `/api/v1/submissions?status=in_review` at 431 ms (M11 244 ms, M12 278 ms; p50 415 ms). The M13 diff does not touch
   the API, submissions or migrations, and the backup step runs after the API step. Admin pages 25 of 31 under 500 ms
   (QUESTIONS 41) · cause not found (seed distribution or planner choice suspected, unverified) · profile the query
   at the 1.0 volume before tuning; J10 records timings and does not fail on them (ADR 0083).
75. M13 gate deferrals (low, from the review): the update check reads the whole body before its 1 MiB check (S2); an
   http `FSA_RELEASE_URL` allows a plain-http update check (S3; display only, `fsa upgrade` verifies); the GHCR read
   token stays in the Docker config during the `php artisan` step of `release.yml` (S4); J10's backup evidence is
   unencrypted only, sampled at 1 Hz (D4); the R39 check takes the server major from the pin's tag, not the digest
   (D5); `update-check off` writes nulls instead of removing the keys (D7); the cached update status is not keyed by
   the manifest URL (C-L2); the pg_dump check's multi-platform run needs `--pull=always` on engines without the
   containerd store; J10's backup slot (now + 3 min) can be missed on a slow runner (fails, never passes falsely)
   · accept each or schedule.
76. 2.0.0 release run (2026-10-21): (a) a new cloudflared exception, CVE-2026-63209 (klauspost/compress v1.18.0 in
   the binary; fixed in 1.18.7, still in cloudflared 2026.10.2), 30 days to 2026-11-20 (ADR 0071); 2026.10.2 would drop
   the amd64 libssl entry, the pin stays 2026.10.1 for 2.0.0 · confirm or bump the pin. (b) The pg_dump check's
   multi-platform run (QUESTIONS 75) is fixed: it removes the image it pulled between platforms. (c) Dogfood E2E failed
   once at the 2.0.0 run (m6-offline journey 3, a click timeout at host load 8) and passed on the rerun · watch.

## Human decisions (2026-10-21)
- 1, 25: renamed to **Fieldset** before the first publish: repository `beyerenrico/fieldset`, CLI `fieldset` (was `fsa`);
  signer identity, `install.sh`, images and docs change with it. Trademark not yet checked (EUIPO/USPTO).
- 27, 76a: the dated Trivy exception policy (ADR 0071) is accepted; refresh pins or renew entries around 2026-11-17.
- 41, 74: accepted for 2.0; document that large datasets need 4 GB; query tuning moves to 2.1.
- Rename details: no 1.0 users besides the human, so 1.0 phone storage and SSO TXT names are renamed without fallback;
  spec `$id` on GitHub Pages (`beyerenrico.github.io/fieldset/spec/…`); release 2.1.0. Plan:
  `docs/plans/2026-10-21-001-refactor-rename-to-fieldset-plan.md`.
