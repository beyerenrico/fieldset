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
24. The hardware floor is a target until J10 measures it (E16, ADR 0062; U15 in M11): Gotenberg OOMs are reported
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
