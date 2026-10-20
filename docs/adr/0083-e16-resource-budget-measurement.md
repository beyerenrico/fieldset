# 0083: E16 measured: the small budget under a 2-CPU envelope at the 1.0 test volume
- Status: accepted
- Date: 2026-10-18
- Refines: ADR 0062 (minimum hardware, "measured, not assumed"), ADR 0069 (resource budget table), ADR 0070
  (doubled limits under emulation), ADR 0046 (1.0 performance targets)

## Context
Plan unit U15 (PLAN M11-T04): E16, §2.3 "Small", journey J10, §13 rule 4 ("a miss is an ADR with numbers"). ADR 0069's
`small` table was an estimate. E16 asks for domain mode with capture mail, no Keycloak, the scheduler running and
production Horizon settings, inside 2 vCPU / 2 GB, with the sum of all memory limits within 2 GB minus OS headroom.
§2.3 adds: install to sign-in link in at most 5 minutes (pulls excluded and reported separately), and the 1.0 targets
(admin pages p95 < 500 ms at 100k submissions, API p95 < 300 ms, a 10-page PDF < 10 s queued; ADR 0046) still met at
the 1.0 test volume.

## Method
**Host and engine (verified with `docker info` and `sysctl`):** Apple M1 Pro, 10 cores, 32 GB; Docker Desktop with
Docker 29.2.1, a Linux VM with 10 CPUs and 15.6 GiB, aarch64, kernel 6.12.72-linuxkit, cgroup v2 (cgroupfs driver),
Compose 5.0.2. Native arm64 images only (the M11 gate repeats J10 under amd64 emulation, where ADR 0070 doubles every
limit).

**Envelope.**
- Memory: the generated compose file's own `mem_limit`s (`ResourceBudget`, size `small`). Nothing else limits memory.
- CPU: `tests/Support/Docker/EnvelopeMonitor.php` follows `docker events` and runs `docker update --cpuset-cpus 8,9`
  on every container of the instance (its services, `compose run` one-offs, throwaways labelled with the instance UUID)
  and on every `fsa` ops container that mounts the instance directory, as soon as Docker reports its creation (the
  update takes about 0.1 s; throwaways that exit before that are recorded with "No such container"). All of them share
  the same two CPUs, as on a 2-vCPU host. Rejected: per-service `cpus` quotas summing to 2. They cap each service at its
  share even while the others idle (the app could never use more than its fraction during a page view), which is
  stricter than a 2-vCPU host, and in the product they would also throttle bigger hosts. CPU limits stay out of the
  product: on the target host its CPU count is the limit.
- Sampling: one `docker stats` stream (working set = usage minus inactive page cache; CPU in % of one CPU; summed over
  the stack per refresh) and, every 2 s, the cgroup `memory.stat` of every service, read through one read-only reader
  container (`CgroupReader`): anonymous plus shared memory, which reclaim cannot free without swap. At the end:
  `memory.peak` (page cache included), `memory.events` (`max` = how often the limit was hit and reclaim ran; `oom_kill`
  = kills of any process, `docker exec`ed ones included), `docker inspect` (`OOMKilled`, `RestartCount`), and Postgres'
  I/O counters over the two perf scripts (`pg_stat_reset()` before them).

**Workload (J10, in this order):** `install.sh … --domain` against Pebble and challtestsrv (as J2), capture mail, no
`--demo` → the smoke E2E (`tests/e2e/fsa-smoke.spec.ts` in Chromium, trusting Pebble's chain by SPKI: the owner link
sets a password, the dashboard shows the capture banner, the Field App registers its service worker) → `fsa demo`
(random demo passwords) → `app:seed-load demo 100000` in a one-off → rendition rows for the generated submitted,
in-review and changes-requested submissions (see Data) → `scripts/perf/fixtures.php` in a one-off → wait until Horizon's
queue is empty → `scripts/perf/pdf.php 160 6 10` → `admin-pages.mjs` and `api.mjs` with their 1.0 settings (3 warm-up,
30 runs, one client) against `https://fsa-test-j10.test` → OOM and restart checks → `fsa uninstall`.

**Data.** As in 1.0 (ADR 0046): 100,000 generated submissions, 2.32M values, 40k tasks, 38k workflow runs, 17k mails,
150k audit entries; database 1,265 MiB (`submission_values` 911 MiB, `submissions` 150 MiB). One difference: 1.0
stopped its scheduler while the load was present, because `documents:sweep` would render the ~22k generated
submissions without a rendition (the generator gives only approved and completed ones a row). E16 needs the scheduler
running, and a real instance renders every submission on submit, so J10 gives those submissions the same file-less
rendition row instead (22,003 rows in the shipped run; renditions 71k → 93k). Renders the sweep dispatched while the
load was being generated finish before anything is measured.

**Perf scripts against an instance** (`scripts/perf/*`; defaults unchanged for the dev stack): `PERF_PASSWORDS_FILE`
(the random demo passwords from `fsa demo --secrets-file`), `PERF_CHROMIUM_ARGS` (host resolver rule and SPKI list),
`PERF_RESOLVE` and `PERF_CA_FILE` (the token calls from Node), `PERF_THROWAWAY_INSTANCE=1` (lets `fixtures.php` and
`pdf.php` run in a production image, read from stdin). `fixtures.php` now picks an in-review sample the demo reviewer may
open: on a fresh demo every generated task belongs to "Team Nord" and the reviewer is in "Büro", so the 1.0 query
returned a submission the reviewer gets a 404 for; the newest one's open task is handed to the reviewer's group.

**Runs.** Six runs on 2026-10-18, all summarized in `docs/agent/milestones/evidence/M11/J10-budget-iterations.json`:
run 1 with ADR 0069's table (shakedown), run 2 with generous limits to measure needs (`J10-calibration/`), runs 3–5 with
candidate tables, run 6 with the shipped table (`J10-*.json`). Microsoft Defender scanned new files on the host during
all runs (up to 6 cores in run 2). J10 raises the scripts' load gate to 10; the 1-minute load average at the start of
each series is in the evidence (run 6: 2.7–9.6).

## Results (run 6, shipped budget)
**Memory (MiB).** No OOM kill, no restart and no `oom` event in any container, one-offs and ops containers included.

| Service | Limit | Unreclaimable peak (anon + shmem) | Working-set peak (`docker stats`) | `memory.peak` (cache incl.) | Limit hits |
|---|---:|---:|---:|---:|---:|
| app | 384 | 354 | 357 | 378 | 0 |
| queue | 400 | 363 | 370 | 400 | 21 |
| scheduler | 208 | 175 | 181 | 184 | 0 |
| postgres | 160 | 122 (while loading data) | 158 | 160 | 490,929 |
| gotenberg | 304 | 255 (275 in run 4) | 292 | 304 | 105 |
| redis (Valkey) | 48 | 6 | 7 | 12 | 0 |
| mailpit | 48 | 9 | 12 | 16 | 0 |
| edge | 48 | 16 | 17 | 18 | 0 |
| backup (U16), tunnel | 128, 48 | not rendered in this mode | | | |

- The app's peak is one classic-builder request (4.5 MB of HTML); the queue's is five PHP processes (Horizon master,
  two supervisors, one default and one exports worker; about 57 MiB each at rest, 286 MiB before any job); the
  scheduler's is `schedule:work` plus `schedule:run` plus one command (CLI, no OPcache); Gotenberg's is the 10-page PDF.
  Postgres sits at its limit all the time, with page cache.
- The whole stack's working set peaked at 1,029 MiB (sum per `docker stats` refresh). Transient containers: the `fsa`
  ops container up to 85 MiB, one-offs up to 118 MiB (`app:seed-load`). CPU: the stack used both CPUs fully while the
  load was generated (193 %), 42–44 % on average during the perf scripts (one client).
- Engine overhead in the Docker Desktop VM: one `containerd-shim` per container at 10–15 MiB RSS, `containerd` 39 MiB
  (read through a `--pid=host` container); Docker Desktop's `dockerd` (1.16 GiB after days of builds) says nothing about
  a VPS.

**Timings.**
- Install to sign-in link: 53.4 s, of which 6.3 s pulls (the app image from the harness registry; the other images were
  present), so **47.1 s** without pulls; the `start` phase (health checks) is 32 s of it. Runs 2–6: 45.8–47.1 s.
- Pull size of a first domain-mode install on arm64: 838 MB compressed (Gotenberg 506, app 160, Postgres 114, Caddy 24,
  Valkey 18, Mailpit 17), **67 s at 100 Mbit/s**.
- 10-page PDF (160 lines, 6 photos, 1.49 MB): inline p95 1,356 ms (1.0: 1,028), queued p95 **3,961 ms** (1.0: 2,279).
  Queued times are bimodal (0.8 s or 3.8–4.0 s): with one default worker, a job that arrives while the worker sleeps
  between polls (Horizon's 3 s) waits for it.
- Load generation (`app:seed-load demo 100000` in a one-off): 482 s (1.0 on the unlimited dev stack: 198 s).

**Admin pages (doc p95 in ms, one client; 1.0 = M8 evidence, ADR 0046).** 23 of 31 series meet 500 ms.

| Page or search | 1.0 | Run 2 (generous limits, Postgres 256) | Run 6 (shipped) | Verdict |
|---|---:|---:|---:|---|
| saved report, all 100k by type | 365 | 1,018 | 946 | **missed** |
| saved report, last year by month | 265 | 2,143 | 1,618 | **missed** |
| explorer search, unique term (Livewire) | 246 | 2,116 | 1,245 | **missed** |
| explorer search, word in 35 % (Livewire) | 291 | 2,865 | 1,879 | **missed** |
| reviewer inbox | 316 | 551 | 550 | **missed** |
| inbox filter form + date | 156 | 183 | 613 | **missed** |
| inbox search number | 194 | 310 | 586 | **missed** |
| classic builder (ADR 0046 exception) | 1,052 | 1,411 | 1,117 | **missed** (as in 1.0) |
| inbox (default) | 182 | 374 | 285 | met |
| inbox filter status | 154 | 348 | 480 | met |
| canvas builder, 310 elements | 114 | 343 | 179 | met |
| dashboard (owner) | 98 | 96 | 134 | met |

The other 19 series (lists, details, audit log, workflows, members, mail log, builders, reviewer dashboard and tasks,
the two narrower searches) measure 98–484 ms, most close to 1.0. Raw numbers: `J10-admin-pages.json`.

**API (p95):** all 23 series meet 300 ms; the slowest is the reviewer's in-review list at 220 ms (1.0: 93), most are
within 10 ms of 1.0 (`J10-api.json`). The same series measured 318–344 ms in runs 3–5.

**Postgres over the two perf scripts:** 15.7M blocks read outside shared buffers against 18.6M hits (54 %); 4.7M of
the reads are `submission_values` heap blocks, 9.5M `submissions` heap blocks; no temp files.

## Decision
**Budget `small` (MiB; worst case = available = 1,728):**

| | app | queue | scheduler | postgres | redis | gotenberg | mailpit | edge/tunnel | backup |
|---|---|---|---|---|---|---|---|---|---|
| ADR 0069 estimate | 384 | 320 | 128 | 256 | 80 | 320 | 48 | 64 | 128 |
| shipped | 384 | 400 | 208 | 160 | 48 | 304 | 48 | 48 | 128 |

- Horizon: one default worker on `small` (was 2); exports stay at one. With two, the queue's unreclaimable memory
  peaked at 363 MiB in run 2 and would need about 430 MiB. Gotenberg renders one document at a time anyway.
- Every slot keeps its measured unreclaimable peak plus about 30 MiB or more: app 30, queue 37, scheduler 33,
  Postgres 38, Gotenberg 29–49, the small services far more. Mailpit keeps 48 because amd64 Mailpit under emulation needs
  about 60 MiB plus its health check (doubled to 96, ADR 0070). Beyond that floor Postgres gets what is left: more only
  buys page cache, while a killed queue worker loses its job, a killed FrankenPHP takes the app down and a killed
  Postgres backend sends the server into crash recovery. The backup slot keeps ADR 0069's 128 until U16 measures it (a
  PHP daemon plus `pg_dump` will not need much less).
- Postgres settings follow the limit (`shared_buffers` 40 MB, `effective_cache_size` 120 MB, `maintenance_work_mem`
  20 MB); `work_mem` never drops below Postgres' default of 4 MB (the formula would give 2 MB, and report aggregates
  would spill to disk).
- FrankenPHP keeps 4 threads: page views fire up to three parallel Livewire requests, and one classic-builder request,
  not concurrency, sets the app's peak.
- `medium` scheduler 192 → 256 (the measured need does not grow with the size); the rest of `medium` and `large` is
  unchanged and unmeasured.
- How the table was found: run 3 (queue 368, Postgres 224) and run 4 (queue 384, Gotenberg 304, Postgres 176) left the
  queue 15–20 MiB above its unreclaimable peak with 77–103 limit hits; run 5 (queue 400, Gotenberg 320, Postgres 144)
  left Postgres 22 MiB above its peak while loading data; run 6 balances the two.

**Verdicts (E16 and §2.3 "Small" at the 1.0 test volume):**

| Target | Result | Verdict |
|---|---|---|
| Sum of limits ≤ 2 GB − 320 MiB, scheduler running, production Horizon | 1,728 of 1,728 MiB; no OOM kill or restart through install, smoke E2E, load, PDF and perf scripts | **met**, with the margins above |
| Install → sign-in link ≤ 5 min, pulls excluded | 47.1 s (+ 6.3 s local pulls; 67 s at 100 Mbit/s) | **met** |
| 10-page PDF < 10 s queued | p95 3,961 ms | **met** |
| API p95 < 300 ms | 23 of 23, slowest 220 ms | **met** (318–344 ms for one series in runs 3–5) |
| Admin pages p95 < 500 ms at 100k submissions | 23 of 31 series; misses up to 1,879 ms | **missed** |

The admin-page misses are recorded, not fixed by weakening anything (§13.4). They are the report pages, the explorer's
text searches, the reviewer's inbox and two filtered inbox views: queries over `submission_values` and `submissions`
that 1.0 answered from several GiB of page cache with parallel workers on ten CPUs. On a 2 GB host Postgres keeps about
100 MiB of cache, so most of their blocks come from disk (54 % hit rate above). Inferred, not separately measured: the
share of two CPUs against the missing cache. Run 2, with Postgres at 256 MiB and everything else generous, missed the
same report, search and reviewer-inbox pages, so a few MiB more for Postgres would not close the gap; the two inbox
filters moved over the line as Postgres shrank from 224 to 160 MiB. The classic builder is ADR 0046's known exception.

## Alternatives considered
- **Per-service `cpus` quotas** (summing to 2) instead of a shared cpuset: rejected above.
- **Docker-in-Docker with `--cpus 2 --memory 2g`** as a whole-host stand-in: closest to a VPS, but a privileged daemon,
  a second image store and the harness networks inside it; out of proportion for a measurement.
- **Two default Horizon workers and a smaller Postgres:** the queue would need about 430 MiB and leave Postgres no cache.
- **Lowering the OS headroom** to make room: 320 MiB is already an estimate for kernel, systemd, sshd, journald, dockerd,
  containerd and nine shims at 10–15 MiB each; shrinking it on paper only moves the risk to the host.
- **Raising E16 to 3–4 GB, a Postgres-heavy profile, or query work** (cached report results, covering indexes): product
  decisions, listed in QUESTIONS.

## Consequences
- E16's memory rule holds with measured margins of about 30 MiB per service; the admin-page part of §2.3 "Small" does
  not hold at 100k submissions on 2 GB. The release notes and the M13 gate report must say so. QUESTIONS lists the
  options: accept for small data sets and state the size from which 4 GB is needed; ADR 0046's follow-ups (cached saved
  report results, covering or trigram indexes for the explorer); free memory for Postgres with a lighter scheduler or
  report exports on the default Horizon supervisor.
- J10 asserts what must hold (install, smoke E2E, a 10-page PDF, every perf series answering, no OOM kill, no restart,
  every service pinned to the two CPUs, limits as budgeted) and records timings and p95s with a verdict each; a missed
  timing does not fail it.
- The envelope is a Docker Desktop VM, not a VPS: the two pinned vCPUs are host threads competing with macOS (Defender),
  the edge is reached through Docker Desktop's port forwarding, a container's page-cache misses are served from the VM's
  disk image (itself cached by macOS, faster than most VPS disks), and dockerd and containerd run outside the pinned
  CPUs. Absolute numbers are indicative and vary between runs by up to ±40 % on the I/O-bound pages (the inbox filters
  measured 183–771 ms across runs 2–6); the memory peaks repeat within a few MiB and carry over.
- Unmeasured: the backup slot (U16 measures it and changes this table with a new J10 run if needed), the tunnel slot
  (cloudflared; only the fake tunnel ran), `medium` and `large`, and amd64 emulation (the gate's J10).
- ADR 0069 (budget table, "estimates until U15"), ADR 0062 ("a follow-up ADR") and ADR 0070 (emulation note) need
  amendments pointing here.

## Amendment 2026-10-19: the referring ADRs are amended (M11 gate)
The amendments the last Consequence asks for are in place: ADR 0069 (the shipped table, Horizon `small` 1, the
`work_mem` floor, `medium` scheduler 256, what stays unmeasured), ADR 0062 (this ADR as its follow-up, the verdicts),
ADR 0070 (emulation doubles the new limits; Mailpit kept at 48 MiB) and ADR 0046 (J10 as a second data point).

## Amendment 2026-10-19: small Postgres without JIT and parallel query, 30 connections (M11 gate)
- **Change.** `small` renders `jit=off`, `max_parallel_workers_per_gather=0` and `max_connections=30` (was Postgres'
  defaults `on` and `2`, and 40; `J10-postgres.json` recorded them). `medium` and `large` keep the defaults (unmeasured)
  and their connection counts.
- **Why.** At a 160 MiB limit with 40 MB shared buffers, the memory a query may take beyond them decides whether the
  OOM killer takes a backend, and a killed backend sends the whole server into crash recovery (data safety before
  speed, as above). The pinned `postgres:16-alpine` image ships LLVM JIT (`llvmjit.so`, verified 2026-10-19), and the
  report queries over 100k submissions cost far above `jit_above_cost` (100,000), so each would compile in its backend;
  each parallel worker is one more backend with its own `work_mem` per sort or hash and its own catalog caches. Inferred,
  not measured: the risk of an OOM kill under concurrent report users or exports; J10's single client never hit it.
- **Connections.** `ResourceBudget::postgresClients()` counts what the stack's processes can hold at once (each PHP
  process keeps at most one connection): 4 FrankenPHP threads, 5 Horizon processes (master, two supervisors, the
  default and the exports worker), 4 for the scheduler (schedule:work, an overlapping schedule:run and the commands they
  start), 2 `fsa` one-offs, 2 for the backup service (U16): 17 on `small`. 30 leaves 13 spare; the app's user is the
  image's superuser, so the 3 reserved slots count. A unit test keeps `max_connections` at least 10 above the client
  count for every size, so raising threads or workers without connections fails.
- **Speed.** Not re-measured here; the gate's J10 run records the effect. Inferred: on two CPUs a parallel worker
  competes with the app and the queue for the same cores, and JIT compile time is often larger than its gain on these
  queries, so the admin-page misses above are not expected to grow much; if they do, the verdict table gets the numbers.

## Amendment 2026-10-19: the backup slot measured (U16, ADR 0084)
J08 measured the backup service on `small`: `memory.peak` 68 MiB over the container's life including a scheduled,
encrypted backup run, unreclaimable peak 59.6 MiB (mostly the idle PHP daemon), no limit hit, no OOM kill. 68 + 30 MiB
fits the 128 MiB slot, so the table above stands and the Consequence "U16 measures it and changes this table with a new
J10 run if needed" is settled without a change. The measurement used the demo data, not the 1.0 test volume (ADR 0084).

## Amendment 2026-10-20: the backup slot at the 1.0 data volume (U22, ADR 0091)
J10 now runs a scheduled backup after its perf scripts, on the 1.0 test volume (database 1269 MiB): it succeeded in
12 s (112 MiB, unencrypted), unreclaimable peak 77.7 MiB, `memory.peak` 128.3 MiB (page cache reclaimed at the limit,
894 `max` events, no OOM kill). 77.7 + 30 MiB fits the 128 MiB slot, so the table above stands.
