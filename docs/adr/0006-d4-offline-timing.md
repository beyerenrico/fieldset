# 0006: D4 Offline timing — offline-ready API from M2, offline UX in M6
- Status: accepted
- Date: 2026-10-16

## Context
§0 D4 default. Retrofitting offline onto an online-only API is expensive.

## Decision
From M2: client-generated UUIDv7 ids, `PUT` upserts with `revision`, `Idempotency-Key` on submit,
idempotent attachment upload, device sessions and `/auth/device-status`, `/sync/manifest`.
M6 adds Dexie storage, outbox, sync engine and conflict UI.

## Consequences
M2 API tests must cover replay/idempotency even though the M2 UI is online-only.
