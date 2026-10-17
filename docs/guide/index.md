# form-schema-app documentation

form-schema-app is a free, self-hostable platform for digital forms. Office staff design forms in the
admin dashboard (`/admin`); field workers fill them in on a phone or tablet with the Field App (`/app`),
also offline; the server numbers each submission, renders a branded PDF and runs workflows such as a
review followed by an email to the customer.

These guides describe the current release. They are plain Markdown and work offline in a clone of the
repository (ADR 0042).

## For operators

| Guide | Contents |
|---|---|
| [Install](install.md) | Production install with Docker Compose, the `.env` settings, the first organization and platform operators, reverse proxy, trusted proxies and TLS, protecting the audit log |
| [Upgrade](upgrade.md) | Upgrading from 0.x to 1.0: pre-flight checks, migrations, Horizon, the Field App update, rollback |
| [Backup and restore](backup-restore.md) | What to back up, `APP_KEY`, `scripts/backup.sh`, `scripts/restore-drill.sh`, a full restore |
| [Configuration reference](configuration.md) | Every environment variable and its default |
| [Troubleshooting](troubleshooting.md) | HTTPS and host names, PDFs, mail, queue, scheduler, Field App sync, SSO, login throttling, upload scanning, the audit log |

## For organizations

| Guide | Contents |
|---|---|
| [Admin guide](admin-guide.md) | Roles, members and groups, forms and both builders, designs, submissions and reviews, workflows, reports and dashboards, mail settings, single sign-on, API tokens, the audit log, retention, legal holds and personal data, the `/platform` operator panel |
| [Field App guide](field-app-guide.md) | Installing the app, signing in, filling in and submitting forms, offline use and sync states, sending the PDF, reviews, keyboard use, personal data requests |

## For developers and integrators

| Document | Contents |
|---|---|
| [API and integrations](api.md) | The REST API, the OpenAPI document at `/docs/api`, webhooks and signature verification |
| [Field App API contract](../api/field-app-v1.md) | The normative `/api/v1` contract |
| [Form Schema Spec 1.0](../../spec/form-definition/1.0/README.md) | The open JSON format for form definitions (MIT) |
| [Architecture decisions](../adr/) | One ADR per decision |
| [README](../../README.md) | Development setup, demo data, checks |
| [Changelog](../../CHANGELOG.md) and [release notes](../releases/) | What changed in each version |
| [Security policy](../../SECURITY.md) | How to report vulnerabilities |

## Terms used in these guides

| Term | Meaning |
|---|---|
| Organization | A company using the instance. Every record belongs to exactly one organization. |
| Form / form version | A form definition. Publishing creates an immutable version; submissions keep the version they were filled with. |
| Submission | One filled-in form, from draft to completed. The server assigns its number on submit. |
| Rendition | A PDF created from a submission with a design. PDFs are never changed; re-rendering creates a new one. |
| Design | Paper size, header, footer and layout of the PDFs, versioned like forms. |
| Workflow | A trigger, optional conditions and steps such as a review, an email or a webhook. |
| Field App | The installable web app for phones and tablets at `/app`. |
