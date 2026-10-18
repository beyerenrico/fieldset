# 0080: Capture mode experience, `fsa config set mail` and `operator-email`
- Status: accepted
- Date: 2026-10-18
- Refines: ADR 0054 (capture mail), ADR 0077 (fsa config), ADR 0078 (config safety); closes the `mail` and
  `operator-email` deferrals of ADR 0077

## Context
Plan unit U12 (PLAN M11-T01): R22–R25, R59, R66, AE1, AE9, J4, KTD13, KTD14, and the `operator-email` key that U11
deferred. New instances capture mail in the bundled Mailpit (ADR 0054); admins must see that, operators must be able to
switch to real SMTP without putting a password on a command line (R50), and the switch must leave the instance as it
was when the server refuses mail (ADR 0078). Facts checked on 2026-10-18: Mailpit's `MP_SMTP_BIND_ADDR` sets the SMTP
listener (default `0.0.0.0:1025`) and `MP_SMTP_AUTH_ACCEPT_ANY`/`MP_SMTP_AUTH_ALLOW_INSECURE` let it accept any login
over plain SMTP (Context7, mailpit.axllent.org runtime options); Mailpit has no read-only UI switch (not found in the
docs); the operator panel takes password sign-ins only (ADR 0041), and the admin panel's topbar needs an organization
(UseDefaultTenantOutsideTenantRoutes).

## Decision
**Capture mode in the app.** fsa renders `FSA_MAIL_CAPTURE=true` for app, queue and scheduler while the instance mailer
delivers into the catcher; the app reads it as `config('mail.capture')` (unset: false, so checkouts, dev and CI never show
a banner). The stack file keeps it overridable (`${FSA_MAIL_CAPTURE:-true}`), to be set false with a real SMTP server.
Rejected: inferring capture from `MAIL_HOST=mailpit`, which the dev stack also uses (the E2E suite would see banners).

**Banner** (`App\Filament\Admin\CaptureModeBanner`, admin panel `BODY_START`, view
`filament/admin/capture-mode-banner`, `lang/{de,en}/capture.php`): on every page of an organization while capture is on,
for every signed-in member (not only those who manage the organization: anyone sending a document or an invitation
must know it reaches nobody; E8 "no silent non-delivery"). Variant `full` for organizations on the instance mailer,
`narrow` ("invitations and password resets are captured") for organizations whose MailSetting `usesOwnSmtp()` (R59).
Operators (`PlatformAdmins::check`) see `fsa config set mail smtp --smtp-host <host> --smtp-username <user>
--password-stdin` instead of "ask your instance owner"; members with `organization.manage` also get the Mail settings
link, in the full variant only (an own SMTP server does not help invitations and resets). A named `role="region"`
landmark, inline styles, white on `#1e3a8a` (10.4:1), not sticky (the impersonation banner is). `MailCheck` warns in
capture mode whether or not the catcher answers, naming its reachability in the details.

**`fsa config set mail smtp|capture`** (`Config\Appliers\MailApplier`, `MailValues`, `MailCompletion`; a
`SettingApplier` with the new optional `SettingHooks`):
- Flags: `--smtp-host` (required from capture mode), `--smtp-port` (default 587, 465 with TLS), `--smtp-encryption
  starttls|tls` (STARTTLS when offered → `smtp_scheme` unset; implicit TLS → `smtps`), `--smtp-username`,
  `--password-stdin` (a hidden prompt in a terminal, else stdin; one trailing newline dropped) or `--password-file`
  (input file flag, mounted read-only by the shim), `--from-address`, `--from-name`, `--test-to`, `--remove-catcher`.
  Flags left out keep their values, the credentials included (replaced only by a new `--smtp-username` with a
  password). A user name needs a password and a password a user name (exit 2). Any flag whose name holds a secret word
  (`--smtp-password=…`) is exit 2 `secret_in_argument` before parsing; its hint now names `--password-stdin` and
  `--password-file`. SMTP options on other keys, and `capture` with options, are exit 2 `invalid_flag`.
- Credentials (`Config\SmtpCredentials`, the user name treated as a credential like `config show` already did) live only
  in the app roles' env files. They are registered with the output redactor first, never journaled (the journal context
  records the non-secret options and whether credentials were given), never in state, JSON, audit or progress. A changed
  password alone is a change (`ConfigChange::$credentials`).
- Flow: ADR 0077/0078 unchanged (snapshot, preflight, apply with workers stopped, verify, workers, audit, revert). Apply
  first keeps the previous credentials in `env/smtp-previous` (0600, not `*.env`, so no service reads it and renders do
  not prune it); a revert, also one a resumed run finishes, renders the previous state with them; the file is deleted
  once the change is settled (verified and running, or reverted).
- Verify adds the test mail (`SettingHooks::verify`): the socket-less one-off `instance:mail-test` sends it
  synchronously through the mailer the new env file configures, to `--test-to` or the longest-standing active operator.
  It runs when delivery changes (from capture, or server, port, encryption, sender or account). A refusal is
  `test_mail_failed` (not retryable, `fix_first`), so the change reverts with exit 5 and capture (or the previous server)
  stays. Queue and scheduler are stopped meanwhile, so nothing queued goes out through an unverified server.
- The catcher: switching to SMTP keeps Mailpit (setting `mail_catcher: kept`) with its volume and UI (loopback port, or
  `/_mail/` behind the edge in domain mode) but binds its SMTP listener to the container's loopback
  (`MP_SMTP_BIND_ADDR=127.0.0.1:1025`), so nothing reaches it any more: "read-only" means it receives nothing; the UI can
  still delete messages (Mailpit has no read-only UI). `--remove-catcher` (also alone later, then without a new test mail)
  drops the service (`up --remove-orphans` removes the container) and, once the change runs, its volume
  (`<project>_mailpit-data`); a failed volume removal is a warning with the `docker volume rm` command.
  `config set mail capture` clears the SMTP settings and credentials and brings the catcher back (its volume if kept, a
  free loopback UI port chosen like `mode local` when the remembered one is taken).
- What capture kept from people (`SettingHooks::completed`, after the workers run, never reverting): the one-off
  `instance:mail-captured` counts pending invitations (7-day links) and unexpired password-reset tokens from the app's
  own records (not the tokens behind unused owner sign-in links, which fsa handed over itself); the result carries `data.mail.captured` and a `captured_mail` warning to resend them. Rejected: counting
  the catcher's messages, which needs its credentials in the app and localized subjects, and counts what nobody needs to
  resend.
- Audit: `instance.config_changed` with `from`/`to` such as `capture` → `smtp://smtp.example.com:587 (catcher kept)`,
  never a credential. `data.mail` reports mode, catcher (`active|kept|removed`), catcher URL, captured counts, the removed
  volume and `remove_catcher_command`. A repeated command names `--smtp-username <the same user name> --password-stdin`.

**`fsa config set operator-email <email> [--revoke-previous]`** (`Config\Appliers\OperatorEmailApplier`,
`ConfigKeys::DIRECT`, app side `App\Domain\Platform\Instance\SetOperatorEmail`): operators live in the app's database,
and the state keeps personal data only as digests (InstallRecord), so nothing is rendered or journaled. Under the
instance lock the one-off `instance:operator-email` runs one transaction: refuse an address whose domain enforces SSO
(the operator panel takes passwords only) or a deactivated account (exit 2); create the account when there is none (no
organization, unknown password, address counted as verified); grant the operator role; issue the owner link (password-
reset broker, 60 minutes) when the account has no password of its own; with `--revoke-previous` revoke every other
operator (confirmation required: exit 6 without `--yes` and without a terminal); audit `instance.config_changed` (key,
new address, revoked addresses) beside the `platform.admin_*` entries. The link leaves through SignInDelivery
(--secrets-file, browser hand-over, or `secrets`). An address that already is the operator is `changed: false`. "The
previous operator" means every other operator: the state does not keep the old address, and an instance moving to a new
operator wants exactly one. A new operator without an organization lands on the `/platform` sign-in after setting the
password (`ResetPassword`), not on an admin page it has none of.

**Catcher credentials** for the loopback modes: `fsa config show --catcher-login` adds the generated Mailpit password as
the one-time secret `catcher_password` (stdout `secrets`, or `--secrets-file`) and `data.catcher_login` (URL, user `fsa`).
Exit 2 `catcher_login_not_needed` in domain mode (the edge signs in for a `/platform` session with MFA, R66) and
`no_catcher` once removed. Rejected: a new top-level command (one more name for the launchers' command lists) and
`config show --secrets` (would invite printing every secret). The flag avoids the word "credentials", which the secret
flag guard refuses.

**Launchers.** The value-flag lists of `docker/app/fsa` and `distribution/shim.sh` gain `--from-address --from-name
--password-file --smtp-encryption --smtp-host --smtp-port --smtp-username --test-to` (sync tests unchanged).

## Alternatives considered
- **Testing the SMTP server before apply** with the new settings passed to a one-off: the password would sit on a
  `docker compose run -e` command line (host process list) or need a second env file; apply-then-verify with workers
  stopped gives the same guarantee through the existing revert.
- **Removing the catcher on the first switch** (origin R25's wording): the catcher holds mail people never got; the plan
  (KTD13) keeps it until the operator confirms.
- **A journal for `operator-email`**: one transaction app-side either happened or not; the lock serializes it with other
  operations.

## Consequences
- AE1 holds per organization; the banner disappears with the next request after the switch (env change recreates the
  app roles).
- `config show` lists `mail` (value `capture|smtp`) and `operator-email` (value `null`: not kept in the state) as
  settable in every mode; `mail.smtp.encryption` and `mail.catcher` are new fields.
- Mail sent through SMTP from a local install defaults to `forms@localhost`, which most servers refuse: the result warns
  `sender_localhost` (pass `--from-address`).
- Unverified until the human checklist: a real provider's SMTP with STARTTLS and with implicit TLS (J4 uses Mailpit over
  plain SMTP with AUTH), and the banner's DE text on a real screen reader.

## Amendment 2026-10-18: install --smtp-* flags
Closes the gap U12 left (§7 lists `--smtp-*` for `install`; E8 "capture mode until SMTP is configured"; R11 "a test
mail into capture or the configured SMTP"). `fsa install --smtp-host <host> [--smtp-port <port>] [--smtp-encryption
starttls|tls] [--smtp-username <user> --password-file <path>|--password-stdin] [--from-address <email>] [--from-name
<text>]` installs straight into SMTP mode.
- **One parser for both commands.** `Console\SmtpInput` holds what `config set mail` did inline: the flags as
  `MailOptions`, the account as `SmtpCredentials`, the password from a file or stdin (hidden prompt in a terminal, one
  trailing newline dropped, at most 4096 bytes), registered with the redactor as soon as it is read. The grammar is
  `MailValues`/`ConfigValues` for both (`Install\InstallMail::validate`). `install` has no `--test-to` (the smoke test
  mails the owner) and no `--remove-catcher` (no catcher is created).
- **Phases** (`Install\InstallMail`). The server flags are consumed by the doctor phase and journaled in its data under
  `mail` (`mail: smtp`, `smtp_host`, `smtp_port` (default 587, 465 with `tls`), `smtp_scheme` (`smtps` for `tls`), and
  `mail_from_address`/`mail_from_name` when given): the doctor chooses no catcher UI port (`DoctorContext::$catcher`;
  on a shared proxy network no loopback port at all) and checks no Mailpit image, and the state phase writes these
  settings, so the renderer leaves Mailpit, its volume, `FSA_MAIL_CAPTURE`, the `/_mail/` route and the edge's catcher
  credential out and renders MAIL_* for the server into app, queue and scheduler. The account is consumed by the state
  phase: `InstanceStack::writeState` renders it into the app roles' env files (0600) only, like `config set mail smtp`
  stores it; the journal context records `smtp_credentials: 1` and the non-secret flags, never the user name or the
  password; state, envelope, progress and phase data never hold them.
- **Exit codes** (all exit 2 before any Docker call unless noted): an SMTP flag without `--smtp-host` →
  `input_required` (`data.missing: ["--smtp-host"]`); `--smtp-username` without a password source, an empty password,
  or a resume before the state phase without the password again → `password_required`; a password source without
  `--smtp-username`, both sources, or an unreadable file → `invalid_flag`; a malformed value → `invalid_value`; a
  password as a flag value → `secret_in_argument` (SecretFlagGuard, unchanged); `--password-stdin` under install.sh
  without a terminal → `password_stdin_unavailable` (ADR 0070 amendment). The smoke test's mail refused by the server →
  exit 4 `test_mail_failed`, retryable, journal open at `smoke` (ADR 0072 amendment).
- **Resume and repeat.** After the doctor phase other server flags are `flag_already_applied`; after the state phase so
  is another account (compared with the env file, `InstallMail::comparator`). A finished instance compares the flags
  with its state as it is now (`fsa config set mail` may have changed it; the sender as in effect) and the account with
  the env file: the same flags and password are `changed: false`; others are `use_config_set` with `key: mail` and a
  hint naming `fsa config set mail smtp|capture`.
- **A refused test mail** is retryable for a server that was briefly unreachable (the resume re-runs the smoke test).
  Other settings or another account cannot be corrected on a resume (the state phase rendered them and the stack runs
  with them): the hint names `fsa uninstall --yes` and a new install with corrected flags, or an install in capture mode
  and `fsa config set mail smtp`, which tests the server and keeps capture when it refuses. Rejected: re-rendering and
  restarting the app roles inside the smoke phase of a resumed install (a second apply path beside `config set`, for a
  case uninstall covers on an instance without data). A local install without `--from-address` warns
  `sender_localhost` in the result and names it in the `test_mail_failed` hint.
- **Orthogonal to the modes.** `--smtp-*` combines with `--domain` (sender default `forms@<domain>`), proxy mode,
  `--phone-https` and `--demo`. `fsa config show` (`settings.mail.mode: smtp`, `catcher: removed`, which also means
  "never created") and `fsa status` (`mail.mode: smtp`, no `mailpit` service) needed no change.
- Verified by `tests/Unit/Instance/InstallSmtpTest.php`, `InstallSmokeTest.php` and J4's last case (a second instance
  installed with `--smtp-*` and a password file; the smoke test's mail arrives at a stand-in Mailpit attached to the
  instance's default network while install.sh runs; no catcher container).
