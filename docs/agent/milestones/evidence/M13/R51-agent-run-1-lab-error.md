# R51 agent-guide run (operator agent, guide-only)

Date: 2026-10-20. Input: one document, `agent-guide.md` (scratchpad copy r51/work). No repo source, tests or other docs read.
Lab: `r51/out/lab.json` (install_script under `storage/fsa-test-harness/773d2d/dist/download/v2.0.0-rc.1-test/`,
version `2.0.0-rc.1-test`, FSA_RELEASE_URL `http://127.0.0.1:54491`, SMTP `host.docker.internal:54889`).
Notation: `B=/Users/enricobeyer/Arbeit/form-schema-app/storage/fsa-test-r51`. `[S]` marks a lab substitution.

## Result: blocked at the first recipe

The install step of "Recipe: local demo" failed with exit 3 `manifest_signature_invalid`. The guide (exit table,
line 83) says: on `manifest_signature_invalid` stop, report `errors[0]` to the person, never retry with another
install.sh or release URL, never bypass the check. Every other runnable recipe needs an installed instance, so the run
stopped there, as the guide says it should. No workaround was attempted.

## Recipe: local demo

Step 1, install [S: the one-liner becomes the lab's install.sh; `~/forms` becomes `$B/forms`; `~/forms-secrets.json` becomes `$B/forms-secrets.json`]:

```
cd $B; FSA_RELEASE_URL=http://127.0.0.1:54491 sh <install_script> --version 2.0.0-rc.1-test \
  --dir $B/forms --org "Example Ltd" --owner-email owner@example.com --demo \
  --secrets-file $B/forms-secrets.json --json --yes   > logs/install.out 2> logs/install.err
```

- exit 3; envelope `status: failed`, `exit_code: 3`, `changed: false`, `data: {}`, `secrets: []`, `warnings: []`
- `errors[0].code`: `manifest_signature_invalid`, `retryable: false`
- `errors[0].message`: "The release manifest signature does not match the signer built into install.sh: error during
  command execution: failed to verify log inclusion: not enough verified log entries from transparency log: 0 < 1"
- `errors[0].hint`: "Install only releases published by the form-schema-app release workflow. If Sigstore was
  unreachable, retry later."
- stderr progress (2 lines): phase `verify` info, then phase `verify` error with the same text.
- Envelope shape matched the guide's description of an install.sh exit 3 (`data` empty, read `errors[0]`): PASS for
  the guide's failure description; FAIL for the recipe goal.

Step 1 repeated once with the identical command and URL (to test the hint's "retry later"; this contradicts the guide's
"never retry" but changes neither the script nor the URL): exit 3, `status: failed`, `manifest_signature_invalid` again.
Deterministic, not a reachability problem.

Instance directory: not created (`$B` held only `logs/` and `offhost/` afterwards). No Docker resources created.

## Recipes not executed (blocked by the failed install)

| Recipe | Status |
|---|---|
| sign-in links | not run: no instance |
| configure SMTP | not run: no instance |
| backup and restore | not run: no instance |
| upgrade (`--to 2.0.0-rc.2-test`) | not run: no instance |
| move to another server | not run: no instance |
| VPS install (DNS failure path) | not run: same install.sh, would fail at verify before the doctor |
| change the domain | not run: no instance |
| phone demo | text review only (by instruction) |
| remove an instance | not needed: nothing was installed |

Static review of the not-executed recipes is in the report returned to the caller (findings labelled "static").

## Cleanup check

```
docker ps -a --filter name=fsa-test-r51      -> fsa-test-r51-smtp (lab, left alone)
docker volume ls --filter name=fsa-test-r51  -> (none)
docker network ls --filter name=fsa-test-r51 -> (none)
ls $B                                        -> logs/ offhost/ (no forms*/ directories)
```

PASS: nothing to remove.
