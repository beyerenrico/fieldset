# 0069: Compose renderer, env split, runtime directory and resource budget
- Status: accepted; amended 2026-10-18 by ADR 0080 (capture flag, the kept catcher) and ADR 0082 (the tunnel
  service)
- Date: 2026-10-18

## Context
Plan unit U3 (PLAN M9-T04): `fsa` generates each instance's compose project from its state, and nobody edits
it (KTD6). Each service gets its own 0600 env file with only the keys it needs (KTD7). The runtime directory is
the only channel from ops to app (KTD11). Capture mail goes to a bundled Mailpit (KTD13, ADR 0054). No rendered
service holds the Docker socket (KTD25). Every service has a memory limit, and the limits fit E16 (ADR 0062).
Later units extend the renderer: edge (U8), proxy mode (U9), tunnel (U14) and backup (U16).

## Decision
**Format.** `compose.yaml` in the instance directory holds JSON behind a two-line YAML comment header. JSON is
valid YAML, and Compose 5 parses it (the `ComposeConfig` install journey runs real `docker compose config`).
`symfony/yaml` is not in `composer.lock`, and `json_encode` of a PHP structure leaves no room for injection. No
file is assembled from strings.

**Structure (`App\Domain\Instance\Compose`).**
- `ComposeRenderer::render(InstanceState, InstanceSecrets): RenderedProject` is pure.
- `write(InstanceDirectory, …)` writes `runtime/` first, then the env files, then `compose.yaml`. A missing
  runtime file would make the app fail closed. Env files of services that are no longer rendered are deleted.
- A `ServiceDefinition` returns `ServiceSpec`s, each with a service definition, its whole environment, and the
  volumes and networks it needs. The defaults are `AppRoles` (app, queue, scheduler), `PostgresService`,
  `ValkeyService` (service `redis`), `GotenbergService` and `MailpitService`.
- The renderer adds the same things to every service:
  - `env_file: ./env/<name>.env`;
  - the labels `app.form-schema.instance=<uuid>` and `app.form-schema.role=<service>`;
  - `mem_limit` from `ResourceBudget`. A service without a budget slot cannot be rendered;
  - logs capped by json-file at 10 MB × 3, so a small disk does not fill up;
  - `restart: unless-stopped`, unless the definition sets its own policy, as the tunnel's `"no"` will.
- Every volume and network, including `default`, carries the instance label.
- `ComposeGuard` rejects the following, exit 1, because each is a renderer bug:
  - any string with a control character or a `$` (nothing needs Compose interpolation);
  - a Docker socket mount;
  - `RUN_MIGRATIONS`, in an env file or inline;
  - a `com.docker.compose*` label.
- Extension points:
  - U8, U14 and U16 add a definition to `defaultDefinitions()`;
  - U9 branches on `RenderContext::mode()` inside `AppRoles::ports()` and `MailpitService`;
  - the unit that renders a mode lifts `ComposeRenderer::MODES`. Until then, domain and proxy exit 2 with
    `unsupported_mode`. (Superseded 2026-10-18: `MODES` is local, domain and proxy; domain mode by ADR 0075,
    proxy mode by ADR 0076.)
- `ONE_OFF_SERVICE = app`: one-offs run the app service, so they get its env file and its runtime mount.

**Env split (KTD7).** Each env file holds the service's whole environment, and the compose file holds none.
| service | secrets it receives |
|---|---|
| app, queue, scheduler (and one-offs) | APP_KEY, DB_USERNAME/DB_PASSWORD, REDIS_PASSWORD, SMTP credentials when configured |
| postgres | its own POSTGRES_USER/POSTGRES_PASSWORD |
| redis (Valkey) | its own password: `VALKEY_EXTRA_FLAGS=--requirepass …`, `REDISCLI_AUTH`, `VALKEYCLI_AUTH` |
| mailpit | its own `MP_UI_AUTH=fsa:<password>` |
| gotenberg, later the tunnel | none |
| edge (amended 2026-10-18, ADR 0075) | capture mode only: `FSA_CATCHER_AUTHORIZATION=Basic …`, Mailpit's credential, which the edge injects towards Mailpit; none with SMTP |
| backup (U16, defined by `ServiceEnvironments::backup()`, not rendered yet) | dump role `fsa_backup` and its password, `BACKUP_HMAC_KEY`, `BACKUP_AGE_RECIPIENTS` |

- `BACKUP_HMAC_KEY` is `base64:` + HKDF-SHA256(raw APP_KEY, 32, info `form-schema-app backup hmac v1`). Changing
  the info string breaks verification of every existing backup.
- `InstanceSecrets::generate()` makes the secrets with a CSPRNG: a Laravel APP_KEY and 48-hex passwords, which
  are safe unquoted anywhere.
- `fromEnvFiles()` reads them back on every render. It returns null when `app.env` lacks APP_KEY or the database
  password, because those are never regenerated silently. The Valkey, catcher and dump-role passwords are
  regenerated when their file is gone.

**Services.**
- **App roles.** Images come by digest from `state.digests` (`ImageRole`: app, postgres, valkey, gotenberg,
  mailpit, and later caddy and cloudflared). Healthchecks are those of `compose.yaml`. Networks are `default`
  plus the internal `renderer` for app and queue. FrankenPHP threads come from `FRANKENPHP_CONFIG=num_threads N`;
  the image Caddyfile now has `frankenphp { {$FRANKENPHP_CONFIG} }`, checked with `frankenphp adapt` both set
  and unset.
- **Horizon.** Process counts come from `HORIZON_MAX_PROCESSES` and `HORIZON_EXPORTS_PROCESSES` in
  `config/horizon.php`. The defaults are unchanged: production 10, local 3, exports 1.
- **Postgres** runs `postgres -c shared_buffers=… -c effective_cache_size=… -c maintenance_work_mem=…
  -c work_mem=… -c max_connections=…`, with `shm_size` at half its limit.
  - `pg_isready -h 127.0.0.1` asks over TCP. The entrypoint's temporary init server listens only on the socket,
    so dependants never start against it.
- **Valkey.**
  - The official entrypoint appends `$VALKEY_EXTRA_FLAGS` to the server arguments and drops to the `valkey`
    user. The password therefore stays out of the compose file. Valkey shows its process title, not its
    arguments.
  - valkey-cli 8.1 reads `REDISCLI_AUTH` and ignores `VALKEYCLI_AUTH`. It exits 0 on NOAUTH, so the health
    check greps for PONG.
  - `maxmemory` is ¾ of the limit with `volatile-lru`. Only keys with a TTL (cache entries) are evicted, never
    the queue lists.
  - Valkey data now lives on the labelled volume `valkey-data`.
  - All of the above was verified on `valkey/valkey:8-alpine`.
- **Gotenberg** keeps the `compose.yaml` flags. `CHROMIUM_MAX_CONCURRENCY` and `CHROMIUM_MAX_QUEUE_SIZE`
  (8 × concurrency) come from the budget.
- **Mailpit** is rendered only in capture mode, with:
  - `MP_MAX_MESSAGES` from the budget;
  - `MP_DATABASE=/data/mailpit.db` on `mailpit-data`;
  - `MP_DISABLE_VERSION_CHECK=true` (§10, no outbound calls).

  Its UI is published on `127.0.0.1:<mail_ui_port>` in local mode. The SMTP port 1025 is never published. With
  SMTP configured, no Mailpit is rendered, and the app roles get `MAIL_*` from settings and secrets.

**Resource budget (E16).** The host size minus OS headroom must hold the worst case: every slot, with edge and
tunnel counted once (R61). Larger sizes scale Horizon, Gotenberg and FrankenPHP.
| MiB | app | queue | scheduler | postgres | redis | gotenberg | mailpit | edge/tunnel | backup | worst case / available |
|---|---|---|---|---|---|---|---|---|---|---|
| small (2 GB, 320 headroom) | 384 | 320 | 128 | 256 | 80 | 320 | 48 | 64 | 128 | 1728 / 1728 |
| medium (4 GB, 512) | 704 | 704 | 192 | 640 | 160 | 640 | 64 | 96 | 192 | 3392 / 3584 |
| large (8 GB, 768) | 1536 | 1536 | 256 | 1536 | 384 | 1280 | 96 | 128 | 256 | 7008 / 7424 |

- Horizon processes are 2, 4 and 8, and exports stay at 1.
- FrankenPHP threads are 4, 8 and 16.
- Chromium concurrency is 1, 2 and 4.
- Postgres max_connections is 40, 60 and 100.
- MP_MAX_MESSAGES is 500, 1000 and 2000.

The size is the `size` setting, default `small`. These numbers are estimates until U15 measures them. (Superseded
2026-10-19: U15 measured `small`; the table and counts in effect are in the amendment at the end, ADR 0083.)

**Runtime directory (KTD11).** `RuntimeWriter` writes `runtime/runtime.json` atomically. The directory is 0755
and the file 0644, both set explicitly. The file holds:
- `schema_version` 1.0;
- `instance_url`;
- `local_url`;
- `extra_hosts`;
- `tunnel {hostname, expires_at}` (null until U14);
- `gate {raised, operation, since}`.

App roles mount the directory read-only at `/run/fsa-runtime`, and their env carries `FSA_RUNTIME_PATH`, which
U10 reads. A re-render keeps the current gate; only `raiseGate()` and `lowerGate()` change it. A related fix:
`SafeFiles::write()` now creates missing parents (0700) without resetting the mode of existing ones. Before, a
write into `runtime/` reset it to 0700.

**Renderer-input grammar.** `State\InputGrammar` covers:
- RFC 1123 host names or IPs, without wildcards or a trailing dot;
- http(s) URLs without userinfo, query or fragment, with a plain path and a valid port;
- ports 1–65535 as integers;
- slugs and lowercase identifiers;
- e-mail addresses;
- plain text: no quotes, `$`, backticks, backslashes or control characters, at most 200 characters;
- comma-separated host lists;
- image references pinned by digest. This tightens the `digests` check; before, any character except
  whitespace and `@` passed.

Each known setting has a rule; an unknown setting must be scalar plain text. The grammar is enforced in two
places:
- `InstanceState::withSetting`, the new `withSettings` and `withUrl` exit 2. Flags, `--from-backup`
  settings.json and adopted 1.0 `.env` values all pass through these.
- `StateValidator`, on every read of a state document, exits 3.

All end-anchored patterns in the instance domain now use the PCRE `D` modifier. Without it, `$` also matches
before a trailing newline, and `"host\n"` passed.

## Alternatives considered
- **symfony/yaml:** a new dependency for output that JSON already covers. Its emitter quotes correctly, but a
  dependency was not needed.
- **Non-secret values inline (`environment:`) and only secrets in env files:** state-derived text would then be
  interpolated by Compose. Env files are literal, so the compose file now carries no free text at all.
- **Valkey password via `sh -c "… $$VALKEY_PASSWORD"`:** it puts `$` into the compose file, and it skips the
  image's privilege drop unless the entrypoint is re-run.
- **One budget for every size:** E16 fixes only the floor. Bigger hosts would waste memory, or the floor would
  not fit.

## Consequences
- The budget is unmeasured until U15. A miss is recorded with numbers and fixed in `ResourceBudget::PROFILES`,
  never by dropping a control. (Amended 2026-10-19: `small` is measured, ADR 0083; see the amendment at the end.)
- Volume names are Compose's `<project>_<volume>`. Restore and adopt (U17, U19) swap fresh volumes in, so they
  will need explicit `name:` overrides from the state, through the volume map of a `ServiceSpec`.
- `docker compose config` prints `$` in env values as `$$`, so its output stays a valid compose file; containers
  get the literal value. It also prints env-file secrets (ADR 0066): never into command output.
- A Valkey image without `VALKEY_EXTRA_FLAGS` would start without a password. The app's AUTH would then fail
  loudly; it would not run open. The image is pinned by digest, and a manifest bump must keep this contract.
- U7's manifest must use the image role keys above.

## Amendment 2026-10-18: runtime fields for the app (U10, ADR 0074)
`runtime.json` now carries `tunnel {hostname, expires_at, peer, admin}` (peer and admin null/false until U14) and
`gate.smoke_token`. `raiseGate()` generates the token (64 hex) or validates a given one against the app's grammar
and returns it; `lowerGate()` clears it; a re-render keeps it with the gate. The app reads the directory from
`FSA_RUNTIME_PATH` through `config('instance.runtime_path')`. `SESSION_SECURE_COOKIE` stays in the app env only as
a default; the runtime middleware sets Secure and `__Host-` per request host.

## Amendment 2026-10-18: capture flag and the kept catcher (ADR 0080)
- In capture mode the app-role env (app, queue, scheduler and the one-offs) also carries `FSA_MAIL_CAPTURE=true`,
  which the app reads as `config('mail.capture')` for the banner and the health check; with SMTP it is absent.
- Reverses "**Mailpit** is rendered only in capture mode" and "With SMTP configured, no Mailpit is rendered": after
  `config set mail smtp` Mailpit stays rendered (setting `mail_catcher: kept`) with its volume and UI, and its env
  adds `MP_SMTP_BIND_ADDR=127.0.0.1:1025`, so nothing in the stack reaches its SMTP listener. It stops being rendered
  only with `--remove-catcher`, and its volume is then removed once the change runs. The app roles get `MAIL_*` from
  settings and secrets as before.

## Amendment 2026-10-18: the tunnel service (ADR 0082)
`TunnelService` joins `defaultDefinitions()` and renders while `phone_https` is `tunnel`: the pinned cloudflared
image with `restart: "no"` as foreseen above, no env, user 65532, read-only, every capability dropped, nothing
published, at a fixed address on its own network `tunnel` (a /28 from 10.215.0.0/16, setting `tunnel_subnet`), which
the app joins. Its memory limit is the `tunnel` slot of `ResourceBudget`, which the worst case counts once together
with the edge (R61), as the budget table above assumes.

## Amendment 2026-10-19: the measured budget (U15, ADR 0083)
U15 measured `small` with J10 (2 GB envelope, two pinned CPUs, scheduler running, production Horizon, the 1.0 test
volume): no OOM kill and no restart, each slot about 30 MiB or more above its measured peak. This replaces the budget
table under "Resource budget (E16)" and its "estimates until U15" sentence (`ResourceBudget::PROFILES`):

| MiB | app | queue | scheduler | postgres | redis | gotenberg | mailpit | edge/tunnel | backup | worst case / available |
|---|---|---|---|---|---|---|---|---|---|---|
| small (2 GB, 320 headroom) | 384 | 400 | 208 | 160 | 48 | 304 | 48 | 48 | 128 | 1728 / 1728 |
| medium (4 GB, 512) | 704 | 704 | 256 | 640 | 160 | 640 | 64 | 96 | 192 | 3456 / 3584 |
| large (8 GB, 768) | 1536 | 1536 | 256 | 1536 | 384 | 1280 | 96 | 128 | 256 | 7008 / 7424 |

- Horizon processes are 1, 4 and 8: `small` runs one default worker (two would need about 430 MiB for the queue);
  exports stay at 1. FrankenPHP threads, Chromium concurrency, Postgres max_connections and MP_MAX_MESSAGES are
  unchanged.
- Postgres `work_mem` is a sixty-fourth of the limit but never below Postgres' default of 4 MB (`small`: 4 MB, not 2;
  `medium` 10, `large` 24). `small` gets shared_buffers 40 MB, effective_cache_size 120 MB, maintenance_work_mem 20 MB.
- Measured is `small` only. `medium` and `large` stay estimates except their scheduler slot (`medium` 192 → 256), which
  carries the measured need; that does not grow with the size. Also unmeasured: the tunnel slot (cloudflared; J10 ran in
  domain mode) and the backup slot. U16 (M12) must fit the backup service into 128 MiB on `small`, or rebalance the
  table with a new J10 run.
- The Consequence "The budget is unmeasured until U15" now reads: `small` is measured (ADR 0083). The rule stays: a miss
  is recorded with numbers and fixed in `ResourceBudget::PROFILES`, never by dropping a control.

## Amendment 2026-10-19: Postgres settings beyond memory (ADR 0083 amendment)
Each `ResourceBudget` profile gains a `postgres` map of further server settings that `postgresSettings()` appends:
`small` sets `jit=off` and `max_parallel_workers_per_gather=0`, `medium` and `large` none. `small`'s `max_connections`
is 30 (was 40), sized from `postgresClients()` (17 on `small`). The postgres service still passes every setting as a
`-c name=value` argument, so the compose file (and the stack file) shows them.

## Amendment 2026-10-19: the backup service is rendered (U16, ADR 0084)
- `BackupService` joins `defaultDefinitions()` and renders in every mode. Its env (`ServiceEnvironments::backup()`) now
  holds, besides the dump role, `BACKUP_HMAC_KEY` and `BACKUP_AGE_RECIPIENTS`: `BACKUP_APP_FINGERPRINTS`,
  `BACKUP_SCHEDULE`, `BACKUP_TIME_ZONE`, `BACKUP_RETENTION`, `FSA_INSTANCE_ROOT` and the in-container owner
  `FSA_OWNER_UID`/`FSA_OWNER_GID`. The env-split row "backup (U16, …, not rendered yet)" is superseded by this list.
- `write()` also creates `backups/` and `locks/` (0700) before Compose could create the backup service's bind mounts as
  root, and writes `backups/.instance.json` (0600, the instance's identity and portable settings, no secret).
- The stack file (ADR 0076) renders without the backup service.
