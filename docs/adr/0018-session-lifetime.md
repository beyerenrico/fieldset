# 0018: One long rolling session lifetime (30 days)
- Status: accepted
- Date: 2026-10-16

## Context
§9.3: the Field App needs long rolling sessions; Laravel defaults to 120 minutes. Admin and Field
App share the same domain and session cookie.

## Decision
`SESSION_LIFETIME=43200` (30 days, rolling, encrypted sessions) for the whole app, configurable via
env. Sensitive admin actions use password confirmation; device status (§9.3) handles expiry offline.

## Consequences
Admin sessions are long too. Operators can lower `SESSION_LIFETIME`; the Field App copes with
expiry via `/auth/device-status` and re-login without data loss.
