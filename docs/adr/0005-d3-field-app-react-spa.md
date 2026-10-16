# 0005: D3 Field App — React + TypeScript offline-first PWA
- Status: accepted
- Date: 2026-10-16

## Context
§0 D3 default. Offline support dominates the field app architecture. Inertia and a second
Filament panel cannot work offline; NativePHP needs paid plugins (conflicts with principle 5).

## Decision
`resources/field-app` is a Vite + React 19 + TS strict SPA served by Laravel at `/app` (base
`/app/`, SW scope `/app/`), talking to `/api/v1` with Sanctum SPA cookies. The renderer lives in
`packages/form-schema-js` and is shared with the builder preview (`preview.html` entry, no SW).

## Consequences
Two UI stacks (Filament + React). The renderer must be framework-light enough for both hosts.
