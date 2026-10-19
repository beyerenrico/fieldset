# 0046: Performance targets at 1.0: results and accepted exceptions
- Status: accepted
- Date: 2026-10-23

## Context
§11 proposes performance targets (Appendix A: "proposals, not facts"): admin pages p95 < 500 ms at 100k
submissions per organization, API p95 < 300 ms, a 10-page PDF < 10 s (queued), Field App first load < 3 s on a
mid-range Android phone over 4G, and a responsive 300-element form and 500-row repeater. PLAN M8-T05 asks for
evidence and for regressions to be fixed.

The measurement (`docs/agent/milestones/evidence/M8/performance.md`, 100k submissions, 2.3M values, 150k audit
entries) found and fixed five regressions: saved reports (651 → 365 ms), the reviewer inbox (420 → 316 ms),
explorer searches (1.7 s → ≤ 291 ms), the API submission list (268 → 125 ms) and the Field App's repeater
evaluation (2.8 s → ≤ 170 ms per keystroke at 4× CPU). Three results remain at or over a line:

1. **Classic builder (v1) page:** 1,052 ms p95 and 4.5 MB of HTML. Filament's Builder renders every block's
   schema into the page. The cost does not depend on the number of submissions.
2. **Field App first load under Lighthouse's "devtools" throttling** (562.5 ms per request, 1.47 Mbit/s, 4× CPU):
   3.52 s. Under "slow 4G" (150 ms RTT, 1.6 Mbit/s, 4× CPU) it is 2.07 s; repeat loads come from the service
   worker (0 kB, 0.86 s).
3. **Adding the 501st repeater row** at 4× CPU: median 203 ms (192–225 ms). §11 sets no number for
   "responsive"; the measurement used Core Web Vitals' "good" INP limit of 200 ms. Typing stays at 136–168 ms.

## Decision
1.0 ships with these three as accepted exceptions; they are not fixed before the release.

- **Classic builder:** ADR 0044 made the canvas builder (110 ms p95, 310 elements 114 ms) the default. v1 opens
  only on request or for drafts with an open PDF import report. Making v1 fast means lazy block forms, a change
  to the page that every classic-builder test exercises, for a page that ADR 0044 plans to retire. Not worth
  the risk right before the release.
- **Field App first load:** §11 says "over 4G". The slow-4G profile (slower than typical 4G) passes with
  0.9 s to spare. Only the stricter request-level emulation misses, by 0.5 s.
- **501st row:** at the line of a self-chosen criterion; typing, the frequent interaction, is clearly below it.

## Consequences
- The release notes and `docs/agent/FINAL_REPORT.md` list the measured numbers and these exceptions.
- Follow-ups, cheapest first, are in the evidence file under "Remaining risks and proposals": precompressed
  brotli and a smaller preloaded chunk for the Field App; skipping the confirming settle round and deferring
  the autosave write after a row is added; moving the import report, versions and access settings out of v1
  and retiring it (ADR 0044); caching saved report results or a covering index before forms reach ~150k
  submissions; audit log indexes if the table reaches millions of rows.
- QUESTIONS lists the exceptions for human review.

## Amendment 2026-10-19: a second data point under E16 (U15, ADR 0083)
J10 ran the same perf scripts (3 warm-up, 30 runs, one client) at the same test volume on an `fsa` instance in domain
mode inside the E16 envelope: 2 GB of memory limits (Postgres 160 MiB) and two pinned CPUs, against 1.0's unlimited dev
stack on ten CPUs. Doc p95 in ms (1.0 → E16 `small`, run 6 of ADR 0083):

| Target | 1.0 | E16 `small` |
|---|---|---|
| Admin pages < 500 ms | met, except the classic builder | 23 of 31 series met; missed: saved reports 365 → 946 and 265 → 1,618, explorer searches 246 → 1,245 and 291 → 1,879, reviewer inbox 316 → 550, inbox filter form + date 156 → 613, inbox search number 194 → 586, classic builder 1,052 → 1,117 |
| API < 300 ms | met | met: 23 of 23, slowest the reviewer's in-review list 93 → 220 |
| 10-page PDF < 10 s queued | 2,279 | 3,961 |

The Field App first load and the repeater are client-side and not part of J10. The results above stand for a host with
memory for Postgres' page cache; on 2 GB the report, search and inbox pages read most blocks from disk (54 % hit rate).
The follow-ups under Consequences (cached saved-report results, covering or trigram indexes) are the levers for small
hosts as well; QUESTIONS 41 lists the options.
