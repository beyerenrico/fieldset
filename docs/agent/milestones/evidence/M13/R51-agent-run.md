# R51 agent run: agent-guide.md against a local lab (2026-10-20)

Operator agent with only `agent-guide.md` (plus `--help` output). Lab env on every command: `FSA_RELEASE_URL`,
`FSA_REGISTRY`, `FSA_TRUST_KEY`, `SSH_CONNECTION` from lab.json. `$R` = `storage/fsa-test-r51`.
Substitutions marked **[S]**. Secrets redacted. Logs in `$R/logs/`.

[S] one-liner -> `sh <lab install.sh> --version 2.0.0-rc.1-test …`; `~/forms` -> `$R/forms`; `~/forms-new` -> `$R/forms2`;
`~/forms-move` -> `$R/forms-move`; person-created files, secrets files and identity -> `$R/secrets/` (0600);
"off the host" -> `$R/offhost/`; SMTP -> `host.docker.internal:55037`, `--smtp-encryption none`.

## 1. Local demo — PASS
| Step | Command | Exit | status | Result |
|---|---|---|---|---|
| install [S] | `sh install.sh --version 2.0.0-rc.1-test --dir $R/forms --org "Example Ltd" --owner-email owner@example.com --demo --secrets-file $R/secrets/forms-secrets.json --json --yes` | 0 | ok | every expect field present; url `http://localhost:8081` (8080 busy, next free port picked automatically), mode local, demo 9 users, `sign_in_link.delivered=secrets_file`, `secrets[0].written_to` set; secrets file 0600 with `{schema_version, command, secrets:[{name,value,expires_at}]}` |
| URLs | curl `<url>/admin`, `<url>/app`, catcher | 302 / 200 / 401 | – | as described |
| catcher login [S] | `forms/fsa config show --catcher-login --secrets-file $R/secrets/forms-catcher.json --json` | 0 | ok | secret `catcher_password` written; user name only in `data.catcher_login.user` (`fsa`) — not in guide (F7) |

## 2. Sign-in links — PASS
| Step | Command | Exit | status | Result |
|---|---|---|---|---|
| status | `forms/fsa status --json` | 0 | ok | installed true, health ok, mode local, url, services object, version, operation null |
| login-link [S] | `forms/fsa login-link owner@example.com --secrets-file $R/secrets/forms-link.json --json` | 0 | ok | matches expect (`kind: admin` extra); link page answers 200 |

## 3. Configure SMTP — PASS
| Step | Command | Exit | status | Result |
|---|---|---|---|---|
| password file [S] | `$R/secrets/smtp-password` (0600) | – | – | – |
| set smtp [S] | `forms/fsa config set mail smtp --smtp-host host.docker.internal --smtp-port 55037 --smtp-encryption none --smtp-username forms@example.com --password-file $R/secrets/smtp-password --from-address forms@example.com --test-to owner@example.com --json` | 0 | ok | key mail, from capture, to `smtp://…(no TLS) (catcher kept)`, mail.mode smtp, checked list; warning `smtp_plaintext_auth`; Mailpit received "form-schema-app: test mail" to owner@example.com |
| failure path | same without `--password-file` | 2 | failed | `password_required` (as documented) |
| rule 4 | `config set mail smtp --smtp-password x --json` | 2 | failed | `secret_in_argument` (as documented) |

## 4. Backup and restore — PASS (one code mismatch, F1)
| Step | Command | Exit | status | Result |
|---|---|---|---|---|
| backup | `forms/fsa backup --json` | 0 | ok | backup `manual-20261020T201025Z`, path, kind manual, encrypted false, bytes; warning `backup_on_host` |
| off host [S] | `cp -R <path> $R/offhost/`; `grep APP_KEY/APP_PREVIOUS_KEYS app.env > $R/offhost/app-key.env` | 0 | – | only `APP_KEY` present (no previous keys on a fresh instance) |
| status warnings | `forms/fsa status --json` | 0 | ok | `warnings: []`, `last_backup.status succeeded` |
| drill | `forms/fsa restore manual-20261020T201025Z --drill --json` | 0 | ok | backup, dropped true, storage object; checks pass |
| restore w/o --yes | `forms/fsa restore manual-… --json` | 6 | aborted | `confirmation_required`, `data.question` present |
| restore | `forms/fsa restore manual-… --yes --json` | 0 | ok | epoch 2, previous_epoch 1, safety_backup `safety-restore-…`, webhooks paused; warning `webhooks_paused` |
| webhooks on | `forms/fsa config set webhooks on --json` | 0 | ok | key webhooks, from paused, to on |
| outside path | `forms/fsa restore $R/offhost/manual-… --drill --json` | 2 | failed | code `backup_not_found`; guide says `backup_not_visible` for a path outside the instance dir (F1) |

## 5. Upgrade — PASS
[S] `FSA_RELEASE_URL=http://172.17.0.3:8080`, `--to 2.0.0-rc.2-test`.
| Step | Command | Exit | status | Result |
|---|---|---|---|---|
| version | `forms/fsa version --json` | 0 | ok | instance rc.1, newest_known null |
| upgrade | `forms/fsa upgrade --to 2.0.0-rc.2-test --json` | 0 | ok | from rc.1, to rc.2, safety_backup, migrated [marker], notes_url |
| version | `forms/fsa version --json` | 0 | ok | instance rc.2 |
| rerun | same upgrade | 0 | ok | `changed:false`, `current:true` |
| follow | `forms/fsa upgrade --follow --json` | 0 | ok | returns the no-op rerun's result, not the real upgrade (F12) |
| status | `forms/fsa status --json` | 0 | ok | supervisor.running false; last_upgrade = the no-op run |
| rollback w/o --yes | `forms/fsa rollback --json` | 6 | aborted | question + `data.loss` as documented (not confirmed) |
| update-check | `config set update-check on` / `off` | 0/0 | ok | from/to as expected |

## 6. Move to another server — PASS with findings (F2, F6, F5)
| Step | Command | Exit | status | Result |
|---|---|---|---|---|
| 1 passphrase [S] | `$R/secrets/forms-passphrase` (0600, 24 chars) | – | – | – |
| 1 backup [S] | `forms/fsa backup --encrypt --include-secrets --identity-out $R/secrets/forms-identity.age --passphrase-file $R/secrets/forms-passphrase --json` | 0 | ok | encrypted true, includes_secrets true; warnings `backup_on_host`, `backup_identity_written` |
| 2 collect [S] | `mkdir -m 700 $R/forms-move && cp <path>/backup.tar.age … && cp …identity.age … && grep … > $R/forms-move/app-key.env` | 0 | – | app-key.env created 0644 (umask) inside 0700 dir |
| 3 install [S] | `sh install.sh --version 2.0.0-rc.1-test --dir $R/forms2 --from-backup $R/forms-move/backup.tar.age --identity-file $R/forms-move/identity.age --passphrase-file $R/secrets/forms-passphrase --app-key-file $R/forms-move/app-key.env --secrets-file $R/secrets/forms-new-secrets.json --json --yes` | 7 | failed | `backup_newer` (old instance was upgraded to rc.2). Hint says "`fsa upgrade` first" — not possible on a new host; guide's `--version` advice is right (F6). Left a scaffold in `forms2` (F5) |
| 3 retry [S] | same with `--version 2.0.0-rc.2-test` | 0 | ok | url :8082, mode local, from_backup authenticated+encrypted true, webhooks paused, confirm_commands list; warnings `mail_captured_after_move`, `webhooks_paused`, `no_sign_in_link`, `backup_recipients_not_kept` |
| 3 failure paths | identity in missing dir / missing identity / wrong passphrase (into `$R/forms3`) | 2/2/2 | failed | `path_missing`, `identity_unreadable`, `decrypt_failed` — as documented; scaffold left in forms3, `forms3/fsa uninstall --yes --json` -> exit 3 `no_state` (F5); removed with rm |
| confirm 1 | `forms2/fsa config set webhooks on --json` | 0 | ok | paused -> on |
| confirm 2 (as given) | `forms2/fsa config set mail smtp --smtp-host host.docker.internal --smtp-port 55037 --smtp-username forms@example.com --password-file $R/secrets/smtp-password --json` | 5 | rolled_back | `test_mail_failed`: "TLS required…" — the confirm command omits `--smtp-encryption none` and `--from-address` (F2) |
| confirm 2 (fixed) | same + `--smtp-encryption none --from-address forms@example.com` | 0 | ok | test mail accepted; status health ok, rc.2 |
| 4 confirm | `forms/fsa uninstall --keep-backups --json` | 6 | aborted | question, final_backup, keeps_backups |
| 4 uninstall | `forms/fsa uninstall --keep-backups --yes --json` | 0 | ok | removed containers/networks/volumes lists, kept [backups], final_backup object (encrypted, includes_secrets); warning `final_backup_encrypted` |
| 5 delete [S] | `rm -r $R/forms-move && rm $R/secrets/forms-identity.age $R/secrets/forms-passphrase` | 0 | – | – |

## 7. VPS install — PASS (DNS path), scaffold left (F5)
| Step | Command | Exit | status | Result |
|---|---|---|---|---|
| install [S] | `sh install.sh --version 2.0.0-rc.1-test --dir $R/vps --domain fsa-test-r51.invalid --acme-email ops@example.com --org "Example Ltd" --owner-email owner@example.com --secrets-file $R/secrets/vps-secrets.json --json --yes` | 3 | failed | `doctor_failed`; `data.checks[]` entry `{key: dns, status: fail, hint: "Create an A record … run the command again"}`; ports ok. Matches guide |
| leftovers | `ls $R/vps`; docker filters | – | – | no Docker resources; directory with `.fsa-instance fsa locks operations releases` despite "Nothing changed" |
| remove | `vps/fsa uninstall --yes --json`, `vps/fsa status --json` | 3 / 3 | failed | `no_state` ("Run install.sh again…"); no documented way to discard — removed with `rm -r` (F5) |

## 8. Phone demo — static only (not run, per lab rules)
See F15 (`--tunnel-admin` undocumented). Otherwise consistent with `fsa tunnel --help` / `fsa install --help`.

## 9. Change the domain — PARTIAL (F3, F4)
[S] `/opt/form-schema-app` -> `$R/forms2` (local instance, demo data from the move).
| Step | Command | Exit | status | Result |
|---|---|---|---|---|
| w/o --yes | `forms2/fsa config set domain fsa-test-r51.invalid --json` | 6 | aborted | `data` has from, to, change, impact — but no `data.question` (F4) |
| --yes | `forms2/fsa config set domain fsa-test-r51.invalid --yes --json` | 3 | failed | `default_password_in_use` — not documented in this recipe (F3) |
| follow hint [S] | `forms2/fsa demo --rotate-passwords --json --secrets-file $R/secrets/forms2-demo.json` | 0 | ok | 9 `demo_password:<email>` secrets to file |
| --yes again | same as above | 3 | failed | `doctor_failed`, check dns fail with hint; instance still local, health ok. Matches guide |

## 10. Remove an instance — PASS
| Step | Command | Exit | status | Result |
|---|---|---|---|---|
| uninstall | `forms2/fsa uninstall --keep-backups --yes --json` | 0 | ok | removed 8 containers, 2 networks, 4 volumes; kept [backups]; final_backup (unencrypted) + plaintext `final-….app-key.env` beside it, warning `app_key_beside_backup` (F9) |
| (forms) | already removed in move step 4 | – | – | – |

## Cleanup
`rm -rf $R/{forms,forms2,secrets,offhost}` (vps, forms3, forms-move removed earlier). Remaining: `$R/logs/`.
`docker ps -a --filter name=fsa-test-r51` -> only `fsa-test-r51-smtp`. Instance resources were named `fsa-forms-3b35*` /
`fsa-forms2-3571*` (they do not contain `fsa-test-r51`); checked by `fsa-forms` too: no containers, volumes or networks left.
Pulled images kept.

## Lab notes (not guide findings)
- `install.sh --version 2.0.0-rc.2-test` with the normal `FSA_RELEASE_URL` worked (lab serves rc.2 there too).
- Instance names derive from the directory basename, so the `fsa-test-r51` filter does not match instance resources.
