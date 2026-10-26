# Troubleshooting

For an instance installed with `install.sh` and managed with `fieldset`. Start with the general checks, then find your
symptom below. The 1.0 guide for checkout installs is in the [archive](archive/1.0-troubleshooting.md).

## General checks

```sh
fieldset status            # health of every service, URL, mail, backups, an unfinished operation
fieldset doctor --post     # Docker, memory, disk, ports, DNS, clock, and every service healthy
fieldset logs app          # also queue, scheduler, postgres, redis, gotenberg, mailpit, backup, edge, tunnel
fieldset logs app --since 30m --tail 0
```

Every `fieldset` command exits with a documented code and, with `--json`, prints one JSON document; the
[agent guide](agent-guide.md#exit-codes-and-recovery) explains them. The most important rule: when a command stopped
half-way, `fieldset status` and every other command name the command that continues it (exit 9
`operation_unfinished`). Run exactly that command; do not repair by hand with `docker` commands.

## The install or a command fails before it starts

| Answer | Cause and fix |
|---|---|
| exit 10 `docker_missing`, `docker_unavailable` | Docker is not installed, not running, or you may not use its socket. Start Docker (or add your user to the `docker` group) and run the command again. In WSL, see [Docker is missing or not running in WSL](#docker-is-missing-or-not-running-in-wsl). |
| exit 10 `docker_too_old` | Docker Engine older than 24. Upgrade Docker. |
| exit 3 `doctor_failed` | `data.checks` (or the human output) names the failed check and its fix: `ports` (a port is in use: pick another `--port`, or stop what uses 80/443), `dns` (the domain does not point to this host yet), `disk`, `memory`, `clock`, `registry` (images cannot be pulled), `path_sharing`, `permissions` (see [ownership errors](#ownership-errors)), `windows_network` (see [phones on Windows](#phones-cannot-reach-the-instance-on-windows)). |
| exit 3 `wsl1_unsupported`, exit 2 `path_on_windows_drive` | On Windows: see [WSL 1 and Windows drives](#wsl-1-and-windows-drives). |
| exit 3 `owner_mapping_unknown`, `socket_no_group_access` | Docker maps user IDs in a way fieldset does not support, or its socket is closed to its group: see [ownership errors](#ownership-errors). |
| exit 3 `path_not_shared` | On Docker Desktop the instance directory is not shared with Docker. Use a directory under your home directory, or add its parent in Docker Desktop's file-sharing settings. |
| exit 3 `verify_failed`, `manifest_signature_invalid` | The release manifest did not verify. Do not work around it: download again; if it persists, report it. |
| exit 7 `project_conflict` | Another instance or a leftover uses the same Compose project. `fieldset uninstall --orphans <uuid>` removes the leftovers of an instance whose directory is gone. |
| exit 9 `instance_locked` | Another `fieldset` command or a backup runs. Wait. |

## The site answers 503

The maintenance gate is up: an upgrade, restore, rollback, reset or adopt is running or stopped half-way.
`fieldset status` shows `operation`. Let it finish, or run the command it names. After exit 8 (rollback failed) the
instance stays behind the gate on purpose: follow `errors[0].hint` of that result.

## No certificate in domain mode

`fieldset status` shows the URL, and `fieldset logs edge` shows Caddy's attempts to get a certificate. Common causes: the DNS
record does not point to this host (yet), port 80 or 443 is blocked by a firewall, or Let's Encrypt's rate limits
after many attempts (use `fieldset config set acme-ca staging` while testing). An instance moved with `--from-backup`
reports "certificate pending" until DNS points to it.

## Links point to the wrong host, or the dashboard looks broken behind a proxy

In proxy mode the app trusts forwarded headers only from exactly one address: the proxy container (`traefik`,
`caddy`) or the instance network's gateway (`port`). If the proxy was recreated with a new address, `fieldset status` and
`fieldset doctor` warn `proxy_address_changed`; `fieldset config set trusted-proxy auto` follows it. Give the proxy a fixed
address on the shared network to avoid it. The instance's URL is `fieldset config set url <origin>`.

## Mail is not delivered

A new instance captures mail: everything lands in the mail catcher and the admin dashboard shows a "mail is captured"
banner. That is intended until you configure SMTP: `fieldset config set mail smtp …` (see
[configuration](configuration.md#mail)). The catcher's address is in `fieldset status` (`mail.catcher_url`); its login
comes from `fieldset config show --catcher-login --secrets-file <file>`.

With SMTP configured, a mail that does not arrive shows its error in the organization's mail log ("E-Mail-Protokoll").
`fieldset config set mail smtp` with the same settings sends a new test mail. Exit 5 `test_mail_failed` quotes the
server's answer: wrong login, a sender the server does not allow (`--from-address`), or STARTTLS required where the
server offers none.

## PDFs are not created

PDFs are rendered by the `gotenberg` service after a submission, in the queue. Check `fieldset status` (gotenberg and queue
healthy) and `fieldset logs gotenberg` and `fieldset logs queue`. The `/platform` health page shows Gotenberg and the queue too.

## The queue or the scheduler does not run

`fieldset status` shows `queue` and `scheduler`. Both stop on purpose while an operation holds the maintenance gate. If
they stopped otherwise, `fieldset logs queue` or `fieldset logs scheduler` says why; `fieldset doctor --post` fails while a service
is unhealthy. Backups need the `backup` service; `fieldset status` warns when it does not run.

## Windows (WSL 2)

Fieldset runs in a WSL 2 distro ([install guide](install.md#windows-wsl-2)). `fieldset doctor` checks the Windows side
too; each problem below names the doctor's code. Commands for PowerShell come from the doctor with your instance's
values filled in; the ones here show the form, with `<id>` for the first 8 characters of the instance ID.

### Ownership errors

**2.1.0: "The instance directory belongs to UID 1000, not the instance owner (UID 0 inside containers)".** Fieldset
2.1.0 guessed the owner from the product name and got it wrong for Docker Desktop in WSL 2, where containers see your
files with your own user ID. The chmod and chown in its hint do not help: the files already belong to you. Fix: run the
2.1.1 install command again with the same `--dir`. It measures the owner, gives back what the failed install left to
root, and completes the install.

**Doctor's `permissions` check fails with "the instance was set up for UID … but containers now see your files as UID
…".** Docker maps user IDs differently than when the instance was set up: rootful Docker became rootless, or the
instance moved to WSL 2 or another host. Run `fieldset repair-ownership`: it asks first, gives the instance files to the
measured owner (owners only: modes, symlinks and Docker volumes stay as they are), saves that owner and recreates the
backup service. Then run `fieldset doctor` again.

| Code | Meaning and fix |
|---|---|
| warning `owner_not_measured` (doctor status `unknown`) | The short-lived container that measures the owner could not run, so fieldset used its rules. Not a mismatch. Run `fieldset doctor` again once Docker can start containers. |
| exit 3 `owner_not_measured` | `fieldset repair-ownership` needs a measurement from this run. Run it through the instance's own `fieldset` script once Docker can start containers. |
| exit 4 `owner_repair_failed` | Giving the files to the new owner failed; the saved owner is unchanged. Fix the cause in the message, then run `fieldset repair-ownership` again. |
| exit 4 `secrets_exposed` | An env file is no longer mode 0600 after the change. Run the `chmod 600` the hint names, then `fieldset repair-ownership` again. |
| exit 3 `owner_mapping_unknown` | Containers see your files as a third user ID (Docker with user-namespace remapping). Use Docker Desktop, rootless Docker, or Docker Engine without remapping. |
| exit 3 `socket_no_group_access` | The Docker socket grants its group no write access inside containers, so fieldset cannot reach Docker as your user. Docker Engine: the `docker` group and mode 660 on the socket. Docker Desktop: restart it. |

### Docker is missing or not running in WSL

| Answer | Cause and fix |
|---|---|
| exit 10 `docker_missing`, "Docker is not available in this WSL distro" | Docker Desktop's WSL integration is off for this distro (new versions leave it off). Turn it on in Docker Desktop: Settings > Resources > WSL integration. Or install Docker Engine in the distro. |
| exit 10 `docker_unavailable`, "Start Docker Desktop on Windows" | Docker Desktop is not running. Start it on Windows and wait until it says it is running. Docker Engine in the distro: `sudo service docker start`. |

### WSL 1 and Windows drives

- **exit 3 `wsl1_unsupported`.** The distro runs on WSL 1, where Docker cannot run. In PowerShell: `wsl -l -v` lists the
  distros, `wsl --set-version <distro> 2` converts one. Then run the command again.
- **exit 2 `path_on_windows_drive`.** The instance directory, or a link on its path, is on a Windows drive (`/mnt/c/…`),
  where Linux owners and permissions do not work. Install into the distro's Linux file system: leave out `--dir` (the
  default is `~/fieldset`) or pass `--dir ~/fieldset`. Backups and files you pass with flags may stay on a Windows drive.

### Interop is off

WSL interop lets the distro start Windows programs. Without it (`[interop] enabled=false` in `/etc/wsl.conf`, or a
broken interop setup), fieldset cannot ask Windows anything:

- no browser opens: the sign-in link is printed in the terminal (or kept in a file only you can read, named in the
  warning);
- phone facts are unknown: doctor's `windows_network` check reports `unknown` (`windows_facts_unknown`) with manual
  steps, and phones get no Windows address by detection. Set it yourself:
  `fieldset config set lan-address <Windows LAN IPv4> --yes` (`ipconfig` in PowerShell shows it);
- with Docker Engine, `wsl_lifecycle` reports `unknown`: it cannot read `.wslconfig`.

Turn interop back on (remove the setting, then `wsl --shutdown` in PowerShell) for the automatic checks.

### Pulls fail with docker-credential-desktop.exe

Docker Desktop's WSL integration writes `"credsStore": "desktop.exe"` into `~/.docker/config.json` in the distro. When
interop is off, or after you switched to Docker Engine, the Docker CLI cannot start that helper and pulls fail with
"error getting credentials" or "docker-credential-desktop.exe: executable file not found". Remove the `credsStore`
line from `~/.docker/config.json` (keep the rest of the file), then run the command again. With Docker Desktop and
interop working, leave it.

### Clock skew after sleep

After Windows sleeps or hibernates, the clock in WSL 2 can lag behind Windows. Doctor's `clock` check warns above 30
seconds and fails above 5 minutes, and certificate checks (downloads, the release signature) can fail too. Run
`wsl --shutdown` in PowerShell, open the distro again, then run the command again.

### Phones cannot reach the instance on Windows

With the local CA on, doctor's `windows_network` check looks at what Windows does with phone traffic and prints one set
of commands for the first problem it finds, under "Run in PowerShell as administrator:" (in `--json`:
`details.commands`). Run them, then `fieldset doctor` again. Fieldset never runs them. Install only warns about these,
so the instance installs and its result carries the commands.

#### The network is Public

Windows blocks incoming connections on networks it treats as Public. Set the network's profile to Private (Windows
Settings > Network & internet > your network > Network profile type), or run the printed
`Set-NetConnectionProfile … -NetworkCategory Private`.

#### Docker Desktop publishes ports on localhost only

Docker Desktop's port binding setting is local-only, so nothing outside the PC reaches the edge. Change the port
binding behavior in Docker Desktop's settings back to the default, then run `fieldset doctor` again.

#### No firewall rule for ports 80 and 443

The Windows firewall has no inbound rule for TCP 80 and 443 on the Private profile. The printed rule allows them from
your local network only:
`New-NetFirewallRule -Name fieldset-<id>-https -DisplayName fieldset-<id>-https -Direction Inbound -Action Allow -Protocol TCP -LocalPort 80,443 -Profile Private -RemoteAddress LocalSubnet`.

#### A portproxy is missing or stale (Docker Engine)

With Docker Engine behind WSL's default NAT networking, Windows must forward ports 80 and 443 from its LAN address to
the distro with a portproxy. WSL's internal address changes after `wsl --shutdown` or a Windows restart, which leaves
the portproxy pointing at the old one. Doctor then prints `netsh interface portproxy delete …` lines for the stale
entries, an add line per port, and the firewall rule when it is missing:
`netsh interface portproxy add v4tov4 listenaddress=<LAN address> listenport=443 connectaddress=<WSL address> connectport=443`.
Run `fieldset doctor` after every restart of WSL. Docker Desktop, or WSL's mirrored networking, avoids this.

#### No Hyper-V firewall rule (mirrored networking)

With WSL's mirrored networking, the Hyper-V firewall filters traffic to WSL. The printed rule lets TCP 80 and 443 in:
`New-NetFirewallHyperVRule -Name fieldset-<id>-https-wsl -DisplayName fieldset-<id>-https-wsl -Direction Inbound -VMCreatorId '{40E0AC32-46A5-438A-A0B2-2B479E8F2E90}' -Protocol TCP -LocalPorts 80,443`.

#### Port 80 or 443 is in use on Windows

Another program listens on 80 or 443 on Windows, often IIS or another service of the Windows HTTP server (PID 4).
Doctor fails and names it. Stop IIS (`Stop-Service W3SVC` in PowerShell as administrator) or the program with that PID
(Task Manager, Details), then run `fieldset doctor` again.

#### Windows' LAN address changed (`lan_address_changed`)

The certificate is for an address Windows no longer has, for example after the PC joined another network. Re-issue it
for the current address, which the warning names: `fieldset config set lan-address <address> --yes`. Phones then open
the new address and trust the same CA.

#### Windows facts unknown (`windows_facts_unknown`)

Fieldset could not ask Windows ([interop is off](#interop-is-off)), or Windows did not report something the check needs
(`details.unknown` lists it). The check reports `unknown`, never a pass. Check by hand that the network is Private, that
the firewall allows inbound TCP 80 and 443 from the local network, that nothing else uses those ports and, for Docker
Engine, that a portproxy or a Hyper-V rule forwards them. Doctor prints the commands it can build.

### The instance stops with the distro

Doctor's `wsl_lifecycle` check (WSL 2 only):

- **Docker Engine, warning "The distro stops when its last terminal closes".** WSL stops a distro without a terminal
  after its idle timeout, and Docker Engine and the instance stop with it. Add `instanceIdleTimeout=-1` under
  `[general]` to `%UserProfile%\.wslconfig` on Windows.
- **Docker Engine, warning "Docker Engine does not start with the distro".** systemd is not running. Add
  `systemd=true` under `[boot]` to `/etc/wsl.conf` in the distro.
- After either change, run `wsl --shutdown` in PowerShell and open the distro again.
- **`unknown` (`wsl_lifecycle_unknown`).** The check could not read `.wslconfig` (interop off) or did not learn whether
  systemd runs. Check both settings by hand.
- **Docker Desktop** passes as information: it starts only after you sign in to Windows. Whether WSL stopping an idle
  distro affects the instance is not verified yet; if the app stops loading after you closed every WSL terminal, open
  the distro again.

### Windows rules after uninstall

`fieldset uninstall` on WSL with the local CA warns `windows_rules` and prints the commands that remove what the doctor
suggested for this instance (in `--json`: `data.windows_cleanup`), for example
`Remove-NetFirewallRule -Name fieldset-<id>-https` and, for Docker Engine,
`netsh interface portproxy delete v4tov4 listenaddress=<LAN address> listenport=443`. Run them in PowerShell as administrator. A rule or portproxy you never added is reported as not found;
nothing else changes.

## Field App

**Work stays "Wartet auf Synchronisierung" ("Waiting to sync").** The device tries every minute while the app is
open, on reconnect and on "Jetzt synchronisieren" ("Sync now"), with longer pauses (up to 15 minutes) after errors.
Ask the worker what the header shows:

| Header or message | Cause | What to do |
|---|---|---|
| "Erneut anmelden, um zu synchronisieren" | The session expired, or the device check failed (also when the device was rate-limited). Automatic syncing pauses. | Sign in again in the app; the data stays on the device. |
| "Brauchen Ihre Entscheidung: N" | The server refused something, for example a retired form version, revoked form access, or a draft over the 2 MB limit. | The worker opens the item and follows the instructions ([Field App guide](field-app-guide.md#when-the-app-needs-your-decision)). |
| "Nicht gespeichert – der Speicher dieses Geräts ist voll." | The device is out of storage. | Free space; sync; install the app. |
| Nothing, the app is just offline | No connection. On iPhone and iPad the app only syncs while it is open. | Open the app once online. |

What an administrator can check: whether the submission already arrived in "Einreichungen"; server errors or rate
limiting in `fieldset logs app`; the queue, because the PDF and the customer email follow the sync. Do not use "Geräte
sperren" ("Revoke devices") to fix a stuck device: the device then deletes all local data, including unsent work.

**The app does not offer to install, or offline mode does not work.** The app must be opened over HTTPS: a domain
instance, `fieldset config set phone-https local-ca` (the phone must trust the instance's CA first, see its trust page),
or `fieldset tunnel start`. On iPhone and iPad, install from Safari's share menu.

**The phone does not reach the local instance.** The phone must be in the same network as the computer, and the
computer's firewall must allow the HTTPS port. `fieldset status` shows `phone_https.lan_address`; if the computer's
address changed, `fieldset config set lan-address <IPv4>`. On Windows, see [phones on
Windows](#phones-cannot-reach-the-instance-on-windows).

**"Die Formulare sind offline noch nicht verfügbar."** The device has not downloaded the forms yet; connect once.

## Single sign-on

**Administrators get "Single Sign-on erfordert Ihre Aufmerksamkeit: `<provider>`"** ("Single sign-on needs your
attention"). The hourly provider check or the daily domain check failed; the SSO page shows the status "Fehler"
("Error") and the last error, for example: the discovery document, the signing keys or the SAML metadata could not be
loaded (check the URL and that the server reaches the provider over HTTPS); issuer, tenant, audience or signature
mismatches; `invalid_client` (the client secret is wrong or expired); a domain lost its verification; the break-glass
Owner is no longer valid. The same problem is reported at most every 6 hours; the next successful check sets the status
back to "Aktiv".

**Users see the password step instead of the identity provider.** The email domain is not verified, or the provider is
not active yet (run "Anmeldung testen", "Test sign-in").

**Domain verification fails.** "Kein TXT-Eintrag gefunden" ("No TXT record found"): the record is missing, not yet
visible (DNS caching), or was created as `_fieldset-verification.example.com.example.com` because the DNS
provider appended the domain; then enter only `_fieldset-verification` as the name.

**The provider reports an invalid redirect URI.** Register exactly the redirect URI shown on the SSO page
(`https://<your server>/auth/sso/<id>/callback`). It follows the instance's URL: after
`fieldset config set domain` or `url`, register the new one.

**Users come back with an error.** The login screen explains it; the codes are `sso_failed`, `sso_denied`,
`account_inactive`, `not_member`, `domain_not_verified`, `email_unverified` and `provider_unavailable`.

**"Ihre Organisation schreibt die zentrale Anmeldung vor."** ("Your organization requires single sign-on.") Only the
break-glass Owner can still use a password; if the identity provider is down, they sign in with password and
two-factor code and can turn enforcement off.

## Login throttling and lockouts

| Where | Limit |
|---|---|
| Field App password login | 5 attempts per minute per email address and IP |
| Field App two-factor code | 5 per minute |
| Admin login, password step | 5 per minute per IP |
| Admin login, email step | 10 per minute per IP |
| Password reset | one email per user per minute |
| API | 240 requests per minute per integration token, otherwise per user |
| `/platform` login | 5 attempts per minute per IP |
| `fieldset login-link` | exit 9 `rate_limited`: wait a minute |

The limits reset after a minute; waiting is the fix. Limits per IP need the real client IP: in proxy mode, see the
proxy section above. Quick-tunnel visitors all share the tunnel's address.

**Lost two-factor device.** Sign in with a recovery code, then set up two-factor authentication again.

**Forgotten password, or the owner cannot sign in.** "Passwort vergessen?" on `/admin/login` (only once mail is
delivered), or on the server: `fieldset login-link <email>` issues a one-time sign-in link for an admin.

## Audit log

**`activity_log is append-only` in the logs.** Something tried to change or delete audit entries, and the database
refused it. That is the protection working.

**The chain heads changed after a restore, rollback or reset.** Expected: these start a new chain epoch and record
the old heads in `backups/ledger.log` and in an audit entry; see [chain epochs](backup-restore.md#chain-epochs-and-the-ledger).
