# 0009: D7 Languages — UI in DE + EN; one content language per form
- Status: accepted
- Date: 2026-10-16

## Context
§0 D7 default.

## Decision
All UI strings via Laravel `lang/{de,en}` (admin, mail, PDF chrome) and an i18n JSON catalog for
the Field App. User locale overrides organization locale. Form content uses the definition `locale`.

## Consequences
Every new string needs DE and EN entries (DoD §15); a test asserts key parity between locales.
