# 0007: D5 Database — PostgreSQL 16+
- Status: accepted
- Date: 2026-10-16

## Context
§0 D5 default. `jsonb`, GIN indexes, `ON CONFLICT`, `SELECT … FOR UPDATE` and triggers are used.

## Decision
PostgreSQL only (no MySQL support). Tests run against PostgreSQL (Docker, host port 54329), not
SQLite, so the test suite exercises the real SQL features.

## Consequences
Slower tests than SQLite; mitigated with `RefreshDatabase` transactions and parallel runs.
