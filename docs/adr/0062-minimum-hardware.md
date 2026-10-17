# 0062: Minimum hardware: 2 vCPU, 2 GB RAM, 20 GB disk, measured
- Status: accepted
- Date: 2026-10-17

## Context
E16. The "small VPS" promise needs a number. Today the app image is 2.12 GB and Gotenberg 1.17 GB uncompressed
(Appendix C). 1.0 never measured the stack under a memory ceiling.

## Decision
- The floor is **2 vCPU, 2 GB RAM, 20 GB disk**, for domain mode with capture mail and without Keycloak. Keycloak is
  not part of an instance (Appendix B lists it as a later optional profile).
- Every service gets a memory limit from `ResourceBudget`. The sum stays within 2 GB minus OS headroom, with the
  scheduler running and production Horizon settings.
  - The generated compose file sizes Horizon processes, FrankenPHP threads and Postgres memory to fit, including
    `shm_size` and small-host settings.
  - It also sets Gotenberg's `CHROMIUM_MAX_CONCURRENCY` and a queue bound (plan U3).
  - A larger host scales Horizon processes and Gotenberg concurrency up.
- The floor is measured, not assumed. J10 runs inside a 2-CPU, 2 GB envelope with the scheduler running and covers
  install, the smoke E2E, a 10-page PDF render and the 1.0 performance scripts (`scripts/perf/`) at the 1.0 test
  volume. Peaks and p95s go to the evidence folder and to a follow-up ADR.
- Timing: the plan moves the measurement to U15 in M11, because E16 is defined with capture mail, which arrives
  in U12. §12 of BUILD_PROMPT_V2 lists J10 in M10. A run in M10 counts as preliminary.
- The doctor checks RAM and disk against these numbers (R10). It warns instead of failing when it cannot tell.

## Alternatives considered
- **4 GB:** safer, but it gives up the cheapest VPS tier, which is the promise.

## Consequences
- Gotenberg OOMs under several Chrome processes are reported in the community (plan Risks). Low concurrency, the
  whole-stack limits and swap guidance mitigate them.
- A miss is recorded with numbers. It is never fixed by dropping a backup, a health gate or a security control
  (§13.4).
- Disk use adds up:
  - images: app up to 1.0 GB (E17), Gotenberg about 1.17 GB, plus Postgres, Valkey, Caddy and Mailpit;
  - seven daily backups plus retained safety backups;
  - an upgrade or restore needs room for a safety backup, a second database and a second storage volume (KTD19).
  - 20 GB therefore suits small data sets. The doctor checks free space before an upgrade or restore and exits 3
    when it is short.
- The 1.0 performance targets for admin pages and the API must still hold at the E16 limits (§10).
