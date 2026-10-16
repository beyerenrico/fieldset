# 0026: Own `attachments` table instead of spatie/laravel-medialibrary for submission files
- Status: accepted
- Date: 2026-10-16

## Context
§9.2 requires client-generated attachment UUIDs and idempotent uploads (offline outbox replays).
medialibrary assigns its own ids/uuids, attaches media to models via morphs and runs conversions;
idempotency by a client uuid and per-tenant private paths would fight its conventions.

## Decision
`attachments` (uuid primary key = client uuid, organization, submission, kind, element key, sniffed
MIME, size, sha256, disk, path). `StoreAttachment` validates by content sniffing and an allow-list,
re-encodes images with GD (EXIF removed), stores under `attachments/{org}/{submission}/{uuid}.{ext}` on
the private disk, and returns the existing record for a repeated uuid. medialibrary stays available
for organization branding assets (M3) where its conversions help.

## Consequences
Downscaling for print happens at render time (M3). An optional ClamAV hook can wrap `UploadInspector`.
