# Security Policy

## Reporting a vulnerability

Please **do not** open a public issue for security problems. Email the maintainers at
`beyer.enrico@outlook.com` with:

- a description of the issue and its impact,
- steps to reproduce or a proof of concept,
- affected versions or commit.

We acknowledge reports within 3 working days and aim to ship a fix or mitigation within 30 days
for high-severity issues. We credit reporters who want to be named.

## Supported versions

Security fixes go to the newest 2.x minor release (2.1 today). Versions 1.0 and 2.0 get none. Installs from a clone
of the repository (1.0) move to 2.x with `fieldset adopt`; see the
[install guide](docs/guide/install.md#adopt-a-10-install).

## Scope

In scope: the application in this repository, its Docker images and the Fieldset Spec tooling.
Out of scope: third-party services an operator connects (SMTP providers, identity providers).

## Hardening notes for operators

An instance installed with `fieldset` generates its own database, cache and mail catcher passwords and its `APP_KEY`,
runs in production mode with debug off and registration off, and sets secure cookies in the HTTPS modes (see
[configuration](docs/guide/configuration.md#what-fieldset-sets-for-you)). What stays with the operator:

- **Upgrade.** Run `fieldset upgrade` when a release is out; it takes a safety backup and rolls back when it fails
  ([upgrade guide](docs/guide/upgrade.md)). `fieldset config set update-check on` makes the instance page in
  `/platform` show when a newer release exists.
- **Backups.** Copy `backups/` off the host regularly and turn on backup encryption first: a plain backup holds personal
  data readable. Keep the `APP_KEY` lines, the backup identity and its passphrase outside the host, never copy
  `staging/`, and run a restore drill after each upgrade ([backup and restore](docs/guide/backup-restore.md)).
- **Access to the host.** The instance directory is mode 0700 and `fieldset` reaches Docker through its socket. Limit
  who can read the directory and use the socket to the operator's account.
- **Check the instance.** `fieldset doctor --post` and `fieldset status` report failed checks and backups
  ([install guide](docs/guide/install.md#after-the-install)).
- **Accounts.** Two-factor authentication is optional; ask admins and operators to set it up in their profile. Where
  members sign in through a company account, enforce single sign-on in the admin dashboard; it keeps a break-glass
  Owner with two-factor sign-in ([admin guide](docs/guide/admin-guide.md#single-sign-on-sso)).
- **Mail.** A new instance captures mail instead of sending it; set up SMTP with `fieldset config set mail smtp`
  ([configuration](docs/guide/configuration.md#mail)).
- **Stack file.** `compose.stack.yaml` runs the images behind your own proxy without `fieldset`; certificates, backups
  and upgrades are then yours ([install guide](docs/guide/install.md#run-without-fieldset)).

### Running from source

- Keep `APP_DEBUG=false` and set a unique `APP_KEY` in production.
- Run behind HTTPS; set `SESSION_SECURE_COOKIE=true`.
- The PDF renderer (Gotenberg) runs on an internal Docker network with Chromium network access denied.
- Back up the database and storage volume; test restores regularly.
