# Security Policy

## Reporting a vulnerability

Please **do not** open a public issue for security problems. Email the maintainers at
`beyer.enrico@outlook.com` with:

- a description of the issue and its impact,
- steps to reproduce or a proof of concept,
- affected versions or commit.

We acknowledge reports within 3 working days and aim to ship a fix or mitigation within 30 days
for high-severity issues. We credit reporters who want to be named.

## Scope

In scope: the application in this repository, its Docker images and the Form Schema Spec tooling.
Out of scope: third-party services an operator connects (SMTP providers, identity providers).

## Hardening notes for operators

- Keep `APP_DEBUG=false` and set a unique `APP_KEY` in production.
- Run behind HTTPS; set `SESSION_SECURE_COOKIE=true`.
- The PDF renderer (Gotenberg) runs on an internal Docker network with Chromium network access denied.
- Back up the database and storage volume; test restores regularly.
