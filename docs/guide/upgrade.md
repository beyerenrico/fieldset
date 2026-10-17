# Upgrade guide

This guide upgrades a Docker Compose installation (see the [install guide](install.md)) from 0.1.x or
0.2.x to 1.0. The same procedure applies to later releases; always read the release notes of every
version you skip.

Contents:
- [Before you start](#before-you-start)
- [Upgrade steps](#upgrade-steps)
- [What changes between versions](#what-changes-between-versions)
- [The Field App update](#the-field-app-update)
- [After the upgrade](#after-the-upgrade)
- [Rollback](#rollback)

## Before you start

1. **Read the release notes** in `docs/releases/` and `CHANGELOG.md` for every version between yours
   and the target. Find your current version with `git describe --tags`.
2. **Back up and test the backup.** The upgrade changes the database schema; the backup is your way
   back.

   ```bash
   scripts/backup.sh --dest /srv/backups/form-schema-app
   scripts/restore-drill.sh --backup /srv/backups/form-schema-app/<newest> --scratch-db restore_drill_preupgrade
   ```

3. **Check that the system is healthy** before you change it, so you do not mistake old problems for
   upgrade problems:

   ```bash
   docker compose ps
   docker compose exec queue php artisan horizon:status
   docker compose exec app php artisan queue:failed
   docker compose exec app php artisan migrate:status
   ```

4. **Compare your `.env` with the new `.env.example`** (step 2 below lists the new settings for 1.0).
5. **Plan a short downtime.** The dashboard, the Field App sync and the API are unavailable while the
   `app` container restarts and runs the migrations, usually a minute or two. Field workers can keep
   working offline; their devices send everything afterwards. Large installations should allow longer:
   0.2 builds report indexes concurrently.
6. **Make sure there is disk space** for the new image and the backup.

## Upgrade steps

```bash
cd /srv/form-schema-app

# 1. Get the new release.
git fetch --tags
git checkout v1.0.0

# 2. Add new settings to .env (see "What changes between versions").
git diff v0.2.0 v1.0.0 -- .env.example

# 3. Build the new image. There is no registry image to pull: the image is built from the checkout.
docker compose build

# 4. Recreate the containers with the new image.
docker compose up -d --wait
```

Step 4 recreates all three application containers:
- `app` runs `php artisan migrate --force` on start (`RUN_MIGRATIONS=true` in `compose.yaml`) and
  caches the new configuration. You can also run the migrations yourself first:
  `docker compose run --rm --no-deps app php artisan migrate --force`.
- `queue` starts Horizon with the new configuration, including new supervisors such as
  `supervisor-exports` (added in 0.2). Recreating the container is the Horizon restart. If you run
  Horizon outside Docker, run `php artisan horizon:terminate` and let your process manager start it
  again.
- `scheduler` picks up new scheduled tasks.

The web server configuration (`docker/app/Caddyfile`) is part of the image, so the rebuild applies its
changes. If you replaced it with your own file, merge the changes listed below by hand.

## What changes between versions

### 0.1 → 0.2

From the [0.2.0 release notes](../releases/0.2.0.md):
- Migrations add report and dashboard indexes with `CREATE INDEX CONCURRENTLY` (outside a
  transaction), plus an optional `pg_trgm` index for the explorer search. If PostgreSQL refuses the
  extension, the migration logs it and continues without the index.
- Horizon gets the `supervisor-exports` supervisor for the `exports` queue (one process, so large
  exports run one after another). Without a Horizon restart, exports stay pending.
- The Caddyfile routes `/app/index.html` and `/form-preview/preview.html` through Laravel, so the
  Content Security Policy always applies. Rebuild the image.
- The Field App moves its local data into a new IndexedDB database on first start; devices stay
  registered.
- Revoking a device now also ends that user's other sessions.
- New scheduled tasks: scheduled report emails (hourly), the export sweep (every 10 minutes) and the
  daily clean-up of expired exports. The `scheduler` service must run.

### 0.2 → 1.0

Single sign-on and the canvas builder (milestone M7):
- Migrations create the SSO tables. No data migration is needed for the canvas builder: it edits the
  same form drafts as the classic builder.
- New settings in `.env.example`; the defaults are the safe production values:

  ```dotenv
  SSO_ALLOW_INSECURE_IDP=false
  SSO_DNS_DRIVER=dns
  SSO_DNS_RECORDS=
  SSO_SAML_SP_CERTIFICATE=
  SSO_SAML_SP_PRIVATE_KEY=
  ```

  With `APP_ENV=production` the application **refuses to boot** if `SSO_ALLOW_INSECURE_IDP=true` or
  `SSO_DNS_DRIVER=config`, the development values from `compose.dev.yaml`. If your production `.env`
  still lists `compose.dev.yaml` in `COMPOSE_FILE`, change it to `COMPOSE_FILE=compose.yaml` before
  upgrading.
- The SAML key pair (`SSO_SAML_SP_CERTIFICATE`, `SSO_SAML_SP_PRIVATE_KEY`, PEM) is optional. Without it
  the application sends unsigned SAML requests and its metadata has no certificate; identity providers
  that require signed requests need it.
- Login is now email-first in the admin dashboard and the Field App: the address decides whether the
  user goes to the organization's identity provider or to the password step. Organizations that enforce
  SSO lose password login and password reset for their domains (except the break-glass Owner).
- New scheduled tasks: `sso:check-providers` (hourly) and `sso:recheck-domains` (daily).
- Outbound connections: the `app` and `scheduler` containers now call your organizations' identity
  providers (discovery documents, keys, tokens, SAML metadata) over HTTPS and look up DNS TXT records.
  Allow this in your firewall if outbound traffic is restricted (webhooks from `queue` already needed
  it).

> **TODO-VERIFY (M8):** add the 1.0 hardening changes once they land: the audit log screen, its
> retention job and the database trigger that blocks UPDATE/DELETE on audit entries (M8-T01); per-form
> retention, data export and erasure (M8-T02); security headers, HSTS, secure cookies, trusted proxies
> and the opaque preview origin (M8-T03); the `/platform` operator panel (M8-T06); any new environment
> variables, scheduled tasks or Horizon queues from these tasks, and the 1.0 release notes link.

For integrators: the API stays `/api/v1`. 0.2 added `client_submitted_at` on submit and
`retired_version_ids` in the sync manifest; see [API and integrations](api.md).

## The Field App update

The Field App is a web app with a service worker. It does not update in the middle of someone's work:
1. After the upgrade, each device finds the new version the next time the app checks, for example when it
   is opened.
2. The app shows "Eine neue Version der App ist verfügbar." ("A new version of the app is available.").
3. "Jetzt aktualisieren" ("Update now") saves open drafts on the device, activates the new version and
   reloads. "Später" ("Later") keeps the old version until the next prompt.

Unsent work stays in the device's outbox during the update. If the app is open in several tabs, the
other tabs ask to reload ("Neu laden"). Tell field workers to accept the prompt; nothing else is needed,
and devices stay registered.

## After the upgrade

```bash
docker compose ps                                   # app and queue healthy
docker compose exec app php artisan migrate:status  # every migration "Ran"
docker compose exec queue php artisan horizon:status
docker compose exec app php artisan schedule:list   # includes the new tasks
docker compose logs --since 15m app queue scheduler
```

Then check in the dashboard: sign in, open a submission and its PDF, send a test email from
"E-Mail-Einstellungen", and open the Field App on a phone (accept the update prompt). In the Horizon
dashboard (`/horizon`, for `PLATFORM_ADMIN_EMAILS`) both supervisors should be running. Run a restore
drill on the first backup after the upgrade.

## Rollback

**If the upgrade fails before or during the migrations:** read the error in `docker compose logs app`.
Most migration problems can be fixed and the migrations run again
(`docker compose run --rm --no-deps app php artisan migrate --force`). An interrupted
`CREATE INDEX CONCURRENTLY` leaves an invalid index that a rerun skips; find and drop it, then migrate
again:

```bash
docker compose exec postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT indexrelid::regclass FROM pg_index WHERE NOT indisvalid"'
```

**To go back to the previous release:** do not check out the old code against the migrated database.
The supported way back is the pre-upgrade backup:
1. `git checkout <previous tag>` and `docker compose build`.
2. Restore the backup taken before the upgrade, as described in
   [Full restore](backup-restore.md#full-restore) (stop the application, recreate the database,
   restore database and storage, start).
3. Data created after the upgrade is lost, except for work still in the Field App outboxes, which the
   devices send again.

The Field App follows the server: after a rollback, devices should be offered the previous app version
through the same update prompt, because the service worker file changes again.
