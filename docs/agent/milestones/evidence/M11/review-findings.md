# M11 gate review findings (2026-10-19)

Five reviewer subagents on `git diff main...m11-mail-phones` (at 84f007a): correctness, security, data safety,
agent operability, docs accuracy. Findings de-duplicated; the fix agent that owns each is in brackets.

## High
1. Unfinished `tunnel` journal blocks every mutating command (exit 9 loop); `tunnel stop` cannot adopt it; hint
   `fsa tunnel` is a usage error; no Interruption guard on tunnel/demo (correctness, data safety, agent ops) [A]
2. `tunnel start` rotates demo passwords before preflight/target; a failed pull loses them and says "nothing was
   changed" (correctness high, agent ops medium) [A]
3. Reverted `config set mail capture` loses the SMTP account (no stash for capture changes) (correctness, data safety) [C]
4. `config set domain` exposes demo accounts still on `password` (R29/AE2/E9) (security high, data safety medium) [B]

## Medium
5. Tunnel failure results break the ADR 0078 contract (exit 8, retryable, retry fields) (agent ops) [A]
6. `install --demo --phone-https tunnel` with failing tunnel reports stale `data.demo` (agent ops, correctness low) [A]
7. Resume of `install --phone-https tunnel` exits 2 `flag_already_applied` (correctness) [A]
8. Committed evidence held 9 demo passwords of a removed test instance (EvidenceRedactor misses `secrets[].value`)
   (security) — scrubbed in 6119bb2; redactor fix [A]
9. Demo org identified only by slug `demo` (real org can take it; R65 confirmation bypassed) (data safety) [B]
10. SMTP passwords with 0x0B/0x0C/0x85 bytes (Å, ą, х) corrupted on env read-back (correctness) [C]
11. `starttls` opportunistic: STARTTLS stripping sends the SMTP password in cleartext (security) [C]
12. Small Postgres: jit on, parallel workers, margins unproven for swapless/concurrent use (data safety) [C]

## Low (fixed where cheap)
- install repeat with tunnel → wrong key [A]; status `data.url` not the tunnel URL [A]; ExposureCheck silent up to
  300 s, hostname maxSeconds understated [A]; exposure check before lock (security) [A]; stale TunnelStack docblock [A]
- demo rotation keeps login links/reset tokens [B]; operator-email/demo link idempotence, `rate_limited` without
  retry_after, operator-email ignores an open journal [B]
- `mail smtp --smtp-host <new>` reuses the old account [C]; resume after completion re-applies after clean-up [C];
  CA deletion failure not retryable [C]; lan-address preflight skips new address [C]; `missing_flag` vs
  `input_required`, help text keys [C]
- Logged only: local CA keys on the edge volume instead of `<instance>/certs` (§10 deviation; ADR/QUESTIONS);
  SecretRedactor ignores secrets < 4 chars; `--catcher-login` hint on finished instances; test-mail hint without
  operator.

## Docs accuracy (all medium unless noted)
13. Trust page tells Android users to run `fsa tunnel start`, which local-CA mode refuses [D]
14. Banner SMTP command lacks `--from-address` (forms@localhost refused); rolled-back results drop `sender_localhost`
    and the `test_mail_failed` hint ignores the sender [D, C]
15. ADR 0080's human checklist items (real SMTP STARTTLS/TLS, DE banner screen reader) on no checklist [D]
16. M11 commands undocumented for users; install.sh help points at a guide without fsa flags; catcher login not
    discoverable; CHANGELOG lacks the M11 entry [D, C]
17. Narrow banner omits e-mail confirmations (Filament verification mails use the instance mailer) [D]
- Lows: help/docblock key lists, TunnelOperation docblock, Q37 stale, ResourceBudget docblock runs, ConfigValues
  hint without command, fake-tunnel Dockerfile comment [A, C, orchestrator]. admin-guide.md capture banner → U21.
