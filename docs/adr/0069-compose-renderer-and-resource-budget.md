# 0069: Compose renderer, env split, runtime directory and resource budget
- Status: accepted
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
    `unsupported_mode`.
- `ONE_OFF_SERVICE = app`: one-offs run the app service, so they get its env file and its runtime mount.

**Env split (KTD7).** Each env file holds the service's whole environment, and the compose file holds none.
| service | secrets it receives |
|---|---|
| app, queue, scheduler (and one-offs) | APP_KEY, DB_USERNAME/DB_PASSWORD, REDIS_PASSWORD, SMTP credentials when configured |
| postgres | its own POSTGRES_USER/POSTGRES_PASSWORD |
| redis (Valkey) | its own password: `VALKEY_EXTRA_FLAGS=--requirepass …`, `REDISCLI_AUTH`, `VALKEYCLI_AUTH` |
| mailpit | its own `MP_UI_AUTH=fsa:<password>` |
| gotenberg, later edge and tunnel | none |
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

The size is the `size` setting, default `small`. These numbers are estimates until U15 measures them.

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
  never by dropping a control.
- Volume names are Compose's `<project>_<volume>`. Restore and adopt (U17, U19) swap fresh volumes in, so they
  will need explicit `name:` overrides from the state, through the volume map of a `ServiceSpec`.
- `docker compose config` prints `$` in env values as `$$`, so its output stays a valid compose file; containers
  get the literal value. It also prints env-file secrets (ADR 0066): never into command output.
- A Valkey image without `VALKEY_EXTRA_FLAGS` would start without a password. The app's AUTH would then fail
  loudly; it would not run open. The image is pinned by digest, and a manifest bump must keep this contract.
- U7's manifest must use the image role keys above.
