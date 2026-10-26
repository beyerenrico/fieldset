# Install guide

Fieldset 2.0 installs from a signed release with one command. You need no clone of the repository: the
installer downloads the release manifest, checks its signature, pulls the images by digest and hands over to `fieldset`,
the instance's command-line tool. Every later step (configuration, backups, upgrades) goes through `fieldset` as well.

Agents and scripts: the [agent guide](agent-guide.md) has the same steps with the JSON fields to read. Installations
from a clone of the repository (1.0) are described in the [archive](archive/1.0-install.md); move them to 2.0 with
[`fieldset adopt`](#adopt-a-10-install).

## Requirements

- Linux (amd64 or arm64) with Docker Engine 24 or later, Docker Desktop on macOS, or Windows with WSL 2 and Docker
  Desktop or Docker Engine (see [Windows](#windows-wsl-2)). `fieldset` brings its own Docker Compose.
- At least 2 GB of memory for Docker and 4 GB of free disk space (10 GB recommended). `fieldset doctor` checks both.
  With large data (around 100,000 submissions, a database over 1 GB) give Docker 4 GB of memory: on 2 GB, saved
  reports, the submission explorer's search and the submissions inbox can take up to two seconds.
- `curl`.
- For a public instance with HTTPS: a domain whose A (and AAAA) record points to the server, and the ports 80 and
  443 free and reachable from the internet.

## Install

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/latest/download/install.sh | sh -s -- --org "Example Ltd" --owner-email owner@example.com
```

`releases/latest` is the newest stable release. A pre-release (and any specific version) is installed from its own
release, with the version named twice:

```sh
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/download/v<version>/install.sh | sh -s -- --version <version> --org "Example Ltd" --owner-email owner@example.com
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

## Windows (WSL 2)

Fieldset runs on Windows inside WSL 2, the Linux system built into Windows. Every command in this guide runs in the
terminal of a WSL 2 distro (for example Ubuntu), not in PowerShell or Git Bash. Native Windows is not supported.

**1. Check WSL 2.** In PowerShell, `wsl -l -v` lists your distros. The `VERSION` column must say 2. Convert a WSL 1
distro with `wsl --set-version <distro> 2`. `install.sh` refuses WSL 1 ([WSL 1 and Windows
drives](troubleshooting.md#wsl-1-and-windows-drives)).

**2. Choose a Docker.**

- **Docker Desktop** (recommended, and the easier way for phones). Install it on Windows, then turn on Settings >
  Resources > WSL integration for your distro: new versions of Docker Desktop leave it off. Docker Desktop must run
  whenever you use Fieldset ([Docker is missing or not
  running](troubleshooting.md#docker-is-missing-or-not-running-in-wsl)).
- **Docker Engine inside the distro.** Install Docker Engine in the distro as on Linux. So that it starts with the
  distro and keeps running without a terminal, turn on systemd in `/etc/wsl.conf` (`[boot]`, `systemd=true`), set
  `instanceIdleTimeout=-1` under `[general]` in `%UserProfile%\.wslconfig` on Windows, then run `wsl --shutdown` in
  PowerShell and open the distro again. `fieldset doctor` warns when either is missing ([the instance stops with the
  distro](troubleshooting.md#the-instance-stops-with-the-distro)).

**3. Keep the instance in the Linux home.** The default `~/fieldset` is right. Never put the instance on a Windows drive
(`/mnt/c/…`) or on a link to one: Linux owners and permissions do not work there, and `install.sh` refuses it. Backups
may be copied to a Windows drive.

**4. Install.** Run the [install command](#install) in the distro's terminal, unchanged. `install.sh` measures how Docker
shows your files inside containers and sets up the instance for it. A directory that a failed 2.1.0 install left behind
is fine: run the command again ([ownership errors](troubleshooting.md#ownership-errors)).

**5. Open the app.** The owner's sign-in link opens in your Windows default browser. It is also printed in the
terminal, in case no browser opens ([interop is off](troubleshooting.md#interop-is-off)). The URL
(`http://localhost:<port>` in local mode) works in the Windows browser.

**6. Phones (optional).** Install with `--phone-https local-ca`, or later run
`fieldset config set phone-https local-ca --yes`. Phones use the Windows PC's address in your Wi-Fi, never WSL's
internal one. Windows has to let them in: run `fieldset doctor`, and its `windows_network` check prints the commands to
run in PowerShell as administrator, such as a firewall rule for ports 80 and 443 limited to your local network. Fieldset
never changes Windows settings itself. If something blocks phones, see [phones on
Windows](troubleshooting.md#phones-cannot-reach-the-instance-on-windows).

**Where the data lives.** The database, the uploaded files and the certificates are Docker volumes: inside Docker
Desktop, or inside the distro with Docker Engine. The instance directory (`~/fieldset`: settings, secrets, backups) is
in the distro. `wsl --unregister <distro>` deletes the distro and the instance directory with its backups; resetting or
removing Docker Desktop deletes the volumes. Copy backups to Windows or another machine regularly ([backup and
restore](backup-restore.md#copy-backups-off-the-host)), for example
`cp -r ~/fieldset/backups /mnt/c/Users/<you>/fieldset-backups`.

**Switch between Docker Desktop and Docker Engine.** The volumes stay with the old Docker, so a switch is a move:
`fieldset uninstall --keep-backups` writes a final backup and prints the command that brings the instance back. Switch
Docker (turn Docker Desktop's WSL integration off for the distro before you install Docker Engine, or the other way
round), then run that command: the install one-liner with `--from-backup` and `--app-key-file`. See also [move to another
server](backup-restore.md#move-to-another-server) and, if pulls fail afterwards, [the Docker Desktop credential
helper](troubleshooting.md#pulls-fail-with-docker-credential-desktopexe).

**Reach it from outside your network.** The quick tunnel (`--phone-https tunnel` or `fieldset tunnel start`) gives a
temporary public address and needs no Windows firewall rule, since it connects outward.

**HTTPS on the PC itself.** With the local CA, the instance's URL becomes `https://<LAN address>`, and the browser on the
PC warns until Windows trusts the CA too. Open the trust page `http://<LAN address>/phone-trust` on the PC, download the
certificate, compare its SHA-256 with `phone_https.root_sha256` from `fieldset status`, and import it into the current
user's trusted root certificates: double-click it, choose "Install Certificate", "Current User", then "Trusted Root
Certification Authorities". Or in PowerShell:
`Import-Certificate -FilePath <file> -CertStoreLocation Cert:\CurrentUser\Root`.
Firefox keeps its own certificate list (Settings, Certificates, Import).

**The PC is the server.** The instance runs only while Windows is on and Docker runs. Docker Desktop starts after you
sign in to Windows (turn on its "Start Docker Desktop when you sign in" setting). While the PC sleeps or is off, phones
cannot sync: their work waits on the device. Scheduled backups run at 03:30 only if the PC is on then; pick another time
with `fieldset config set backup-schedule <HH:MM>`.

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
curl --proto '=https' --tlsv1.2 -fsSL https://github.com/beyerenrico/fieldset/releases/latest/download/install.sh | sh -s -- --dir /opt/fieldset --adopt /srv/form-schema-app
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
