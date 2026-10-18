# 0054: Capture mail by default with a bundled Mailpit
- Status: accepted
- Date: 2026-10-17

## Context
E8. In 1.0, invitations and "send to customer" need an SMTP account first. Mailpit runs only in the dev overrides
(`compose.dev.yaml:19`, `axllent/mailpit:v1.31`). Organizations can configure their own SMTP server (Appendix C).
Invitations and password resets always go through the instance mailer (`MemberInvitation`; plan, System-Wide
Impact).

## Decision
- Until SMTP is configured, the instance mailer sends to a bundled Mailpit over SMTP, and nothing leaves the
  server (R22). The image is `axllent/mailpit:v1.31.x`, pinned by digest. 1.31.6 is current: GitHub release of
  2026-10-15, Docker Hub tag `v1.31.6`, MIT (verified 2026-10-17). App, queue and scheduler all use it (KTD13).
- Mailpit gets generated `MP_UI_AUTH` credentials, a bounded `MP_MAX_MESSAGES` and `MP_DATABASE` on a volume, and
  its SMTP port is never published (KTD13). `MP_UI_AUTH`/`MP_UI_AUTH_FILE`, `MP_WEBROOT` and `MP_ALLOWED_HOSTS`
  were verified through Context7 (Appendix A); `MP_MAX_MESSAGES` and `MP_DATABASE` come from the plan's sources
  (mailpit.axllent.org/docs) and were not re-checked here.
- UI access:
  - local and proxy modes: a loopback port;
  - domain mode: `/_mail/` behind the edge, with the catcher credentials plus `forward_auth` requiring a
    `/platform` session with MFA. Every opening is audited as `catcher_opened` (plan R66, which strengthens R23).
- Banner, as narrowed by plan R59 (changes R24 and AE1). It is non-dismissible and uses a Filament `BODY_START`
  render hook (KTD14).
  - Organizations on the instance mailer see "Mail is captured, not delivered".
  - Organizations with their own SMTP see a narrower banner saying that invitations and password resets are
    captured, because those always use the instance mailer.
  - Org admins see "ask your instance owner" and a Mail settings link. Only operators see `fsa config set mail`.
- `fsa config set mail` reads the password from stdin or a file and sends a test mail before switching. The
  catcher volume and UI stay read-only until `--remove-catcher` confirms. `--capture` switches back (R25, plan
  U12). The health check reports capture mode as a warning.

## Alternatives considered
- **Require SMTP at install:** slows the install and blocks agents that have no mail credentials.
- **Log-only mailer:** mail is silently not delivered, and invitation links are lost.

## Consequences
- The catcher holds live account-recovery links for every tenant. That is why domain mode gates it behind MFA and
  the other modes keep it on loopback.
- Unverified: whether Mailpit's UI, including its live-update connection, works behind `forward_auth` (plan Open
  Questions). The fallback is loopback-only access in domain mode too. (Amended 2026-10-18: verified. Mailpit
  works fully under `/_mail/` behind Caddy's `forward_auth`, including the live-update websocket (spike, LEARNINGS
  103), and domain mode serves it there (ADR 0075), so the fallback is not needed. Live updates over a real network
  path stay on the human checklist, ADR 0075.)
- Mailpit is no longer dev-only, which amends ADR 0020. The install smoke test sends one mail into the catcher
  (R11).
- Sending invitations and resets through an organization's own SMTP, which would remove the narrower banner, is
  follow-up work.
