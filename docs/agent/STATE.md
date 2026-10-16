# State: 2026-10-16T17:00Z
Milestone: M2 Field App online · Task: M2-T01 Devices
Status: M1 merged to main (tag m1-done). Spec 1.0 + 390 conformance cases, both SDKs, forms domain,
Builder v1 done. M2 breakdown is in PLAN.md (M2-T01..T18).
Branch: m2-field-app · Last commit: see `git log -1`

## Next actions
1. M2-T02 submissions schema first (devices depend on users only, submissions on forms):
   submissions (client uuid PK), submission_events, attachments, number_sequences.
2. M2-T03 state machine (spatie/laravel-model-states — install, verify v2.14 API in vendor).
3. M2-T01 devices + device-status; then API endpoints M2-T05..T10.
4. Field App (M2-T12..T14) can be delegated to a subagent once the API contract exists
   (renderer goes into packages/form-schema-js/src/react or a sibling package).

## Files in flight
- none

## Known problems
- None open. LEARNINGS 8–15 cover tooling gotchas (composer during tests, pcov, Livewire limits).

## Resume commands
docker compose up -d postgres redis gotenberg mailpit
php artisan test --compact            # or scratchpad t.sh wrapper
npm run conformance && npm test -w packages/form-schema-js
./scripts/coverage.sh                 # coverage gate (pcov in docker/ci image)
