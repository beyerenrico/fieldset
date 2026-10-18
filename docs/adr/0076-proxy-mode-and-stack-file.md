# 0076: Proxy mode and the stack file
- Status: accepted
- Date: 2026-10-18

## Context
Plan unit U9 (PLAN M10-T03): R17, R18, J3, ADR 0053 (modes), KTD6/KTD7 (one generated compose file, env split),
KTD10 (the app trusts exactly one proxy address). An existing Docker host with Traefik or caddy-docker-proxy, or a
host proxy in front of a loopback port, should run an instance without the edge. Portainer and Coolify users want
one compose file without `fsa`. §7 names only `--behind-proxy traefik|caddy|port` and `--url`; it has no flags for
the shared network or the proxy's identity, so this ADR names them.

Facts this relies on (checked 2026-10-18 on Docker Desktop 29.9 / Compose 5.6, Traefik v3.7.15 per ADR 0064):
- Connections to a port published on `127.0.0.1` reach the container from the gateway of the network the port is
  published on (a test container saw `172.23.0.1`, the gateway of its network; the internal second network has
  no gateway). The same holds on Linux with docker-proxy, and with iptables DNAT the source is masqueraded to the
  bridge address (inferred, not tested here).
- Any container may join a user-defined network with an alias of its choice; Docker's DNS then answers that name
  with several addresses. A proxy's DNS name on a shared network therefore identifies nothing.
- `docker network inspect` lists every attached container with its address; container names are unique per engine.
- Traefik (`forwardedHeaders.insecure` false, the default) drops X-Forwarded-* from clients it does not trust and
  sets its own (J3 shows the app recording the real client, not a forged value).

## Decision
**Flags.** `fsa install --behind-proxy traefik|caddy|port --url <origin>`; traefik and caddy also take
`--proxy-network <name>` (required) and `--trusted-proxy <container name, ID or address on that network>`
(optional). `--url` is an http(s) origin without path, query or credentials (routing under a prefix is unsupported,
ADR 0074), normalized to lower case without a trailing slash. Exit 2 for: proxy with `--domain` (R61), `--url`
without proxy mode, the network flags without traefik/caddy, `--port` on a shared network (`invalid_combination`),
and a missing `--url`/`--proxy-network` (`input_required`). They are preflight flags: a resume refuses changing
them after the doctor phase (`flag_already_applied`), a repeat on a finished instance compares them like the
others (`changed: false` or `use_config_set`).

**Which address is trusted, and how it is found (KTD10).** Exactly one address goes into `TRUSTED_PROXIES` of the
app roles (setting `trusted_proxies`; the grammar accepts single IPs only, never a range, so a CIDR covering the
shared network cannot be configured).
- traefik and caddy: the doctor phase reads the shared network from the engine (`DockerGateway::inspectNetwork`)
  and takes the container named by `--trusted-proxy` (name, unique ID prefix of 12+ characters, or the address it
  holds there), else the only container on that network running a `traefik` or `caddy-docker-proxy` image (last
  path segment of `Config.Image`). Zero or several candidates, a missing network or a named container that is not
  attached exit 3 (`proxy_not_found`, `proxy_ambiguous`, `proxy_network_missing`, `trusted_proxy_not_found`) with
  nothing changed. The state keeps `proxy_container` too, so a later `fsa doctor`/`config set` can follow a
  recreated proxy. The install result shows `data.proxy {setup, network, container, trusted}`.
- port: the gateway of `<project>_default`, read in the `up` phase right after Compose created the network and
  before the app roles start (the env files are rendered again). A missing gateway is exit 4 `gateway_unknown`,
  resumable.
- Justification: resolving through the engine at install time needs no extra input in the common case (one
  Traefik on its network), never trusts a DNS name a neighbour could claim, and names what it trusts. Requiring an
  IP flag alone would push the lookup onto every operator and still go stale the same way. A proxy that gets a
  new address (recreated without a fixed `ipv4_address`) fails safe: forwarded headers are ignored, links and HSTS
  still follow the https instance URL, but client addresses collapse to the proxy's. Operators who recreate the
  proxy should give it a fixed address on the shared network; `fsa config set` (U11) re-resolves it.

**Renderer.** Mode `proxy` joins `ComposeRenderer::MODES`; the branches stay inside the existing extension points.
- Only the app joins the shared network, as compose key `proxy` with `external: true` and the real name; the
  renderer neither labels nor creates external networks, and uninstall (by label) never removes them. Postgres,
  Valkey, Gotenberg, Mailpit, queue and scheduler stay on the instance's own networks.
- Traefik labels (`ProxyLabels`): `traefik.enable`, `traefik.docker.network`, a router named after the compose
  project with `Host()` from `--url`, `tls=true` for an https URL (certificates from the entry point's resolver or
  Traefik's default: a per-router `certresolver` is not set), service port 8080. caddy-docker-proxy labels: the URL
  origin as site, `reverse_proxy {{upstreams 8080}}`, and `caddy_ingress_network` (from caddy-docker-proxy's
  documentation, unverified here: no caddy-docker-proxy journey).
- port setup: the app publishes `127.0.0.1:<port>` as in local mode. The catcher UI is on a loopback port in every
  proxy setup; its SMTP port is never published. No edge.
- The runtime file carries the `--url` instance URL and no local URL (ADR 0074); `fsa status` and the install
  result report `port: null` and `fsa doctor` probes no app port where none is published.

**Stack file (R18).** `App\Domain\Instance\Compose\StackFile` renders a proxy-mode (`port`) state with the release
digests through `ComposeRenderer` and makes it env-only:
- secrets are rendered as placeholders and replaced by `${VAR:?…}` wherever they occur (APP_KEY, DB_PASSWORD also
  for Postgres, REDIS_PASSWORD also inside `VALKEY_EXTRA_FLAGS`, MAILPIT_UI_PASSWORD inside `MP_UI_AUTH`); APP_URL is
  required; mail settings keep the rendered value as default (`${MAIL_HOST:-mailpit}` …); TRUSTED_PROXIES,
  TRUSTED_HOSTS, MAIL_SCHEME/USERNAME/PASSWORD default to empty; APP_PORT and MAILPIT_UI_PORT move the loopback
  ports;
- images stay pinned by digest with an overridable registry, `${FSA_REGISTRY:-<registry>}/<repository>@<digest>`
  (E4, like install's mirror handling);
- no env files, no runtime mount and no `FSA_RUNTIME_PATH` (the app then uses APP_URL, TRUSTED_HOSTS and
  TRUSTED_PROXIES, config/instance.php), no instance labels, no project name;
- without a supervisor the app migrates on start (`RUN_MIGRATIONS=true`, app only, healthcheck `start_period` 300 s)
  and queue and scheduler wait for it to be healthy. This is the one generated file that sets RUN_MIGRATIONS; the
  instance compose file still never does (KTD6, ComposeGuard);
- a guard refuses any `$` that is not one of these interpolations, leftover placeholders, the socket and env files.
- It is block YAML (people edit it in Portainer and Coolify) written by `YamlEmitter`: every scalar is a JSON string
  literal, which is a valid YAML double-quoted scalar, so no value can change the structure (ADR 0069's reason for
  JSON). The header documents every variable and what is missing without fsa (no fsa commands, no upgrade
  supervisor or rollback, no backups through fsa, no runtime state, gate, tunnel, local CA or edge, fixed 2 GB
  limits).

**Generation path.** The app digest exists only after the image build, so the stack file cannot be a committed
`distribution/stack/compose.stack.yaml` (deviation from the plan's file list). `release:manifest --stack-file=<path>`
renders it from the same pins as the manifest (`ReleaseManifestBuilder`) and lists it as an asset, so its checksum
is in the signed manifest. The release workflow's manifest job writes `dist/compose.stack.yaml`; the sign job (no
PHP) no longer compares it with a tagged file but checks that it is listed and that every `image:` line is a
manifest pin in the `${FSA_REGISTRY:-…}` form, and that it mounts no socket. The local harness generates it the same
way. The manifest schema does not require the asset, because manifests up to 2.0.0-alpha.1 lack it and readers must
accept them.

**Harness and J3.** `compose.harness.yaml` gets Traefik v3.7.15 (pinned by digest, ADR 0064) behind the `proxy`
profile on an external `fsa-test-proxy-<id>` network that `ReleaseHarness::startProxy()` creates and `stop()`
removes. J3 installs behind it, requests the configured host through Traefik's TLS entry point from a client
container, sends forged X-Forwarded-For/-Proto/-Host/-Port from a neighbour container straight to the app on the
shared network, and reads what the app saw from its own `sessions.ip_address` rows (database session driver); it
then starts the release's stack file with plain `docker compose` and only environment variables (smoke: `/up`,
`/admin/login`, the published port, `app:install`), and uninstalls.

## Alternatives considered
- **Trust the shared network's subnet:** every neighbour could forge client address, scheme and host.
- **Trust the proxy by DNS name, resolved per request:** any container on the network can claim the alias.
- **Require `--trusted-proxy <IP>` always:** more friction for the common case, same staleness.
- **Pin the default network's subnet so the port setup's gateway is known before `up`:** risks address-pool
  collisions on the host; reading the gateway after Compose created the network is exact.
- **A committed stack file with the app image by tag:** violates "images by digest"; with a placeholder digest it
  would not run.
- **JSON for the stack file:** valid YAML and simpler, but people read and edit it in Portainer and Coolify, and
  Coolify parses compose files with a YAML library whose multi-line flow support was not verified.
- **A `migrate` one-off service in the stack:** a service the proxy-mode render does not have (the drift test
  compares the two); 1.0's compose.yaml already migrated on app start the same way.

## Consequences
- Proxy mode is installable; U11 must re-resolve the proxy address (`proxy_container`) and the port setup's gateway
  when it changes mode, network or proxy, and should add a doctor check that warns when the recorded address no
  longer belongs to that container. (Amended 2026-10-18: U11 re-resolves through `fsa config set trusted-proxy`
  (ADR 0077), but the doctor drift check was not built. It stays open (QUESTIONS 35); the remedy after a recreated
  proxy is `fsa config set trusted-proxy auto`.)
- Traefik deployments that set the certificate resolver per router need it on the entry point instead (or a later
  `--proxy-label`-style option); recorded in QUESTIONS.
- On Docker Desktop, containers can reach host-loopback ports through `host.docker.internal`, so in the port setup
  any local container could reach the app as the gateway address (inferred, not tested); local containers on a
  Desktop host are trusted with the socket-equivalent anyway.
- The stack file's limits are documented in its header; it is regenerated for every release and its checksum is
  signed with the manifest.
