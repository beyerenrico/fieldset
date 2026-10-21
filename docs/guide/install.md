# Install guide

form-schema-app 2.0 installs from a signed release with one command. You need no clone of the repository: the
installer downloads the release manifest, checks its signature, pulls the images by digest and hands over to `fieldset`,
the instance's command-line tool. Every later step (configuration, backups, upgrades) goes through `fieldset` as well.

Agents and scripts: the [agent guide](agent-guide.md) has the same steps with the JSON fields to read. Installations
from a clone of the repository (1.0) are described in the [archive](archive/1.0-install.md); move them to 2.0 with
[`fieldset adopt`](#adopt-a-10-install).

## Requirements

- Linux (amd64 or arm64) with Docker Engine 24 or later, or Docker Desktop on macOS. `fieldset`
  brings its own Docker Compose.
- At least 2 GB of memory for Docker and 4 GB of free disk space (10 GB recommended). `fieldset doctor` checks both.
- `curl`.
- For a public instance with HTTPS: a domain whose A (and AAAA) record points to the server, and the ports 80 and
  443 free and reachable from the internet.

## Install

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/form-schema-app/releases/latest/download/install.sh | sh -s -- --org "Example Ltd" --owner-email owner@example.com
```

`releases/latest` is the newest stable release. A pre-release (and any specific version) is installed from its own
release, with the version named twice:

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/form-schema-app/releases/download/v<version>/install.sh | sh -s -- --version <version> --org "Example Ltd" --owner-email owner@example.com
```

What happens:

1. `install.sh` checks Docker, downloads the release manifest and verifies its signature with cosign against the
   release workflow's identity built into the script. Nothing is pulled before that check passed.
2. It creates the instance directory (`--dir`; default `/opt/fieldset` for root on Linux, otherwise
   `~/fieldset`), pulls the app image by digest and installs the `fieldset` shim into it.
3. `fieldset install` runs the doctor (Docker, memory, disk, ports, DNS), pulls the other images, writes the Compose
   project, starts the services, migrates the database, creates the organization and its owner, renders a test PDF,
   sends a test mail and prints the URL and a one-time sign-in link for the owner.

The sign-in link is valid for 15 minutes and works once; it leads to "set a password". `--secrets-file <file>` writes
it to a file (mode 0600) instead of the terminal; on a desktop, `--open` opens it in the browser. A new link:
`fieldset login-link owner@example.com`.

Other flags of `fieldset install`: `--owner-name`, `--locale de|en` (default `de`), `--port` (local mode), `--demo`
(sample data, see below), `--json` and `--yes` (no questions). `install.sh` itself takes `--dir`, `--version`,
`--link` / `--no-link` (link `fieldset` into your PATH, `/usr/local/bin` for root or `~/.local/bin` otherwise; without
either flag it asks, but only on a terminal and without `--yes` or `--json`; otherwise it does not link) and
`--help`. `<dir>/fieldset install --help` lists everything.

If an install stops (a failed check, a lost connection), fix the cause and run the same command again: it resumes
where it stopped. Running it again after it finished changes nothing.

## Modes

**Local** (the default): the app listens on `http://localhost:<port>` (8080 or the next free port) on this computer
only. For trying it out and for demos.

**Domain**: `--domain forms.example.com --acme-email ops@example.com`. A Caddy edge container publishes ports 80 and
443, gets a certificate from Let's Encrypt and redirects HTTP to HTTPS. `--acme-staging` uses Let's Encrypt's staging
CA for a first test (browsers do not trust its certificates; switch with `fieldset config set acme-ca production`).
`--acme-ca <url>` (with `--acme-ca-root-file`) uses another ACME CA.

**Behind your own reverse proxy**: `--behind-proxy traefik|caddy|port --url https://forms.example.com`.
- `traefik` and `caddy` (caddy-docker-proxy): the app joins the proxy's Docker network (`--proxy-network <name>`,
  required) with routing labels. The app trusts exactly one address for forwarded headers: the proxy container on that
  network, found automatically or named with `--trusted-proxy`.
- `port`: the app listens on `127.0.0.1:<port>` and your proxy on the host forwards to it.

The mode can change later: `fieldset config set domain <name>`, `fieldset config set mode local` (see
[configuration](configuration.md)).

**Phones.** A phone needs HTTPS for the Field App's offline features. A local instance offers two ways:
`--phone-https local-ca` (a local certificate authority for the LAN address, `--lan-address <IPv4>` when there are
several; the phone trusts the CA once, guided by a trust page) and `--phone-https tunnel` (a temporary public
`trycloudflare.com` address, see `fieldset tunnel`). A domain instance needs neither.

## Mail

A new instance captures mail: everything it sends lands in its mail catcher (Mailpit) and reaches nobody, and the
admin dashboard shows a "mail is captured" banner on every page. This keeps a test instance from mailing real
people. Set up delivery during the install with `--smtp-host`, `--smtp-port`, `--smtp-encryption`,
`--smtp-username`, `--password-file` and `--from-address`, or later with `fieldset config set mail smtp` (see
[configuration](configuration.md#mail)). The catcher's address is in the install result and in `fieldset status`;
in local and proxy mode, `fieldset config show --catcher-login` prints its login (in domain mode the edge signs you in
through `/platform`).

## Demo

`--demo` (or `fieldset demo` on an installed instance) adds a demo organization with sample forms, submissions and users.
On an instance that only listens on this computer, the demo users have the documented password `password`; as soon as
the instance is reachable from other machines (domain, LAN certificate, tunnel), each demo user gets a random password
instead, printed once. `fieldset demo --rotate-passwords` issues new ones. `fieldset reset --demo` starts over.

## After the install

```sh
fieldset status          # services, URL, mail, backups (run in the instance directory, or <dir>/fieldset)
fieldset doctor --post   # the checks again, plus every service healthy
fieldset logs app        # logs of one service (app, queue, scheduler, postgres, redis, gotenberg, mailpit, backup, edge, tunnel)
```

The shim runs each command in a short-lived container of the instance's app image. Use it from the instance directory
(`./fieldset`), by its full path (`/opt/fieldset/fieldset`), or through the PATH link (`fieldset`).

Platform operators manage the instance itself in the `/platform` panel. The instance's owner is a platform operator;
`fieldset config set operator-email <email>` adds one.

Next: set up mail delivery, check the backup schedule and copy backups off the host ([backup and
restore](backup-restore.md)).

## The instance directory

| Path | What |
|---|---|
| `fieldset` | the shim |
| `state.json` | the instance's settings and image digests (written by `fieldset` only) |
| `compose.yaml`, `env/` | the generated Compose project and its environment files. Do not edit them: `fieldset` rewrites them, and `fieldset config set` is the way to change a setting |
| `backups/` | backups and `ledger.log` |
| `staging/` | private working files (never copy or sync it) |
| `runtime/`, `operations/`, `locks/` | runtime settings of the app, journals of running operations, locks |

The directory is mode 0700. Docker volumes hold the database, the uploaded files and the certificates; they carry the
label `app.fieldset.instance=<instance UUID>`.

## Run without fieldset

Each release also ships `compose.stack.yaml`: the same images by digest as a plain Compose file for running behind your
own proxy without `fieldset` (environment variables supply the secrets and the URL). You then do backups, upgrades and
certificates yourself; `fieldset` does not manage such a stack.

## Adopt a 1.0 install

A 1.0 installation from a clone of the repository moves to 2.0 with:

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/form-schema-app/releases/latest/download/install.sh | sh -s -- --dir /opt/fieldset --adopt /srv/form-schema-app
```

(or `fieldset adopt <checkout>` from an existing 2.0 shim). It reads the checkout without changing it, takes a 1.0-format
backup, stops the 1.0 project and drains its queue, copies the database and files into the new instance's volumes,
verifies counts and audit chain heads, and starts 2.0 with the same APP_KEY. If anything fails before 2.0 starts, 1.0
is started again. Only allow-listed settings of the 1.0 `.env` are taken over; an external database or a
`COMPOSE_FILE` override is refused (exit 7). Going back to 1.0 is `docker compose up -d` in the checkout after
`fieldset uninstall`. Confirm with `--yes`, or answer the question.

## Remove an instance

`fieldset uninstall --keep-backups` removes the instance's containers, volumes, networks and its directory after a
confirmation, but first writes a final backup and keeps `backups/`. Use it unless you are sure: a plain
`fieldset uninstall` deletes the backups too.
