# 0063: App image size budget: at most 1.0 GB uncompressed per architecture
- Status: accepted; the cosign lever was taken in ADR 0071 (for the scan gate)
- Date: 2026-10-17

## Context
E17. The app image is 2.12 GB uncompressed today (local arm64 build, Appendix C). Pull time on a small VPS
decides the "minutes" promise (§2.3). 2.0 adds the ops binaries to the image (ADR 0048, plan KTD8).

## Decision
- Budget: at most **1.0 GB uncompressed per architecture**. The image contract journey (plan U1) measures it for
  both architectures and writes it to the M9 evidence. Compressed pull size, and pull time at 100 Mbit/s, are
  reported separately (§2.3).
- Slimming (plan U1):
  - turn `.dockerignore` into an allow-list;
  - drop `tests/`, `docs/`, `scripts/`, `.github/`, package tests, dev dependencies and the runtime `composer`
    binary;
  - keep `spec/form-definition/1.0`, which `SpecPaths.php` reads at runtime (Appendix C).
- A miss is recorded with numbers in an ADR and in QUESTIONS, never silently accepted (§13.4).

Added binaries, measured on 2026-10-17 from the release assets (uncompressed):

| Binary | Size |
|---|---|
| Docker CLI 29.9.1 (`docker/docker` from the static tarball) | 41.6 MB (arm64) |
| Compose 5.6.0 | 29 MiB (arm64), 31 MiB (amd64) |
| cosign 3.1.3 | 126 MiB (arm64), 134 MiB (amd64) |
| age 1.3.2 and age-plugin-batchpass | 6.5 MB and 4.0 MB (arm64) |
| `postgresql-client-16` (PGDG) | not measured yet, measured in U1 |

Together that is about 210–220 MB before the Postgres client. That is double the plan's estimate of "about 100+
MB" (plan Risks). The slimming therefore has to remove about 1.3 GB from today's image.

## Alternatives considered
- **No budget:** pull time drifts upward release by release.
- **Separate ops image:** two images to sign, pin and keep in step. "The image is the installer", with per-version
  upgrade logic, would then need a second artifact.

## Consequences
- If slimming alone misses the budget, the first lever is to stop embedding cosign. The ops container would run
  the official cosign image, pinned by digest, through the socket it already holds. That saves about 130 MB, and
  the built-in identity of R58 stays unchanged. This changes KTD8 and needs its own ADR.
- The next levers are the FrankenPHP base and the PHP extension set, measured in U1.
- Gotenberg (1.17 GB) is outside this budget but counts toward pull time and E16 disk.
