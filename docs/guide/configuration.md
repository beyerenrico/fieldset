# Configuration

An instance's settings live in its `state.json` and change only through `fsa config set`. `fsa` renders the Compose
project and the environment files (`compose.yaml`, `env/*.env`) from that state and checks every change before it
keeps it. **Do not edit `env/` or `compose.yaml` by hand:** the next `fsa` command rewrites them, and a hand-made
change bypasses the checks and the automatic revert.

The 1.0 reference of `.env` variables for checkout installs is in the [archive](archive/1.0-configuration.md).

## Show and change settings

```sh
fsa config show                       # every setting, the keys you can set in this mode, outbound contacts
fsa config set <key> <value>          # change one setting
```

`fsa config set` validates the value, runs the checks the change needs (DNS, ports, the SMTP server), applies it,
restarts only the services whose configuration changed and verifies the instance. If the verification fails, the
change is reverted and the command exits 5 `rolled_back`; `errors[0]` names the cause and `data.fix_first` or
`data.retry_after` says what to do. Setting a value that is already set changes nothing. Changes that move the
instance to another URL ask for confirmation first (`--yes` confirms).

## Keys

| Key | Values | Modes | Notes |
|---|---|---|---|
| `domain` | a DNS name | all | Switches to domain mode with `https://<name>`. Checks DNS and ports 80/443 first, keeps the change only once a certificate was issued. `--acme-email`, `--acme-staging`, `--acme-ca`, `--acme-ca-root-file`. Changes the URL. |
| `mode` | `local` | domain, proxy | Back to local mode. Changes the URL. |
| `url` | an http(s) origin | proxy | The URL your proxy serves. Changes the URL. |
| `port` | a port number | local, proxy (`port`) | Changes the URL. |
| `trusted-proxy` | a container name, an address or `auto` | proxy | The one proxy address the app trusts for forwarded headers. `auto` finds the proxy container again after it was recreated. |
| `acme-email` | an address or `none` | domain | The ACME account's contact. |
| `acme-ca` | `production`, `staging` or a URL | domain | Which ACME CA issues certificates. |
| `mail` | `smtp` or `capture` | all | See [mail](#mail). |
| `phone-https` | `local-ca` or `off` | local | HTTPS for phones in the LAN through a local CA (the tunnel is `fsa tunnel`). Changes the URL. |
| `lan-address` | an IPv4 address | local | The LAN address the local CA's certificate is for. |
| `backup-schedule` | `HH:MM` or `off` | all | Daily backup time in the instance's time zone (default 03:30). |
| `backup-retention` | 1 to 365 | all | Scheduled backups kept (default 7). |
| `backup-encryption` | `on` or `off` | all | `on` needs `--identity-out <file>` and `--passphrase-file <file>` (or `--passphrase-stdin`); see [backup and restore](backup-restore.md#encryption). |
| `webhooks` | `on` or `paused` | all | Restores and moves pause webhook deliveries; `on` resumes them. |
| `update-check` | `on` or `off` | all | Off by default. `on` lets the app read the release manifest once a day and show "update available" with the notes link and `fsa upgrade` on the `/platform` instance page. Nothing else is sent; `fsa config show` lists the manifest URL under `outbound_contacts` while it is on. |
| `operator-email` | an e-mail address | all | Adds a platform operator (an admin of `/platform`) and, when the account has no password yet, issues a sign-in link for them. `--revoke-previous` revokes every other operator (asks for confirmation). |

`fsa config show` lists under `data.keys` which keys this instance's mode accepts. A key from another mode is refused
with exit 2 and names the key to use instead.

## Mail

New instances capture mail in the instance's mail catcher (Mailpit); the admin dashboard shows a banner while they do
(see the [admin guide](admin-guide.md#mail-capture-banner)). Switch to an SMTP server:

```sh
fsa config set mail smtp --smtp-host smtp.example.com --smtp-username forms@example.com --password-file ~/smtp-password --from-address forms@example.com --test-to you@example.com
```

- The password comes from `--password-file <file>` or `--password-stdin` (piped, or a hidden prompt on a terminal),
  never from the command line.
- `--smtp-port` (default 587), `--smtp-encryption starttls|tls|none` (default `starttls`; `tls` is implicit TLS on
  465, `none` only for a relay in a trusted network), `--from-name`.
- `fsa` sends a test mail through the new server (to `--test-to`, or the operator) and keeps the change only if the
  server accepts it; otherwise it reverts with exit 5 `test_mail_failed`.
- The catcher keeps running so you can still read captured mail; `--remove-catcher` removes it and its data.
- `fsa config set mail capture` goes back to capturing.
- `fsa config show --catcher-login --secrets-file <file>` writes the catcher's login to a file.

Organizations can also use their own SMTP server for their mail ("E-Mail-Einstellungen" in the admin dashboard).
Invitations, password resets and e-mail confirmations always go through the instance's mailer.

## What fsa sets for you

The rendered environment always runs the app in production mode with debug off, registration off, encrypted
database sessions, PDF rendering through the instance's Gotenberg, secure SSO settings and exactly one trusted proxy
address for the mode (the edge in domain and local-CA mode, the proxy container in proxy mode, none otherwise). The
database, Valkey and catcher passwords and the APP_KEY are generated at install and never printed.

Settings of the 1.0 `.env` that `fsa config set` does not offer (for example error reporting, upload scanning or log
channels) are not configurable on a 2.0 instance. `fsa adopt` takes over only the APP keys, the URL (and with it the
mode and port), the sender and the database names from a 1.0 `.env`; everything else comes from `fsa`'s rendering.
Mail starts captured: the 1.0 SMTP server is only suggested for `fsa config set mail smtp`.

## Environment variables of install.sh and fsa

| Variable | Meaning |
|---|---|
| `FSA_RELEASE_URL` | A mirror of the GitHub Releases page (https), for install.sh and `fsa upgrade` |
| `FSA_REGISTRY` | A registry mirror (`host[:port]`) the images are pulled from |
| `DOCKER_DEFAULT_PLATFORM` | `linux/amd64` or `linux/arm64`: pull and run that platform (another one runs emulated) |

## Organization settings

Everything that belongs to one organization (branding, members, SSO, mail settings, retention, webhooks, API tokens)
is set in the admin dashboard; see the [admin guide](admin-guide.md).
