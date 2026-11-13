# 0097: Hardening pass of 2026-11-13: mail payloads, domain imports, refusals, line limit, ops container
- Status: accepted
- Date: 2026-11-13
- Amends: ADR 0040 (Horizon's `font-src`, see the pass's CI fixes); ADR 0093 is amended separately (pins, Postgres
  client). ADR 0096 (journey tiers) is a separate record.
- Plan: `docs/plans/2026-11-13-001-fix-maintenance-pass-plan.md` (local only); requirements:
  `docs/brainstorms/2026-11-13-maintenance-pass-requirements.md`.
- Decision by the agent after a human asked for an analysis of the project and its docs and then for all findings to
  be addressed; QUESTIONS 82 lists what stays open.

## Context
The analysis found five places where the code was weaker than its records. `MailMessage::mailable()` called
`unserialize` on a stored payload with no class restriction. Domain code imported other modules, `App\Http` and
`App\Filament` classes in both directions, and nothing stopped the next import. Seven domain files threw
`ApiException`, an HTTP-layer class. Source lines had no limit. The ops container of the launcher shim (`ops`,
`measure` and `reclaim` runs) kept the default capability set and could gain privileges.

## Decision
**Mail payloads.** `MailMessage::mailable()` unserializes with `allowed_classes` set to `PAYLOAD_CLASSES`, refuses any
`__PHP_Incomplete_Class` in the result, logs the class that was refused, and requires `mailable_class` to be in
`MAILABLE_CLASSES`. `MailPayloadAllowListTest` derives the class set by parsing the serialized strings of real
payloads (`O:` and `E:` entries), because the object graph hides classes: restoring Carbon adds translator classes
through `__wakeup`. Limit: in PHP 8.4 `allowed_classes` does not gate backed enums (`E:` entries), so an enum in a
payload is not blocked by the list (an enum restores only to one of its own cases, which is why this is accepted).
The test still lists them so a new one is seen.

**Import baseline.** `tests/Arch/DomainImportBaselineTest.php` scans `app/Domain` with
`tests/Support/Arch/DomainImports.php` and compares the cross-module, `App\Http` and `App\Filament` imports with
`tests/Arch/domain-import-baseline.php`. It fails on a new import and on a baseline entry that no longer exists (the
baseline can only shrink). It freezes the existing cycles; it does not remove them. `MailMessage` still imports
`NotificationMail` and `ScheduledReportMail` (QUESTIONS 82i).

**Domain refusals.** `App\Support\Refusal\DomainRefusal` and `ExpectedDomainRefusal` replace `ApiException` in seven
domain files. `ApiExceptionRenderer` and `dontReportWhen` treat both families alike, so API responses and reporting
are unchanged and the domain no longer depends on the HTTP layer.

**Line limit.** `tests/Unit/Quality/LineLengthTest.php` fails on a line over 300 characters in `app/`. 53 lines were
wrapped to reach it; 553 lines still exceed 200. 300 is what the code could meet without rewriting hundreds of lines
in one pass; 200 would have made the pass a formatting change (QUESTIONS 82f).

**Ops container.** `distribution/shim.sh` runs `ops`, `measure` and `reclaim` with `--cap-drop ALL --security-opt
no-new-privileges` and adds back only what each run needs:

| Run | User | Capabilities kept | Why |
|---|---|---|---|
| ops, non-root | host UID | none | nothing to add |
| ops, `0:0` | root | CHOWN, DAC_OVERRIDE, FOWNER | repair ownership, read files of any owner |
| measure | root | DAC_READ_SEARCH | traverse the 0700 instance directory owned by the host UID |
| reclaim | root | CHOWN, DAC_READ_SEARCH | return files to the instance owner |

`LauncherVersionTest` pins the hash of shim version 1; the hash changed and the version did not, because 2.1.1 is not
tagged. Limits: this is defense in depth only. The Docker socket stays mounted, so a process in the container is
root-equivalent on the host. The `0:0` ops path occurs on Docker Desktop, rootless Docker or the `rules` fallback;
the smoke run on a Linux runner was green but no runner journey covers that path.

## Consequences
- Stored mail payloads of other classes now fail loudly instead of being instantiated. A new mailable needs its class
  in `MAILABLE_CLASSES` and in the payload test.
- New cross-module imports fail the Arch suite until the baseline is edited in a reviewed commit.
- The shim hash changed under an unchanged version number: instances refresh their `fieldset` script by version
  (ADR 0095), so the new capability set reaches them only with a release that raises the version.
- The line limit is a ratchet the human can lower; every step down means wrapping lines.
- The `0:0` path under reduced capabilities is untested on a real Docker Desktop, rootless Docker or the `rules`
  fallback; a failure there would call for another capability in the table.
