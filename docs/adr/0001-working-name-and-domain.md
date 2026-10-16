# 0001: Working name `form-schema-app` and domain `localhost`
- Status: accepted
- Date: 2026-10-16

## Context
BUILD_PROMPT uses `{{APP_NAME}}` and `{{APP_DOMAIN}}` placeholders that were left unresolved.

## Decision
Use `form-schema-app` as the application name and `localhost` as the domain. The meta-schema
`$id` is `https://localhost/spec/form-definition/1.0/meta-schema.json`; `APP_NAME` and `APP_URL`
are env-driven so operators can change them.

## Consequences
Renaming later touches `config/app.php` defaults, docs and the meta-schema `$id`. Logged in QUESTIONS.md.
