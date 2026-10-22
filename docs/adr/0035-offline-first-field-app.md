# 0035: Offline-first Field App (Dexie store, outbox, sync engine, service worker)
- Status: accepted
- Date: 2026-10-17

## Context
§9.2 and §9.3, and journeys 3 and 7. M2 shipped an API that was ready for offline use (ADR 0006, 0027), but the client was online-first:
- Autosave sent a PUT directly (`src/lib/autosave/api-transport.ts`).
- Submit and send keys lived in sessionStorage.
- IndexedDB held only the device record (the M2 `kv` store).
- Starting the app required a live `GET /me` (`routes/router.tsx`).

Versions, checked on the npm registry on 2026-10-17: dexie 4.4.6 (Apache-2.0), vite-plugin-pwa 2.0.0 (MIT), workbox-build and workbox-window 7.4.1 (MIT), fake-indexeddb 6.2.6 (tests).

## Decision
### Store
- One Dexie database, `fsa-offline` (`src/lib/db`). It has these tables:
  - `drafts` (uuid; [orgId+userId], updatedAt)
  - `outbox` (id; submissionUuid, [orgId+userId], seq, nextAttemptAt)
  - `attachments` (uuid; submissionUuid, state)
  - `forms` ([orgId+id]; orgId)
  - `formVersions` (id; orgId, [orgId+form_id])
  - `meta` (key: device, session, sync, seq, catalog:<orgId>)
- Every row carries orgId and userId, and every read filters by both. Another user who signs in on the device sees none of these rows, and nothing is ever sent under their session.
- File bytes are stored as ArrayBuffer plus a MIME type, not as a Blob. This works in older Safari and with fake-indexeddb under jsdom.
- A schema change adds `db.version(n)` with an upgrade. A shipped version is never edited.
- The M2 database `form-schema-field-app` held only the device record. `migrateLegacyStore()` moves that record into `meta.device` and deletes the old database.

### Local-first autosave
- The AutosaveController keeps its API and rules: one write in flight, latest data wins, stop and dispose. DraftSession, WorkTracker, conflict-diff and ConflictPanel stay as they are.
- What changes:
  - The transport now writes the DraftRow (data, localVersion + 1) and coalesces a `draft.save` op.
  - Debounce is 300 ms, with a maxWait of 2 s.
  - Writes are flushed on `pagehide`, `freeze` and `visibilitychange` to hidden.
  - Controller states now mean "saved on this device". Sync state comes from DraftRow.sync.
- A reopened draft starts from the local row when that row is newer than the server copy.
- When the session is lost, local writes are flushed before the sessions are disposed.

### Outbox
- Op ids have the form `${kind}:${uuid}[:${attachmentUuid}]`, so enqueueing the same op again coalesces it. There are four kinds:
  - `draft.save`: the body is read when the op is sent.
  - `attachment.upload`: one per attachment.
  - `submit`
  - `send`
- Order within one submission:
  1. `draft.save`, to create the draft on the server (uploads need an existing, editable draft).
  2. Every `attachment.upload`.
  3. `draft.save` again, to update with revision = serverRevision.
  4. `submit`
  5. `send`
- `submit` and `send` persist `{ idempotencyKey, body }`, so a retry sends the same bytes. A new submit revision gets a new key. Submit carries `client_submitted_at`.
- Each request sends the row's orgId as `X-Organization-Id`, set per request.
- Retries back off as min(15 min, 5 s·2^(n−1)) plus jitter. They apply to network errors, 5xx, 408 and 429 (Retry-After is honoured). The backoff resets on success, on `online` and on "Sync now". Nothing is ever dropped.
- These answers block the submission until the user decides:
  - 409 `revision_conflict`, unless the response can be adopted
  - `not_editable`, `version_retired`, `version_mismatch`, `attachment_conflict`
  - 403 (form access revoked), 404, 413
  - 422 on submit: the draft becomes editable again, with pointer errors
  - 422 on upload: the attachment is marked failed and the user replaces it
- 401 and 419 go through `device-status`:
  - `ok`: retry.
  - `reauth`: the engine pauses and the outbox is left untouched.
  - `wipe`: clear everything.
- 403 `organization_forbidden`: refetch `/me`. If the user no longer belongs to that organization, purge its rows.

### Sync engine
- Triggers:
  - app start
  - the `online` event
  - `visibilitychange` to visible
  - an enqueue
  - "Sync now"
  - login
  - every 60 s while the app is visible and the outbox is not empty
  - a message from the service worker
- Only one tab syncs at a time. It holds the Web Lock `fsa-sync` (requested with ifAvailable), and other tabs skip the run. Where Web Locks are missing, a mutex inside the tab is the fallback.
- Requests travel between tabs over a BroadcastChannel (`src/lib/sync/triggers.ts`).
- Background Sync is a wake-up on Chromium only: when an op is enqueued offline, the app registers the tag `fsa-outbox`, and the service worker's `sync` handler posts to open clients. In M6 there is no executor inside the service worker (QUESTIONS #13).

### Conflicts
- A 409 whose server data equals the data that was sent is a lost response, and it is adopted.
- Any other 409 sets the draft to `conflict` and stores the server copy. That submission's ops pause, and a queued submit waits. The user chooses:
  - "Keep mine": serverRevision is set to the conflict revision and the local data is pushed again.
  - "Use server": the local data becomes the server data.
- `not_editable` blocks the draft and offers "discard" or "save as a new draft".
- `version_retired` offers "copy to the current version" (a new uuid; keys are stable, §7.6).

### Catalog cache
- `meta['catalog:<org>'].cursor` drives `GET /sync/manifest?since=`, which is inclusive and upserts by id.
- A fillable form id that is missing locally, or whose checksum changed, is fetched through `GET /forms/{f}/versions/{v}`. The cursor is saved only after every fetch has succeeded.
- Forms that are not in `fillable_form_ids` are purged. A version stays cached while a local draft still uses it.
- Catalog queries read the cache first and the network second. Offline with nothing cached raises `NotCachedError`.

### Service worker (vite-plugin-pwa)
- Mode and scope: generateSW, registerType `prompt`, scope and base `/app/`. `preview.html` stays on the separate preview build in `/form-preview/`, which has no plugin, no Dexie and sits outside the scope.
- Precache: JS, CSS, fonts, icons and the web manifest. The app shell is fetched through the Laravel catch-all, so it keeps the CSP that SpaController sets; the static `public/app/index.html` is never precached on its own.
- Navigation and API: the navigation fallback is the shell. API paths, Sanctum and auth are denylisted, and API responses get no runtime caching.
- Updates: a new version shows a banner. When the user accepts, open drafts are flushed and the new worker is activated. A Dexie `versionchange` in an old tab shows a reload banner.

### Persistence and install
- `navigator.storage.persist()` is called after the first login. Settings shows `estimate()` and whether storage is persisted.
- iOS outside standalone mode shows install instructions. While unsynced items exist, it also warns that iOS deletes data after 7 days without use, and that home-screen apps are exempt (WebKit). On Chromium, `beforeinstallprompt` drives an Install button.

### Logout, wipe, reauth
- **Logout:** count the unsynced items (ops, dirty drafts, pending attachments), warn, and offer "Sync now". Then:
  1. POST `/auth/logout` (best effort).
  2. Clear all Dexie tables, keeping `meta.device` with the uuid only.
  3. Delete all caches and unregister the service worker.
  4. Keep only `fsa.settings` in localStorage.
  5. Reload to the login page.
- **Wipe:** the same, but without the device uuid, without a warning and without syncing.
- **Reauth:** nothing is cleared, and login requests a sync. If a different user signs in, the previous user's items stay hidden and unsent (QUESTIONS #15).

### Backend
- Deactivating a membership ends the member's sessions. When the member has no other active membership left, it also sets `wipe_requested_at` on their devices. Before this change, only the API tokens were deleted.
- Customer send is idempotent by key on `document_sends`, so it still holds after stored idempotency keys are pruned (24 h). Without this, a lost response replayed more than 24 h later would email the customer twice.
- Submit accepts `client_submitted_at`, which is stored and displayed. Number, period, `submitted_at` and `$today` stay on server time (QUESTIONS #14).

## Consequences
- The device is the source of truth for unsynced work; a lost device loses that work.
- The server stays authoritative for numbers, calculations and time.
- Many M2 screen tests that assumed a server PUT on autosave are rewritten.
- The ownership split for the parallel agents and the frozen shared modules are recorded in `docs/agent/milestones/M6.md`.

## Amendment 2026-10-18: implementation notes (M6 agents B and C)
- **Shell URL.** The service worker precaches index.html only under `/app/shell`, which is also its navigation fallback.
  - No static file answers `/app/shell`, so Laravel's catch-all serves it with SpaController's CSP.
  - The Caddyfile also rewrites `/app/index.html` and `/form-preview/preview.html` to Laravel. Before, those two URLs were served statically without the CSP; `/app/` and `/form-preview/` already went through Laravel.
- **Background Sync script.** `sw-sync.js` is pulled in with `importScripts` and a content hash. The preview build sets `publicDir: false`, so no icons or SW files reach `/form-preview/`.
- **Photos.** They are compressed once, by the renderer's AttachmentField, before the adapter stores them. There is no GPS opt-in setting yet, so GPS is always stripped by the canvas re-encode. Files the browser cannot decode (e.g. HEIC outside Safari) keep their EXIF locally, and the server strips it on upload.
- **Catalog cache.** It is kept per organization, not per user, because forms are not user data.
  - The forms list reads the cache only once a refresh has completed; before that it calls `GET /forms`, which purges revoked favourites at once.
  - Queries use `networkMode: 'offlineFirst'`.
- **Manifest.** It now returns `retired_version_ids`: the versions of fillable forms retired since the cursor. A version retired on a form that is no longer fillable shows up as a 409 `version_retired` on sync.
- **Membership deactivation** goes through `EndUserAccess`, shared with DeactivateUser:
  - It deletes the user's sessions and `remember_token`; the Field App logs in with "remember me".
  - When no other active membership remains, it sets `wipe_requested_at` on the user's devices. The wipe stays even if the member is reactivated (QUESTIONS #16).
- **`organization_forbidden`.** On this 403 the client refetches `/me`:
  - If the organization is gone, it purges that user's rows for the organization and the organization's catalog.
  - If no organization is left, it asks device-status and wipes on `wipe`.
- **Durable send idempotency.** `document_sends.idempotency_key` is unique per organization, submission and key. A replay returns the existing send with 202, without a new row, without counting against the cap and without a second mail.
- **`client_submitted_at`.** It must be ISO 8601 with an offset and at most 5 minutes in the future; otherwise the answer is 422 with pointer `/client_submitted_at`. A repeated submit leaves it unchanged. The admin submission view shows it when present.
- **Offline start.** The last `/me` is kept in meta `session`. A network error, a 502/503/504, or no answer within 8 s starts the app from that cached session. A cached session counts as stale, so it is refetched when the connection returns.
  - Login always fetches `/me` fresh. If that fetch fails, the cached session is dropped, so another user's session can never be opened.
- **Logout and wipe.** Only `fsa.*` localStorage keys are cleared, because the admin panel shares the origin. `settings` and `pwa.installDismissed` are kept. An offline logout clears everything but does not reload, which would show the browser's offline page.

## Amendment 2026-10-18: M6 gate review fixes
- **Op ids** include the user: `${kind}:${userId}:${uuid}[:${attachmentUuid}]`.
  - Before every run and every submission, the engine checks that the in-memory user equals `meta.session.userId`. Otherwise it pauses as `stale` and sends nothing. A tab left open from a lost session therefore never sends under another user's session.
  - The local transport refuses writes when the session is gone or belongs to someone else.
- **One editor per draft.** The tab that opens a draft holds the Web Lock `fsa-draft:<uuid>`. Other tabs show the draft read-only. Without Web Locks the behaviour is as before.
- **Lost answers.** Before each PUT the row records `{revision, localVersion, hash}`. A 409 that matches a recorded save with revision + 1 is adopted, and the current data is sent again.
  - A lost submit answer is recognised by `client_submitted_at` and `submitted_at`, and the submit is finished instead of offering a duplicate.
  - Edits written after the draft PUT are sent before the submit. `applySubmitted` marks only the version the submit covered.
- **Decisions surface.** A background refusal or a blocked upload marks the draft blocked:
  - 422 `validation_failed` → `submit_refused` (with the pointer errors);
  - 422 `attachments_missing`, after one automatic re-upload → `attachments_missing`;
  - a refused upload → `attachment_blocked`.

  The draft's queued submit and send ops become `blocked` with a reason. Lists and the detail screen link to the review screen. A new submit lifts these three codes.
- **The retired-version pre-block** applies only to drafts. A `changes_requested` resubmission goes to the server, which accepts it.
- **Send/submit ops** are deleted, blocked or backed off only while the stored idempotency key equals the one sent.
- **`meta.sync.state`:**
  - `offline` after a network error, and the header then says "No connection";
  - `idle` after any server answer;
  - `error` while a server error is retried.
- **Session safety:**
  - A logout that cannot reach the server leaves `fsa.pendingLogout`. Until the server logout succeeds, the app treats itself as signed out and does not call `/me`.
  - A reauth is remembered in `meta.session.reauth`, so an offline restart does not open the cached session.
  - Each fresh `/me` purges rows and catalogs of organizations the user lost.
  - The catalog meta records its user.
  - Logout and wipe broadcast first, end the session, wait 150 ms for other tabs, and then purge.
- **Device revoke** also ends all of the user's sessions and the remember-me token. A session re-created from the remember-me cookie is not bound to the device, so the revoked device could not otherwise reach device-status (QUESTIONS #9).
- **UX:**
  - The header shows pending work only offline, or after 10 s pending with no sync running. Decisions show at once.
  - Only one banner shows at a time (reload > update > iOS warning > install), and install banners are hidden while filling.
  - Restored drafts start as "Saved on this device". "Storage full" is a typed error, announced once.

## Amendment 2026-10-22: the shell has no session
- **Why:** CI run 39747027552 failed an SSO sign-in in the Field App. The worker's background fetch of `/app/shell`
  (34 ms) read the session before the login page's `POST /auth/sso/discover` saved the SSO start token, and saved it
  back 3 ms later; the redirect found no start token (`start_missing`, shown as `sso_failed`). Sessions use the
  database driver without locking, so the later write wins. A user who types fast or autofills can hit it on the first visit.
- **Change:** `app/shell` is its own route (`field-app.shell`) without the session, cookie, CSRF, locale and device
  middleware, like the phone trust page. It serves the same static page with the same CSP. Every other `/app/*` route keeps
  the web group, so impersonation and device checks still run on navigations.
- **Test:** `tests/Feature/Smoke/SpaRoutesTest.php` (no session and no cookie on the shell; a client route has a session).
