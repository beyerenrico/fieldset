From U13 (merged, new ADR 0081):
- 0055: `fsa demo` works on an installed instance; a new local demo is `install.sh --demo` (still needs --org/--owner-email); the loopback default password `password` is public (data.demo.default_password, not a secret); random demo passwords listed once as secrets `demo_password:<email>`.
- 0072: install phase table gains `demo` (after start, before smoke); `--demo` no longer unsupported_option.
- 0070: wrapper command list gains `demo`.
- R30/§7 wording: `fsa demo` has no `--phone-https`; deviation recorded in ADR 0081.
From U12 (merged, new ADR 0080):
- 0054: catcher removed only with `--remove-catcher` (not on the switch); capture is `config set mail capture`; banner shown to all admin-panel members (operators get the fsa command, managers the Mail settings link); "read-only" = the kept catcher receives nothing (its SMTP listener on its own loopback), Mailpit's UI can still delete.
- 0077: key table gains `mail` and `operator-email`; SettingHooks verify/complete steps; `test_mail_failed` (exit 5).
- 0078: the completion step runs outside the journal phases; previous SMTP credentials stashed in `env/smtp-previous` (0600) for revert.
- 0069: `FSA_MAIL_CAPTURE` in the app-role env; `MP_SMTP_BIND_ADDR` for the kept catcher.
- 0075: `/_mail/` stays while the catcher is kept.
- 0076: the stack file has an overridable `FSA_MAIL_CAPTURE` (stack users set it false when configuring SMTP).
From U14 (merged, new ADR 0082):
- 0057: the app serves the trust page; cloudflared is untrusted; `config set phone-https local-ca` refuses while demo accounts have `password` (tunnel start rotates).
- 0074: reverse the "list cloudflared in TRUSTED_PROXIES" consequence; the runtime directory also holds `local-ca-root.crt`.
- 0075: the edge also renders in local-CA mode; `EdgeNetwork::choose` takes a pool; the tunnel network uses 10.215/16.
- 0077/0078: new keys `phone-https` and `lan-address`; the tunnel cleanup runs before `config set`.
- 0081: `app:demo-passwords --check` also returns `organizations {total, non_demo}`.
- 0070/0072: the `tunnel` command, `--phone-https`, `--lan-address`, the doctor's `lan_address` check, `FSA_TEST_TUNNEL`.
- 0069: the tunnel service with `restart: "no"`.
