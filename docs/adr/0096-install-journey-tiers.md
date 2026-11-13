# 0096: Install journey tiers and the coverage audit
- Status: accepted
- Date: 2026-11-13
- Amends: ADR 0091 (all journeys in a nightly and release job)
- Decision by the human, 2026-11-13: "Cut real-Docker coverage" and "I do not want to run 2h long actions".

## Context
The install journeys (`tests/Install/Journeys/`, 20 files, real Docker) run as one serial job of 2 h 21 min. ADR 0091
moved them out of the push path into a nightly, on-demand and release job, but a job of that length still blocks the
release and hides regressions for a day. The human decided that no 2-hour CI job is acceptable.

Most journey assertions are about our own decisions: the Docker calls we make, the files we render, the results and
exit codes we return, the order of phases. Those can be proven with `RecordingDockerGateway` in seconds. Only real
TLS, real images, memory measurements, real upgrades of a running stack and browsers need an engine.

## Decision
- Three Pest groups. `smoke`: `BootstrapJourneyTest`, `HarnessReleaseTest`, `ComposeConfigJourneyTest`,
  `EdgeConfigJourneyTest`, `J01LocalInstallTest`, `J07UpgradeTest`, `J08BackupRestoreTest`. `full`: the other 12 files
  except `J10ResourceBudgetTest`, which is the only member of `budget`.
- The `smoke` set runs on real Docker on pull requests that touch instance tooling and nightly.
- Before a release, all three tiers run in parallel jobs, with `J10ResourceBudgetTest` as its own job. `release.yml`
  still needs them before anything is built, signed or published.
- The 13 files outside `smoke` keep their assertions in Docker-free tests. The appendix lists, per `it`, the fast test
  that proves the same outcome (`covered`), what a fast test could still prove (`gap`, closed by plan unit U8) and what
  only an engine can prove (`engine-only`).

### Measured job durations
_To be filled by a later unit._

### Workflow shape
_To be filled by a later unit._

## Consequences
- Drift in third-party images (Caddy, Mailpit, Postgres, Traefik, Gotenberg) and regressions in the `full` and `budget`
  journeys surface only before a release, unless a fast test or the dev-stack E2E job (`ci.yml`) catches them.
- The `engine-only` rows are the coverage given up between releases: real TLS and ACME (J02, ConfigDomainSwitch, J06),
  real Traefik (J03), real SMTP and browsers (J04), real 1.0 adoption (J09), resource measurements (J10), the built
  image (ImageContract), the Playwright run against an installed instance (Dogfood), and signed multi-arch publishing
  (LocalPreRelease).
- A journey that gains a new decision now needs a fast test for it; the audit below is the baseline.

## Appendix: coverage audit of the 13 files outside the smoke set
Method: every `it` was read, and the named fast test was opened to check that it asserts the same outcome. Where parts
of one `it` differ in class, the `it` has one row per part, so counts are rows. Paths are relative to `tests/`
(`Unit/...`, `Feature/...`). `ImageContractJourneyTest` and `J10ResourceBudgetTest` are counted per `it`.

### J02DomainModeTest

| it | class | fast test or reason |
|---|---|---|
| installs in domain mode: JSON (url, mode, port null, catcher_url, sign_in_link delivered secrets_file), state mode and acme_ca, runtime instance_url | covered | Unit/Instance/DomainInstallTest.php::installs in domain mode: https URL, the edge in the stack, its subnet free of the host's networks; ::sets the ACME contact, staging and a custom CA root from the flags; Unit/Instance/InstallCommandTest.php (sign_in_link delivered secrets_file, line 402) |
| installs in domain mode: Pebble actually issues the certificate to the edge | engine-only | Real ACME order against Pebble and challtestsrv; fast side (smoke loop, certificate_failed) is Unit/Instance/SmokeTestProgressTest.php and DomainInstallTest "exits 4 with the edge log when no certificate comes" |
| serves a certificate whose chain verifies against Pebble's issuing root, SAN is the domain, other root refused | engine-only | Real TLS handshake and chain verification |
| redirects HTTP to HTTPS (308), app security headers, __Host- Secure cookies and preview CORS pass through the edge | engine-only | Caddy's automatic HTTP redirect and header pass-through exist only in a real Caddy. The pieces we own are covered: Unit/Instance/CaddyfileRendererTest.php::serves the domain with automatic HTTPS ... proxies everything else to the app unchanged (no header_down, no tls override); Feature/Security/SecurityHeadersTest.php, HttpsTransportTest.php, Feature/Instance/RuntimeUrlTest.php::makes session cookies Secure and __Host- |
| publishes only 80, 443 and 443/udp, all from the edge | covered | Unit/Instance/ComposeRendererDomainTest.php::publishes only 80, 443 and 443/udp, all from the edge; app and catcher publish nothing |
| /_mail/ behind a /platform session with MFA: 302 to /platform/login, 401 for XHR, auth path 404 from outside, operator with MFA allowed, audited once | covered | Feature/Instance/CatcherAuthTest.php::lets an operator with MFA and a /platform session through and audits the first opening of the session once; ::answers 401 to fetches, XHR and websockets without access; ::redirects a browser navigation without access to the /platform sign-in; Unit/Instance/CaddyfileRendererTest.php::answers the internal auth path with 404 from outside; ::puts /_mail/ behind forward_auth ...; ::hardens the catcher responses |
| /_mail/ behind a session: real Caddy forward_auth to the app, Mailpit UI and API answer 200 through the edge with the injected credential | engine-only | Real Caddy forward_auth chain and real Mailpit; the rendered config is covered by CaddyfileRendererTest |
| trusts forwarded headers only from the edge: neighbour on another network is not trusted, edge strips X-Forwarded-Port, links keep the configured port | covered | Unit/Instance/ComposeRendererDomainTest.php::trusts exactly the edge address as the app's proxy, in every app role and only in domain mode; Feature/Security/TrustedProxiesTest.php::ignores forwarded headers from an address that is not a listed proxy; Feature/Security/TrustedHostsTest.php; Feature/Instance/RuntimeUrlTest.php::takes the link port from the URL configured for the host, never from X-Forwarded-Port or the Host header; CaddyfileRendererTest (header_up -X-Forwarded-Port) |
| reports the instance with fieldset status (mode, url, health ok, edge in services) and doctor --post exits 0 | gap | Extend Unit/Instance/DomainInstallTest.php::reports domain mode in status ... with `status['data']['health']`, `array_keys(services)` contains `edge`, and a `fieldset:doctor --post` run with the edge container running exits 0 (today it asserts mode, url, catcher_url and plain doctor only). Asserts: health ok, edge listed, doctor --post exit 0 in domain mode |
| uninstalls with the certificate volume, certificates_removed warning, no labelled container, volume or network left | covered | Unit/Instance/DomainInstallTest.php::warns about the CA's duplicate-certificate limit before and after uninstalling a domain instance (removed volumes, warning, exit 6 without --yes); Unit/Instance/UninstallCommandTest.php::removes only the labelled containers, networks and volumes of this instance, then its files. The count of 9 containers follows the rendered service list (ComposeRendererTest) |

### J03ProxyModeTest

| it | class | fast test or reason |
|---|---|---|
| installs behind Traefik on a shared network: JSON proxy block, TRUSTED_PROXIES is the Traefik address, only the app joins the shared network, app publishes no port, catcher UI on loopback, shared network not labelled | covered | Unit/Instance/InstallProxyTest.php::installs behind Traefik on a shared network and trusts exactly the Traefik container found by its image; Unit/Instance/ComposeRendererProxyTest.php::lets only the app join the shared network, declared external and never labelled or created by Compose; ::keeps the catcher UI on a loopback port and its SMTP port unpublished in every setup; ::trusts exactly the proxy address in the app roles |
| answers on the configured host through real Traefik over TLS, HSTS, __Host- cookie, https links, client address from the forwarded chain | engine-only | Real Traefik routing and TLS and real container peer addresses. Header trust logic is covered by Feature/Security/TrustedProxiesTest.php::honours forwarded scheme, host, port and client address from a trusted proxy, the labels by ComposeRendererProxyTest::routes Traefik to the app over the shared network |
| does not trust forged X-Forwarded-* from a neighbour container on the shared network | covered | Feature/Security/TrustedProxiesTest.php::ignores forwarded headers from an address that is not a listed proxy, with TRUSTED_PROXIES exactly the proxy address (ComposeRendererProxyTest::trusts exactly the proxy address ..., InstallProxyTest). Real network reachability is not what the decision depends on |
| runs the release stack file with plain docker compose and only environment variables: up --wait, smoke, published port, app:install | engine-only | Needs a real Compose engine, image pulls, healthchecks and migration. The file's content is covered by Unit/Instance/StackFileTest.php (env-only, drift against the proxy render, pins, healthchecks, ports) |
| uninstalls without touching the proxy or its network | covered | Unit/Instance/UninstallCommandTest.php::removes only the labelled containers, networks and volumes of this instance, then its files, with the shared network external and unlabelled (ComposeRendererProxyTest) |

### J04CaptureModeTest

| it | class | fast test or reason |
|---|---|---|
| installs a local instance in capture mode (exit 0, mail mode capture, catcher port) | covered | Unit/Instance/InstallCommandTest.php::installs a local instance phase by phase and hands out the owner link only under secrets (asserts data.mail = capture, catcher_url, login command) |
| catcher login: secret printed only under secrets, file 0600, not on stdout | covered | Unit/Instance/CatcherLoginTest.php::adds the catcher password to secrets, nowhere else; ::writes the catcher password to --secrets-file |
| catcher without login answers 401 | engine-only | The 401 is Mailpit's own MP_UI_AUTH enforcement; our side (the env value) is asserted in Unit/Instance/InstallSmtpTest.php and ComposeRendererDomainTest.php |
| invitation and the install smoke mail arrive in the catcher | engine-only | Needs the real app sending through the real Mailpit container |
| each organization sees its banner variant, operator sees the command (AE1, R59) | covered | Feature/Instance/CaptureBannerTest.php::gives each organization its own variant (AE1); ::shows a platform operator the fieldset command instead of "ask your instance owner" |
| axe checks in a browser for both variants | engine-only | Playwright against an installed instance (e2e/fieldset-capture-banner.spec.ts runs only in this journey); axe needs a real browser |
| switch to SMTP: flags, password on stdin, exit codes, no secret leak, 0600 env, warnings, revert on refusal | covered | Unit/Instance/MailApplierTest.php::switches capture mode to SMTP with the password from stdin, keeps the catcher read-only and reports what was captured; ::reverts to capture mode when the server refuses the test mail (exit 5) and keeps no credential; Unit/Instance/MailConfigSafetyTest.php::requires TLS for STARTTLS, with or without an account ...; ::talks plain SMTP to a relay only when asked ... (smtp_plaintext_auth); Unit/Mail/InstanceMailerTlsTest.php::requires TLS when MAIL_REQUIRE_TLS is true |
| test mail actually reaches the stand-in SMTP server; real STARTTLS-less server answers "TLS required" | engine-only | Real SMTP conversation and Symfony transport against a live server |
| next invitation delivered through the new server, kept catcher receives nothing, banner gone | engine-only | Real delivery decides it; the banner part is covered by CaptureBannerTest::shows no banner once SMTP is configured (AE1), the rendered catcher-read-only wiring by Unit/Instance/CaptureRenderTest.php::keeps the catcher read-only after the switch to SMTP |
| --remove-catcher removes container and volume only with the flag; catcher login then no_catcher | covered | Unit/Instance/MailApplierTest.php::removes the catcher and its volume only with --remove-catcher ...; Unit/Instance/CatcherLoginTest.php::refuses the catcher login in domain mode and without a catcher |
| switch back to capture: catcher returns, banner returns | covered | Unit/Instance/MailApplierTest.php::switches back to capture mode: the catcher returns on a free port and the credentials are dropped; CaptureBannerTest::shows the full banner to an admin ... |
| refuses SMTP password as flag value with exit 2 through the shim (AE9) | gap | Command side covered by MailApplierTest::refuses an SMTP password passed as a flag value with exit 2 and changes nothing (AE9). Shim leg: add a case to Unit/Distribution/ShimScriptTest.php that a `--smtp-password=x` / `--api-token=x` argument reaches the one-off command line unchanged (not swallowed, not rewritten) |
| installs straight into SMTP mode with --smtp-* and a password file: no catcher, MAIL_* env, no secret outside env files, 0600 | covered | Unit/Instance/InstallSmtpTest.php::installs straight into SMTP mode: no catcher, MAIL_* for app, queue and scheduler, the account only in their env files; ::reports mail mode smtp in config show and status after such an install |
| smoke test mail of that install reaches the stand-in relay | engine-only | Real SMTP delivery from the install smoke test |

### J05DemoTest

| it | class | fast test or reason |
|---|---|---|
| local demo install: JSON result (organization, nine users, passwords default, exposure empty, only sign_in_link secret) | covered | Unit/Instance/InstallDemoTest.php::seeds the demo after the stack started on a loopback-only install and keeps the documented password; Feature/Instance/DemoPasswordsTest.php::seeds with the documented password in data, never as a secret, for a loopback-only instance |
| demo users sign in over HTTP with their password; wrong password 422; rotated and remembered sessions answer 401/200 as listed (used by rows 1, 4, 5, 8, 9) | gap | Add a Feature test in Feature/Instance/DemoPasswordsTest.php: after app:demo-seed (default and random modes) and after app:demo-passwords --rotate, POST the app's password login route and GET the me endpoint: default password signs in as worker@demo.test, a random password signs in, the old password gives 422, a remembered session gives 401 after rotation. Existing tests only Hash::check the stored hash |
| all nine demo accounts on password; owner organization Demo counted as real (check command) | covered | Feature/Instance/DemoPasswordsTest.php::finds every account that still signs in with password, demo or not, and skips unknown passwords; ::seeds the real demo beside an organization a real install named Demo, which never counts as the demo (organizations total 2, non_demo 1) |
| fieldset demo on seeded instance changes nothing, reports new owner link, link signs in (303 to /admin) | covered | Unit/Instance/DemoCommandTest.php::changes no demo data when the demo exists on a loopback instance, still tells the documented password, and reports the new link; Feature/Identity/LoginLinkTest.php::consumes the token only on POST ... |
| config set domain refused with exit 3 default_password_in_use, nothing changed | covered | Unit/Instance/ConfigExposureTest.php (default_password_in_use, exit 3, preflight, state unchanged) |
| rotate passwords: result, sessions ended, secrets file names, no password on stdout | covered | Unit/Instance/DemoCommandTest.php::rotates every demo password with --rotate-passwords, into --secrets-file when given, without a sign-in link; Feature/Instance/DemoPasswordsTest.php::rotates every demo password, revokes sessions, remember tokens and API tokens, and audits each account |
| local reset: org and owner only, empty storage, catcher flushed, safety backup kept (0700, SHA256SUMS), retention never prunes it, ledger before drop, instance.reset with old heads, no ID reuse, new epoch | covered | Feature/Instance/ResetCommandTest.php::resets with --yes: quiesce, the ledger before the drop, safety backup, recreate, migrate, epoch, fresh storage, flush, seed, verify, open, gate, cleanup; ::appends the final heads to the ledger after the writers stopped and before the database is recreated; ::takes a safety backup that retention never prunes; Feature/Instance/ResetInstanceCommandTest.php::starts the new epoch from the ledger seed with instance.reset and the old heads as its first entry; ::raises every sequence above the old database before instance.reset ...; ResetVerificationTest::removes the replaced storage volume by name even without the instance label (V8); Feature/Instance/BackupWriterTest.php::archives a 0700 private directory completely and gives every output to the owner, 0700 and 0600 |
| uninstall local demo leaves nothing labelled | covered | Unit/Instance/UninstallCommandTest.php::removes only the labelled containers, networks and volumes of this instance, then its files |
| domain-mode demo install: random passwords (length, only in secrets), exposure domain_mode, password refused, no default_password key | covered | Unit/Instance/DemoPolicyTest.php (data set 'domain mode' gives random, exposure domain_mode); InstallDemoTest::gives random passwords, listed once with the owner link, to an install reachable beyond loopback; DemoPasswordsTest::seeds with one random password per demo user, listed once under secrets and never in data (length RandomDemoPasswords::LENGTH, no default_password). The real Pebble certificate is J02/engine-only and not asserted here |
| domain demo reset --demo --no-backup: audit export (name, header, 0600, entries), no safety backup, no_backup warning, random demo passwords, organizations | covered | Feature/Instance/ResetCommandTest.php::writes an audit export instead of a safety backup with --no-backup while a real organization exists; ::gives the demo users random passwords with --demo on an instance reachable beyond loopback, as secrets only |
| uninstall domain demo leaves nothing labelled | covered | Unit/Instance/UninstallCommandTest.php::removes only the labelled containers, networks and volumes of this instance, then its files |

### J06PhoneHttpsTest

| it | class | fast test or reason |
|---|---|---|
| install with --phone-https local-ca on the LAN address, prints root fingerprint | covered | Unit/Instance/LocalCaCommandTest.php::installs with --phone-https local-ca on the best LAN address: edge, https URL, root fingerprint (+ LocalCaRootTest.php for the root parsing and PEM-only rule) |
| certificate issued for the IP, SNI-less client completes the handshake against the root | engine-only | Real TLS handshake against Caddy's internal CA with a client sending no SNI; only the published ports (LAN address only) are rendering, already in ComposeRendererPhoneTest.php::renders the local-CA edge in local mode with 80 and 443 on the LAN address only |
| port 80 serves only trust page and root, everything else 308 | covered | Unit/Instance/ComposeRendererPhoneTest.php::renders the local-CA Caddyfile: internal CA, default_sni, port 80 only for the trust page (handle @phone_trust, redir 308) + Feature/Instance/PhoneTrustPageTest.php::shows the root fingerprint exactly as openssl prints it, ::offers exactly the public root PEM for download (content-type, body), ::serves the page over plain HTTP without sign-in, session or cookie, ::sends neither HSTS nor upgrade-insecure-requests over plain HTTP |
| Field App over HTTPS in a trusting browser, service worker registers, trust page axe-clean | engine-only | Playwright in Chromium against a live HTTPS instance with a trusted CA (service worker, axe at phone width) |
| status and doctor report the local CA, tunnel refused beside it (R61) | gap | Add to Unit/Instance/LocalCaCommandTest.php: after `config set phone-https local-ca`, `fieldset:status` has phone_https {mode local-ca, root_sha256, lan_address_current true} and services.edge, and `fieldset:doctor` has check lan_address status ok with exit 0 (only the warn path is tested today). Tunnel refusal is covered by TunnelCommandTest.php::refuses a tunnel next to the local CA, in domain and in proxy mode (R61, exit 2) |
| turn local CA off: loopback URL, key deleted | covered | Unit/Instance/LocalCaCommandTest.php::turns the local CA off: loopback URL, edge gone, the CA key deleted, the root gone from the runtime (asserts the `rm -rf` of CA directory and certificates, local_ca_deleted, no edge in compose.yaml); LocalCaGateTest.php::records a CA deletion that failed on phone-https off and retries it |
| tunnel needs --yes beside other organizations, refuses beside an account on the demo password | covered | Unit/Instance/TunnelCommandTest.php::needs --yes when organizations besides the demo exist (exit 6) and changes nothing without it; ::refuses while an account that is not a demo account signs in with the demo password (exit 3) |
| fake tunnel starts, demo passwords rotate, only Field App, API, sign-in pass | covered | TunnelCommandTest.php::starts the tunnel: cloudflared on its network, the hostname from /quicktunnel, 4 hours in runtime.json; ::rotates demo accounts still on the demo password first and lists their new passwords once (R29); Feature/Instance/TunnelPathPolicyTest.php::hides the operator surfaces and everything outside R65 from the tunnel, ::serves the Field App, the API, sign-in and assets through the tunnel (200/401/204), ::ignores a forged X-Forwarded-Host from the tunnel peer; PhoneTrustPageTest.php::is not reachable through the tunnel |
| local CA and domain mode refused beside the tunnel (R61) | gap | Local CA half is covered (LocalCaCommandTest.php::refuses phone-https values it does not take, and the local CA next to a tunnel or outside local mode (R61)). `config set domain <name> --yes` beside a live tunnel (exit 2, invalid_combination) is only unit-tested as ConfigValues::assertNoPhoneHttps (LocalCaGateTest.php); add a test in Unit/Instance/TunnelCommandTest.php (TunnelFixture) running `config set domain` after `tunnel start` and asserting exit 2, code invalid_combination, nothing changed |
| expired tunnel host answers 503, status read-only, next mutating command removes it (AE6) | covered | Feature/Instance/RuntimeUrlTest.php::answers an expired tunnel host with 503 and Retry-After, the local URL as usual, an unknown host with 400 (Retry-After 300); TunnelCommandTest.php::reports an expired tunnel in status without changing anything (AE6); ::removes an expired tunnel before the next mutating command, which then runs (AE6) |
| renew a living tunnel and stop it | covered | TunnelCommandTest.php::renews a living tunnel for 4 more hours and refuses without one (exit 2) (expires_at moves, previous_expires_at); ::stops the tunnel: runtime cleared, cloudflared gone, the URL local again; a second stop changes nothing |
| uninstall leaves nothing labelled | covered | Unit/Instance/UninstallCommandTest.php::removes only the labelled containers, networks and volumes of this instance, then its files |

### J09AdoptTest

| it | class | fast test or reason |
|---|---|---|
| runs a 1.0.0 install built from the tag with demo data and a stored file | engine-only | Builds the 1.0.0 image from the `v1.0.0` git tag and runs its install guide with real Compose, seed and load data |
| adopts with install.sh --adopt while a writer keeps writing until 1.0 stops: every acknowledged write is in 2.0 | engine-only | Real concurrent writer against real 1.0, real pg_dump/copy; no acknowledged write lost and audit chain heads equal in the copy. The phase order is covered by Feature/Instance/AdoptCommandTest.php::follows the KTD20 order: stop and drain 1.0, back it up, stop its Postgres, copy, verify, then start 2.0, checking 1.0 stayed stopped and the fence by AdoptFenceTest.php, but not the data outcome |
| 3a: kept the APP key, forced the safe values (APP_DEBUG, SSO_ALLOW_INSECURE_IDP, no TRUSTED_PROXIES) | covered | AdoptCommandTest.php::adopts a local 1.0 install: its APP key, database names and sender, capture mail and the forced safe values |
| 3b: serves the copied data in 2.0, audit:verify passes, stored file present, login-link works | engine-only | Real audit:verify over a copied 1.0 chain, the copied volume's file and the running app |
| passes amcheck on the unique text indexes of the copy | engine-only | Real `bt_index_check` with heapallindexed on real indexes (the command reports the index list, AdoptCommandTest.php asserts it, but not that Postgres accepts the indexes) |
| 5a: 1.0's volumes untouched (mounted read-only) and stopped, its env backup holds no APP key | covered | AdoptCommandTest.php::writes the backup in the 1.0 format with only the allow-listed env values but no APP key, and never writes the 1.0 volumes; AdoptFenceTest.php::stops every running 1.0 service that is no infrastructure before the queue drains, and starts it again on the way back (review A1) |
| 5b: 1.0 backup restores in a drill with storage archive, referenced files, render, audit | engine-only | Drill over a real 1.0 dump and a real storage tarball with a real PDF render and real renditions rows; fast drill tests (RestoreDrillCommandTest.php::reports counts, audit heads, files and a render, and leaves storage and the audit log alone) use fixture data |
| restores the adopt's 1.0 backup in place through the upgrade child: data, new epoch, healthy instance | engine-only | Real migration of a restored 1.0 database by the upgrade child, real epoch row, real audit:verify. Orchestration is covered by Feature/Instance/RestoreCommandTest.php::restores the adopt backup in place through the upgrade child, trusted only as the set the adopt recorded (U19 R1, U18) and RestoreFinishCommandTest.php::starts a new epoch from the ledger seed and writes instance.restored as its first entry |
| 7a: uninstall takes a final backup and reports writes since adopt and the restart_1_0 command | covered | AdoptCommandTest.php::records the baseline in the ledger, and uninstall takes a final backup and reports the writes since adopt (final_backup dir, writes_since_adopt, restart_1_0 text); UninstallFinalBackupTest.php for the 2.0 backup with APP keys |
| 7b: `docker compose up -d` in the checkout brings 1.0 back with its pre-adopt data | engine-only | Real Compose start of 1.0 on its untouched volumes |

### J10ResourceBudgetTest

The memory-limit half of the CPU and memory `it` is covered by `Unit/Instance/ComposeRendererTest.php` and `Unit/Instance/ResourceBudgetTest.php`; the cpuset half is engine-only, so the whole `it` counts as engine-only.

| it | class | fast test or reason |
|---|---|---|
| installs domain mode with capture mail inside the envelope, records time to sign-in link | engine-only | Time-to-link and pull timing are measurements on a CPU-pinned engine against Pebble; the install result shape is covered by InstallCommandTest and J02 (smoke is J01 only) but the point here is the timing |
| runs every service pinned to two CPUs with small budget memory limits (memory limits part) | covered | Unit/Instance/ComposeRendererTest.php::gives every service a memory limit from the budget and restart unless-stopped (small, sum <= availableMib); Unit/Instance/ResourceBudgetTest.php::never lets the worst-case sum of limits exceed the available memory of any size |
| runs every service pinned to two CPUs (cpuset part; counted for the whole it) | engine-only | cpuset pinning is done by EnvelopeMonitor on live containers; no product code renders it |
| passes the smoke E2E in Chromium against the instance URL | engine-only | Browser against a domain-mode instance over real TLS under a CPU pin; dev-stack E2E (ci.yml job e2e) runs fieldset-smoke.spec.ts on another target |
| adds the demo org and loads the 1.0 test volume (100k submissions, 150k audit entries) | engine-only | Seeding at volume inside the pinned instance; the load is the precondition of the measurements |
| renders a 10-page PDF inline and through the queue | engine-only | Real Gotenberg/Chromium rendering inside the memory envelope |
| measures admin pages at the 1.0 volume | engine-only | Browser p95 measurements (27 pages) |
| measures the API at the 1.0 volume | engine-only | HTTP p95 measurements (23 endpoints) |
| scheduled backup at the 1.0 volume inside the backup service memory slot | engine-only | Cgroup memory peak of a real backup run; BackupServiceRenderTest proves only the limit that is rendered |
| kept every container alive: no OOM kill, no restart | engine-only | Needs docker events and cgroup memory.events of a real run |
| uninstalls without leaving anything labelled behind | covered | Unit/Instance/UninstallCommandTest.php::removes only the labelled containers, networks and volumes of this instance, then its files (RecordingDockerGateway: only labelled resources removed, files gone, containers removed before networks) |

### J11AgentGuideTest

One parametrized `it` over the 14 `sh runnable` blocks of `docs/guide/agent-guide.md`; one row per block. `Unit/Docs/AgentGuideBlocksTest.php` only checks that blocks parse and map to commands, not their `json expect`.

| it (block, guide line) | class | fast test or reason |
|---|---|---|
| 01 install.sh --demo --secrets-file --json (l.149) | gap | AgentGuideEnvelopesTest: install --demo with --secrets-file; expects data.demo.{organization,users,login_url} and data.next_commands, which no install test asserts (InstallDemoTest asserts demo seeded/passwords/delivered only) |
| 02 fieldset status --json (l.206) | covered | Unit/Instance/StatusCommandTest.php::reports version, mode, URL, mail, services, health and unused sign-in links without changing anything |
| 03 login-link --secrets-file --json (l.217) | gap | AgentGuideEnvelopesTest: login-link with --secrets-file must give sign_in_link.delivered `secrets_file` (LoginLinkCommandTest.php asserts `secrets` and `browser` only) |
| 04 config set mail smtp ... --password-file --test-to (l.235) | covered | Unit/Instance/MailApplierTest.php::switches capture mode to SMTP with the password from stdin, keeps the catcher read-only and reports what was captured (key, from capture, to, mail.mode smtp, checked list); ::reads the password from --password-file and uses implicit TLS on 465 for --smtp-encryption tls |
| 05 fieldset backup --json (l.263) | covered | Unit/Instance/BackupCommandTest.php::runs the backup service once under the lock it holds, after the backup role, and reports the backup (backup, path, kind manual, bytes, encrypted false) |
| 06 restore <backup> --drill --json (l.291) | gap | AgentGuideEnvelopesTest (or RestoreDrillTest.php): assert data.storage is an object; RestoreDrillTest.php::drills a backup in a scratch database ... asserts backup and dropped only |
| 07 restore <backup> --yes --json (l.302) | gap | Feature/Instance/RestoreCommandTest.php::restores in place asserts epoch, previous_epoch, safety_backup but never data.webhooks `paused`; add the assertion (and that runtime.json holds webhooks.paused true) |
| 08 config set webhooks on --json (l.313) | gap | ConfigWebhooksTest.php::pauses and resumes webhooks through runtime.json ... asserts exit and state, not the envelope; add data.key `webhooks` and data.to `on` |
| 09 upgrade --to <version> --json (l.356) | gap | Guide expects data.from and data.to for `--to` the running version (harness release, a no-op); no fast test runs `fieldset:upgrade --to <current>` and asserts from/to (UpgradeFollowTest.php only mocks the child envelope). Add to Feature/Instance/UpgradeReleaseTest.php |
| 10 fieldset version --json (l.405) | covered | Unit/Instance/StatusCommandTest.php::prints the fieldset version without an instance directory, and the instance version inside one (data.instance is a string inside an instance) |
| 11 backup --encrypt --include-secrets --identity-out --passphrase-file (l.417) | gap | BackupCommandTest.php::turns encryption on first with --encrypt, then backs up lacks --include-secrets and does not assert data.encrypted true and data.includes_secrets true; add the combined case there |
| 12 shell: mkdir, cp backup.tar.age, identity, grep APP_KEY lines (l.431) | gap | Pure shell on the layout the earlier blocks create. AgentGuideEnvelopesTest: run the line in a temp HOME against a fixture instance directory and an encrypted backup dir (BackupDaemonTest.php::... already shows backup.tar.age in an encrypted set) and assert exit 0 and an app-key.env with only the APP_KEY/APP_PREVIOUS_KEYS lines |
| 13 install.sh --from-backup (encrypted) --identity-file --passphrase-file --app-key-file (l.445) | gap | InstallFromBackupTest.php::moves a local backup ... asserts authenticated, webhooks paused, confirm_commands for a plain backup; the encrypted + authenticated case (from_backup.encrypted true, authenticated true via --app-key-file) is not tested (::uses the APP key inside an encrypted backup with secrets uses --accept-unauthenticated). Add to InstallFromBackupTest.php |
| 14 uninstall --keep-backups --yes --json (l.470) | gap | The three expected facets live in different tests: removed lists (UninstallCommandTest.php::removes only the labelled containers...), kept ['backups'] (::keeps backups/ with --keep-backups...), final_backup object (UninstallFinalBackupTest.php, UninstallFinalBackupMoveTest.php). No test asserts them together for `--keep-backups` with a running stack; add to UninstallFinalBackupMoveTest.php |

### J12IdempotenceTest

| it | class | fast test or reason |
|---|---|---|
| AE4 + AE3: next free port when 8080 is taken, kept on re-run, re-run changes nothing (no Docker events, no restarts, text says nothing changed) | covered | Unit/Instance/InstallCommandTest.php::takes the next free port when 8080 is taken and keeps it on a re-run (AE4); ::changes nothing when run again with the same flags (AE3) (asserts docker->methodsSince = [] and unchanged state) |
| AE9: install refuses a secret flag value with exit 2 and stdin/file hint, no state written | covered | Unit/Instance/InstallCommandTest.php::rejects a secret flag (AE9) ... (no Docker calls, no leak); Unit/Instance/FieldsetCommandTest.php::rejects a secret given as a flag value with exit 2 and a stdin/file hint (AE9) |
| AE9 through the shim: `doctor --api-token=x` exits 2 secret_in_argument | gap | Same ShimScriptTest case as J04 (argument passes through the shim unchanged to the command, which FieldsetCommandTest already shows refuses it) |
| AE5: domain that does not resolve exits 3 doctor_failed with an A-record hint before any pull or start; no state, env dir or journal left | gap | Mostly covered by InstallCommandTest::exits 3 before pulling anything when the domain resolves elsewhere (AE5) (no pullImage, no composeUp, no state). Missing assertions: no `env/` directory and no `operations/current.json` after the refusal. Add both to that test |

### ConfigDomainSwitchTest

| it | class | fast test or reason |
|---|---|---|
| installs a local instance and shows its settings with secrets redacted | covered | Unit/Instance/ConfigCommandTest.php::shows every setting of a local instance with secrets redacted and no outbound contact by default; install itself: Unit/Instance/InstallCommandTest.php |
| lists the organization with SSO and exits 6 without --yes, state unchanged | covered | Unit/Instance/ConfigDomainTest.php::needs --yes for a URL change and lists the organizations with SSO and the links that keep the old address (exit 6); Feature/Instance/InstanceConfigCommandsTest.php::lists every organization with SSO across tenants and counts the links that keep the old address (the real query behind instance:url-impact) |
| switches to domain mode, recreating only the affected services (added edge, recreated app/queue/scheduler/mailpit), progress events (attempt 1, max 420 s, verify, workers), warnings url_changed and sso_urls_changed | covered | Unit/Instance/ConfigDomainTest.php::switches local mode to domain mode, recreating only the services whose definition changed; ::journals snapshot, preflight, apply, verify and audit ...; ::needs --yes ... (confirmed part: sso_urls_changed); Unit/Instance/SmokeTestProgressTest.php::waits for the certificate in a loop with one progress event per attempt ... (attempt, max_seconds 420); ::announces a phase with its maximum duration and reports its completion. Which containers keep running follows from the recreated list plus composeUp services, which the first test asserts |
| serves HTTPS with a certificate that verifies against Pebble's issuing root, HTTP 308 to HTTPS | engine-only | Real TLS and real Caddy redirect |
| changes nothing when the same domain is set again (containers identical) | covered | Unit/Instance/ConfigDomainTest.php::changes nothing, without any Docker call, when the domain is set again |
| returns to local mode with config set mode local and removes the edge | covered | Unit/Instance/ConfigModeTest.php::returns from domain mode to local mode: the edge goes (orphan removal), its subnet and ACME inputs stay |
| reverts with reverted: true when the ACME order fails: exit 5, rolled_back, certificate_failed not retryable, fix_first names the domain, several attempts within 420 s, state local, no open journal | covered | Unit/Instance/ConfigDomainTest.php::reverts to local mode with reverted: true when the edge gets no certificate; Unit/Instance/ConfigRevertTest.php::reports a reverted change as rolled_back with exit 5 and a fix_first marker for a certificate failure; Unit/Instance/SmokeTestProgressTest.php::gives up after 40 attempts ...; ::stops waiting at the time limit even before the 40th attempt. The old URL still answering is a real-engine side effect of the same restored files |
| audited the two successful changes as instance.config_changed with the host UID | covered | Feature/Instance/InstanceConfigCommandsTest.php::audits a changed setting as instance.config_changed by the command line with the host UID; Unit/Instance/ConfigDomainTest.php::journals ... audits through a one-off (--key, --old, --new, --actor-uid) |
| uninstalls and leaves nothing labelled behind | covered | Unit/Instance/UninstallCommandTest.php::removes only the labelled containers, networks and volumes of this instance, then its files |

### ImageContractJourneyTest

| it | class | fast test or reason |
|---|---|---|
| runs as non-root and reports its version and labels | engine-only | Built image config and `id -u`; Dockerfile text is checked by ImageContractTest::never copies the whole context or ships composer in the final stage (USER www-data) and ::labels the image with version, revision, source and license, but the revision/version values come from the build args |
| carries the ops binaries, executable by the image user, and no cosign | engine-only | Runs docker, compose, pg_dump, age in the image; ImageBinariesTest only checks pins in binaries.json and the Dockerfile |
| holds every path and license file of binaries.json | engine-only | File existence in the built image; ImageBinariesTest::ships the license and notice files the tools stage collects checks the Dockerfile only |
| ships no tests, agent docs or composer, but keeps the spec files the SDK reads | engine-only | Image file tree after build; ImageContractTest::sends only what the image stages read to the builder (allow-list) covers the context, not vendor/ or public/build output |
| starts the app role without permission errors in its logs | engine-only | Runs the container, probes /up, reads logs |
| fits the image size budget of 1.0 GB | engine-only | Size of the built image |
| runs fieldset as an arbitrary user without the app start-up | engine-only | UID 4242 permissions in the image; the entrypoint routing is covered by ImageContractTest::runs php artisan fieldset:<command> and lists the commands without one |

### DogfoodE2ETest

| it | class | fast test or reason |
|---|---|---|
| installs a local-mode demo instance from the harness release | covered | Unit/Instance/InstallDemoTest.php::seeds the demo after the stack started on a loopback-only install and keeps the documented password (demo seeded, passwords default, link under secrets); Unit/Instance/InstallCommandTest.php::installs a local instance phase by phase and hands out the owner link only under secrets (mode local, url http://localhost:8080, mail capture, catcher) |
| passes the E2E suite against the instance, dev-only specs skipped with reason | engine-only | Playwright against an installed instance (E2E_TARGET=fieldset); the dev-stack e2e job in ci.yml runs the same specs on the dev stack but not on an instance, and the instance-only skip rules and 60-pass floor need a real install. Report parsing is covered by Unit/Docker/DogfoodReportTest.php |
| uninstalls without leaving anything labelled behind | covered | Unit/Instance/UninstallCommandTest.php::removes only the labelled containers, networks and volumes of this instance, then its files |

### LocalPreReleaseTest

| it | class | fast test or reason |
|---|---|---|
| publishes a signed multi-arch pre-release whose manifest validates and lists every image by digest | engine-only | Real buildx multi-arch build, registry digests and cosign signature verification; schema and digest listing alone are covered by Unit/Release/ReleaseManifestTest.php::lists every service image by digest with per-platform sizes and validates against the schema |
| tags only the pre-release version and never moves latest, 2 or 2.0 | covered | Unit/Release/ReleaseVersionTest.php::publishes only its own tag for a pre-release (imageTags is exactly [version]) and ::derives the channel and image tags of a stable release (X.Y, X, latest); Unit/Release/ReleaseCommandsTest.php::prints the channel and image tags of a version as JSON |
| keeps the verified release assets and the local public key in dist/ | covered | Unit/Release/ReleaseManifestTest.php::checksums the release assets by file name and ::renders the stack file from the same pins and lists it as an asset; the dist/ copy itself is harness code, no product decision |
| installs it natively with the harness install.sh and reports it with status and version | engine-only | Real install of a signed release (verify, pull, healthy containers); the decisions are covered by InstallCommandTest (secrets file, mode), StatusCommandTest::reports version, mode, URL... and ::prints the fieldset version without an instance directory, and J01 stays in smoke |
| answers /up and /admin/login and lets the sign-in link lead into the password-set flow | engine-only | Live HTTP on the running instance; the link flow is covered by Feature/Identity/LoginLinkTest.php::hands only a fresh reset token to the session and leaves exactly one broker token |
| uninstalls with --yes without leaving a labelled container, volume or network | covered | Unit/Instance/UninstallCommandTest.php::removes only the labelled containers, networks and volumes of this instance, then its files (8 containers, networks and volumes removed, directory emptied) |
| writes a redacted summary for the milestone evidence | covered | Unit/Docker/EvidenceRedactorTest.php::redacts CSP nonces, sign-in links, signatures and token fields, and leaves the rest alone; path replacement is harness code |

### Summary

| file | covered | gap | engine-only |
|---|---|---|---|
| J02DomainModeTest | 5 | 1 | 4 |
| J03ProxyModeTest | 3 | 0 | 2 |
| J04CaptureModeTest | 7 | 1 | 6 |
| J05DemoTest | 10 | 1 | 0 |
| J06PhoneHttpsTest | 8 | 2 | 2 |
| J09AdoptTest | 3 | 0 | 7 |
| J10ResourceBudgetTest | 1 | 0 | 9 |
| J11AgentGuideTest | 4 | 10 | 0 |
| J12IdempotenceTest | 2 | 2 | 0 |
| ConfigDomainSwitchTest | 8 | 0 | 1 |
| ImageContractJourneyTest | 0 | 0 | 7 |
| DogfoodE2ETest | 2 | 0 | 1 |
| LocalPreReleaseTest | 4 | 0 | 3 |
| total | 57 | 17 | 42 |
