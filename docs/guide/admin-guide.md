# Admin guide

This guide is for the people who run an organization in the admin dashboard at
`https://<your server>/admin`: owners, administrators, form designers, reviewers and viewers. Field
workers use the Field App instead; see the [Field App guide](field-app-guide.md).

The dashboard is available in German and English (each organization and user has a language). This
guide quotes the German labels as the dashboard shows them, with the English label in brackets.

Contents:
- [Roles and what they may do](#roles-and-what-they-may-do)
- [Your profile and two-factor authentication](#your-profile-and-two-factor-authentication)
- [Organization settings and branding](#organization-settings-and-branding)
- [Members, groups and roles](#members-groups-and-roles)
- [Forms](#forms)
- [Document designs](#document-designs)
- [Submissions and reviews](#submissions-and-reviews)
- [Workflows and webhooks](#workflows-and-webhooks)
- [Notifications](#notifications)
- [Reports, exports and dashboards](#reports-exports-and-dashboards)
- [Email settings and the mail log](#email-settings-and-the-mail-log)
- [Mail capture banner](#mail-capture-banner)
- [Single sign-on (SSO)](#single-sign-on-sso)
- [API tokens](#api-tokens)
- [Audit log](#audit-log)
- [Privacy and data retention](#privacy-and-data-retention)
- [Platform operators (/platform)](#platform-operators-platform)

## Roles and what they may do

Every organization starts with six roles. A member can hold several roles; their permissions add up.

| Role (German / English) | Typical person | Admin dashboard |
|---|---|---|
| "Inhaber" / Owner | Business owner | Everything, including SSO connection settings |
| "Administrator" / Admin | Office manager | Everything except deleting the organization and the Owner-only SSO settings |
| "Formulargestalter" / Designer | Builds forms | Forms, designs, workflows, reports; submissions of their own forms |
| "Prüfer" / Reviewer | Checks submissions | Review tasks, reports; submissions assigned to them or their group |
| "Außendienst" / Field Worker | Fills in forms | No access to the admin dashboard; uses the Field App |
| "Betrachter" / Viewer | Reads only | All submissions and reports, read-only |

An office coordinator who designs forms and reviews submissions gets Designer and Reviewer.

What each area needs:

| Area | Owner | Admin | Designer | Reviewer | Viewer |
|---|---|---|---|---|---|
| Organization settings, branding, email settings, mail log | yes | yes | – | – | – |
| Members, groups, roles, SSO page | yes | yes | – | – | – |
| SSO connection and security settings | yes | – | – | – | – |
| Forms, designs, versions, form access | yes | yes | yes | – | – |
| Workflows, webhook endpoints | yes | yes | yes | – | – |
| Submissions inbox | all | all | own forms | assigned or group | all |
| Approve / request changes, "Meine Aufgaben" | yes | yes | – | yes | – |
| Submission explorer, saved reports, exports | yes | yes | yes | yes | yes |
| Audit log; retention, legal holds, personal data of members | yes | yes | – | – | – |
| Own API tokens, "Meine Daten" ("My data") | yes | yes | yes | yes | yes |

Reports and exports only ever contain submissions the user may see. Owners and Admins can fill in
every form in the Field App; everyone else needs a form access grant (see [Forms](#forms)).

You can create your own roles under "Team" → "Rollen" ("Roles"): give the role a name and tick its
permissions ("Berechtigungen"). You can only grant permissions you hold yourself. The system roles
cannot be renamed or deleted, and the Owner role cannot be changed.

## Your profile and two-factor authentication

Open the user menu (top right) → "Profil" ("Profile") to change your name, email address or password.
A new email address must be confirmed from that address. Addresses managed by single sign-on cannot be
changed here.

Two-factor authentication: in the profile, "Authenticator-App" → "Einrichten" ("Set up"), scan the code
with an authenticator app and keep the recovery codes. The same code works for the admin dashboard and
the Field App. "Wiederherstellungscodes neu generieren" ("Regenerate recovery codes") replaces the
codes. Two-factor authentication is optional, except for the break-glass Owner of an organization
that enforces SSO.

The dashboard signs you out after 30 minutes without activity, and 12 hours after you signed in at the
latest (the operator can change both). The Field App keeps you signed in for 30 days.

## Organization settings and branding

**Organization settings.** The organization menu → "Organisationseinstellungen" ("Organization
settings"): name, language and time zone. Scheduled workflows and report emails use this time zone.

**Branding** ("Formulare" → "Branding"): applies to PDFs and emails.
- "Logo" (PNG, JPEG or WebP, up to 2 MB by default), "Primärfarbe" ("Primary color"), "Akzentfarbe"
  ("Accent color") and "Schrift" ("Font": Inter, Source Sans 3, Noto Sans or Noto Serif). The page
  warns when a color has too little contrast.
- "Firmenangaben" ("Company details"): company name, address, email, phone, website, tax number,
  VAT ID, bank, IBAN, BIC and a legal footer.

**Several organizations.** A person can belong to several organizations. The organization switcher at
the top of the dashboard lists those where they have dashboard access. The first organization is
created at the install (`fieldset install --org …`); further organizations can only be created through
self-registration ("Organisation registrieren", "Register organization"). Self-registration is off on an
instance installed with `fieldset`; on a 1.0 checkout install the operator can enable it (see the
[1.0 configuration reference](archive/1.0-configuration.md#application)).

## Members, groups and roles

**Invite a member.** "Team" → "Mitglieder" ("Members") → "Mitglied einladen" ("Invite member"): email,
name, roles (at least one) and groups. The invitation email contains a link that is valid for 7 days.
Status values: "Eingeladen" ("Invited"), "Aktiv" ("Active"), "Deaktiviert" ("Deactivated").

**Invite many members.** "CSV importieren" ("Import CSV") checks the file first ("Prüfen", "Check")
and then sends the invitations ("Einladungen senden"):
- a header row with the columns `email` and `roles` (required) and `name`, `groups` (optional);
- `;` or `,` as separator; several roles or groups in one cell separated by `|`;
- roles by key or by their German or English name; groups by name;
- UTF-8, at most 500 rows and 512 KB. Each organization may send up to 1000 invitations a day (the
  operator can change this); a file that would exceed the limit is refused as a whole;
- people who were already invited get the invitation again; rows that were skipped or failed are
  listed in a notification afterwards.

```csv
email;name;roles;groups
anna@example.com;Anna Schmidt;Außendienst;Montage Nord
ben@example.com;Ben Weber;Formulargestalter|Prüfer;
```

**Change a member.** Edit the member to change roles, groups and the default group ("Standardgruppe").
You cannot change your own roles. Only an Owner can assign the Owner role.

**Deactivate.** "Deaktivieren" ("Deactivate") ends the member's sessions. If they have no other
organization, their Field App installations delete their local data at the next contact. The last
Owner cannot be deactivated. Members are never deleted; "Reaktivieren" ("Reactivate") restores access.

**Lost device.** "Geräte sperren" ("Revoke devices") signs out every Field App installation of that
member. Each device deletes its local data at its next contact.

**Groups** ("Gruppen") are crews or departments. They decide who may fill in which form, who gets a
review task and who is notified. The option "Mitglieder sehen gegenseitig ihre Einreichungen"
("Members see each other's submissions") lets group members see each other's submissions in the Field
App.

## Forms

"Formulare" → "Formulare" ("Forms") lists every form with its status ("Entwurf" draft, "Aktiv" active,
"Archiviert" archived) and current version.

### Create a form

- **"Neues leeres Formular"** ("New blank form"): name and category.
- **"Aus Vorlage"** ("From template"): "Servicebericht" (service report), "Rechnung" (invoice),
  "Prüfprotokoll" (inspection checklist), "Lieferschein" (delivery note), "Stundenzettel" (timesheet)
  or "Übergabeprotokoll" (handover protocol).
- **"JSON importieren"** ("Import JSON"): a form definition file in the Fieldset Spec format (up to
  2 MB), uploaded or pasted.
- **"Aus PDF importieren"** ("Import from PDF"): a fillable PDF (AcroForm fields, up to 20 MB and 50
  pages). The import runs in the background; the notification links to the new draft ("Entwurf
  öffnen"). Numbered table rows become one list without a row limit, Acrobat sums become calculated
  fields, and required flags, tooltips and dropdown options carry over. Values already filled into the
  PDF are not imported. The builder shows a "PDF-Importbericht" ("PDF import report") with every field
  it could not map, and marks uncertain elements with ⚠. Nothing is published automatically.

Row actions in the list: "Editor" (opens the form in the default builder, see below), "Klassischer
Editor" ("Classic builder"), "Duplizieren" ("Duplicate"), "JSON exportieren" (the published version),
"Auswerten" ("Explore", opens the submission explorer) and "Archivieren" / "Wiederherstellen" ("Archive" /
"Restore"). Clicking a row also opens the default builder. Forms are not deleted.

### Two builders

Both builders edit the same draft; you can switch at any time.

- **Canvas builder** ("Canvas-Builder", the default): a palette of element types, the form canvas and a
  properties panel ("Eigenschaften"). It saves automatically within a few seconds and has undo and redo
  ("Rückgängig", "Wiederholen"). Everything works with the keyboard; "Tastenkürzel" ("Keyboard
  shortcuts") lists the keys, for example Space to pick up an element, the arrow keys to move it,
  Shift+F10 for the move menu, Alt+P for the properties and Ctrl/Cmd+Z to undo. "Klassischer Editor"
  ("Classic builder") in its toolbar switches to the classic builder.
- **Classic builder** ("Klassischer Editor"): pages with element blocks, reordered by drag and drop.
  Tabs: "Editor", "Formulareinstellungen" ("Form settings"), "Vorschau" ("Preview"), "Wer darf dieses
  Formular ausfüllen" ("Who may fill this form") and "Roh-JSON" ("Raw JSON"). It saves the draft every
  20 seconds. "Canvas-Builder" in its header switches back.

New forms, the list, the search and the dashboard open the canvas builder. A form imported from a PDF
opens in the classic builder as long as its "PDF-Importbericht" ("PDF import report") is shown, because
only the classic builder shows the report.

Some things are in the classic builder only: form access ("Wer darf dieses Formular ausfüllen": tick the
groups and roles that may fill in the form), the "Versionen" ("Versions") tab with compare and rollback,
the PDF import report, and the form's audit history ("Verlauf").

If the draft was changed in another tab or by a colleague, the builder says so and offers to reload.

### Logic and calculations

Each element has a "Logik" ("Logic") section: "Nur anzeigen, wenn" ("Show only if"), "Pflichtfeld,
wenn" ("Required if") and "Schreibgeschützt, wenn" ("Read-only if"). Build conditions from rules such as
"ist gleich" ("equals"), "ist leer" ("is empty"), "größer als" ("greater than") or "ist angekreuzt"
("is checked"), combined with AND/OR. "Erweitert (JsonLogic)" accepts a raw expression.

Calculated fields offer guided types ("Berechnungsart"): the sum of a column in a list, multiplying,
adding or subtracting fields, a percentage of a field, or JsonLogic. Money is calculated exactly, without
rounding errors, and the Field App shows the same result as the PDF.

### Form settings

"Formulareinstellungen" ("Form settings"):
- "Formularsprache" ("Form language") and "Layout": one long page, or one page at a time (wizard).
- "Dokumentdesign" ("Document design"): the key of the design for the PDF; empty uses the default
  design.
- "Nummerierungsformat" ("Numbering format"), for example `SR-{YYYY}-{SEQ:5}`. Tokens: `{YYYY}`,
  `{YY}`, `{MM}` and `{SEQ:n}` (a counter with n digits). Without a format, numbers look like
  `000042`. "Nummerierung neu beginnen" ("Restart numbering"): never, every year or every month.
  Numbers are assigned by the server when a submission arrives, so they are unique and without gaps.
- "Mitarbeiter im Außendienst dürfen das Dokument per E-Mail an den Kunden senden" ("Field workers may
  email the document to the customer").

### Preview, lint and publish

- **Preview:** "Vorschau" shows the unsaved draft with the Field App's own renderer, as "Smartphone" or
  "Tablet". "PDF-Vorschau" ("Preview PDF") renders the draft with sample data.
- **Problems:** the lint panel "Probleme" ("Problems") lists errors and warnings. A form with errors
  cannot be published.
- **Publish:** "Veröffentlichen" ("Publish") needs a short change note ("Änderungsprotokoll"). Field
  workers get the new version at their next sync; drafts already started stay on their version.

### Versions and rollback

The "Versionen" ("Versions") tab of the classic builder lists every published version with author,
date and change note:
- "Vergleichen" ("Compare") shows what was added, removed, changed or moved, against the draft or any
  other version.
- "Auf diese Version zurücksetzen" ("Roll back to this version") publishes the old content as a new
  version (for example v3 after v2). Nothing is overwritten.
- "Version zurückziehen" ("Retire version") stops new submissions on that version. The Field App asks
  the worker to copy open drafts into the current version.

Publishing never changes existing submissions or their PDFs. Every submission keeps the form version it
was filled with.

## Document designs

"Formulare" → "Designs" controls how PDFs look. Each organization can have several designs; one is
the default ("Als Standard festlegen", "Make default").

Settings:
- "Seite" ("Page"): A4 or US Letter, portrait or landscape, margins in millimetres.
- "Kopf- und Fußzeile" ("Header and footer"): rich text with the tokens `{{org.name}}`,
  `{{submission.number}}`, `{{submission.date}}`, `{{page}}` and `{{pages}}`, and an option to show the
  logo in the header.
- "Inhalt" ("Content"): two-column or compact layout; lists as tables or cards; photos per row (1–4);
  signatures in place or in a block at the end; watermark ("Wasserzeichen": automatic DRAFT/VOID by
  status, none, or always); whether to show empty fields.
- "Länderformate" ("Locale formats"): number and date formats.

"PDF-Vorschau" previews the design; "Veröffentlichen" publishes a new design version with a note.
Designs have versions like forms: compare, roll back and retire. A PDF that is created again later
uses the design version of its submission, so old documents keep their look.

## Submissions and reviews

**Inbox.** "Einreichungen" ("Submissions") lists submissions you may see, filtered by status, form,
person and date. Drafts are hidden by default.

Open a submission to see:
- "Übersicht" ("Overview") and "Eingereichte Daten" ("Submitted data") with photos and files;
- "Dokumente" ("Documents"): every PDF version ("PDF-Fassungen") with its versions, page count and
  hash, a download button, and the customers it was sent to;
- "Verlauf" ("History"): every status change.

Actions:
- "Freigeben" ("Approve") and "Änderungen anfordern" ("Request changes", with a comment) when you have
  an open review task for it.
- "An Kunden senden" ("Send to customer") when the form allows it.
- "PDF neu erzeugen" ("Re-render PDF") creates a new PDF version, for example after a rendering
  error. The old one is kept.
- "Workflow ausführen" ("Run workflow") starts a manual workflow.

Submissions are never edited or deleted in the dashboard. A workflow step can set them to "Storniert"
("Void").

**"Meine Aufgaben" ("My tasks")** lists your open review tasks with their due date; overdue tasks are
marked "Überfällig". Approve or request changes directly from the list. Reviewers can also decide in the
Field App.

When you request changes, the field worker is notified, edits the submission and resubmits it. The
document number stays the same.

## Workflows and webhooks

"Automatisierung" → "Workflows" automates what happens after a submission. A workflow has a trigger
("Auslöser"), optional conditions and ordered steps.

Triggers:
- "Einreichung abgeschickt" ("Submission submitted")
- "Status der Einreichung geändert" ("Submission status changed", to a chosen status)
- "Einreichung freigegeben" ("Submission approved") and "Änderungen angefordert" ("Changes requested")
- "Formular veröffentlicht" ("Form published")
- "Zeitplan (Cron)" ("Schedule (cron)"), in the organization's time zone
- "Manuell (Schaltfläche an einer Einreichung)" ("Manual (button on a submission)")

Limit a workflow to some forms with "Nur für diese Formulare" and add JsonLogic conditions with
"Bedingungen (JsonLogic)".

Steps:
- "E-Mail senden" ("Send email"): fixed addresses, addresses from form fields, the submitter or groups;
  subject and Markdown message with tokens; the PDF as an attachment (a link when it is too large) or
  as a download link.
- "Benutzer benachrichtigen" ("Notify users"): users, groups or roles, in the app and/or by email.
- "Prüfung anfordern" ("Request review"): assigns a task to a group or a user, with a due time in hours.
  Approving continues the workflow; requesting changes returns the submission to the worker.
- "Status setzen" ("Set status"): "Abgeschlossen" ("Completed") or "Storniert" ("Void").
- "Dokument erzeugen" ("Generate document") with a chosen design.
- "Webhook aufrufen" ("Call webhook"): sends the submission to another system.

A typical setup: trigger "Einreichung abgeschickt" → "Prüfung anfordern" (group Office) → "E-Mail
senden" to the customer address field with the PDF attached. If no workflow requests a review or sets
the status, a submission is completed right after it is submitted.

**Runs.** The "Ausführungen" ("Runs") tab shows each run with a step timeline ("Verlauf"). Failed steps
are retried automatically; if they still fail, admins are notified and "Erneut versuchen" ("Retry")
continues the run at the failed step. A trigger never starts the same workflow twice for the same
event, so customers do not get duplicate emails. Workflows with runs cannot be deleted; deactivate them
instead.

**Webhook endpoints.** "Webhook-Endpunkte" ("Webhook endpoints"): name, URL, signing secret
("Signatur-Geheimnis") and active flag. Leave the secret empty to have one generated; it is shown once.
The target must be a public HTTPS or HTTP address; private and internal addresses are refused. How
receivers verify the signature: [API and integrations](api.md#webhooks).

## Notifications

The bell in the dashboard shows in-app notifications. User menu → "Benachrichtigungen"
("Notifications") sets, per event, whether you get them in the app ("In der App") and by email
("E-Mail"):
- a review was assigned to you; changes were requested on your submission; your submission was approved;
- a workflow failed (administrators);
- a new or changed form is available;
- messages from workflows;
- an export you requested is ready;
- single sign-on is misconfigured (administrators).

The settings apply in all your organizations.

## Reports, exports and dashboards

**Submission explorer.** "Auswertung" → "Einreichungen auswerten" ("Submission explorer"): choose a
form ("Formular wählen") to get one column per field across all its versions. The first six field
columns are visible; switch on more as needed. Filter by date, status, person, group and field values.
The search box matches the whole term as typed, in the number, the submitter or any value. While a search
is active, the list shows no total and "select all" selects the rows on the current page; "Export
filtered rows" still exports every match.

**Exports.** "Exportieren" ("Export"): all filtered rows as Excel (XLSX; list rows go to a second
sheet), as CSV, or all PDFs as a ZIP. You can also export selected rows. Exports run in the background
and notify you when ready. Downloads are kept for 7 days. Limits: 50,000 rows per export, 10,000
selected rows, and two exports in progress per person.

**Saved reports.** "Als Bericht speichern" ("Save as report") in the explorer, or "Gespeicherte
Berichte" ("Saved reports"):
- group by ("Gruppieren nach") status, person, form version, day, week, month or a field;
- measure ("Kennzahl"): count, sum or average of a number or money field, calculated exactly;
- filters and a date range; visible to you only or to everyone with report access;
- "Regelmäßiger Versand" ("Scheduled email"): weekly on Mondays or monthly on the 1st, at 07:00
  organization time, with a CSV attachment.

The report page shows a chart and a table, offers "CSV herunterladen" ("Download CSV") and "An
Dashboard anheften" ("Pin to dashboard").

**Dashboard.** The start page shows widgets for your role, for a date range (the last 7 days by
default):
- Owners and Admins: key figures, submissions over time per form, failed workflows;
- Reviewers: the review queue and recent submissions;
- Designers: form drafts and recently published versions;
- Viewers: key figures and recent submissions;
- plus the saved reports you pinned.

Figures are cached for about a minute.

## Email settings and the mail log

**"E-Mail-Einstellungen" ("Mail settings")**, Owner and Admin:
- "Absender" ("Sender"): sender name and reply-to address. Mail is sent from the server's address
  unless you use your own mail server.
- "Eigener Mailserver (SMTP)" ("Own mail server"): host, port (25, 465, 587 or 2525), encryption,
  sender address, username and password. The password is stored encrypted and never shown again. The
  server must have a public address.
- "Test-E-Mail senden" ("Send test email") sends a test to your own address with the saved settings.

**"E-Mail-Protokoll" ("Mail log")** lists every email with recipient, subject, status, attempts and
times. Failed mails show the error; "Erneut senden" ("Send again") retries them.

PDFs up to 10 MB are attached; larger ones are sent as a link that expires after 14 days (the operator
can change both).

## Mail capture banner

While the instance captures mail (a new instance does until its operator sets up delivery), a banner
"E-Mail-Abfangmodus" ("Mail capture") sits at the top of every page of the admin dashboard. It cannot be
dismissed. It comes in two variants:

- **"E-Mails werden abgefangen, nicht zugestellt."** ("Mail is captured, not delivered."): the
  organization uses the instance's mailer, so every mail it sends stays in the instance's mail catcher and
  reaches nobody. Members who may manage the organization also see the link "Eigenen SMTP-Server in den
  E-Mail-Einstellungen eintragen" ("Use your own SMTP server in Mail settings").
- **"Einladungen, Passwort-Zurücksetzungen und Bestätigungen von E-Mail-Adressen werden abgefangen, nicht
  zugestellt."** ("Invitations, password resets and email address confirmations are captured, not
  delivered."): the organization has its own SMTP server, which delivers all other mail; these three
  always use the instance's mailer.

Platform operators see the command that sets up delivery on the server ("E-Mail-Versand auf dem Server
einrichten:", "Set up mail delivery on the server:"), `fieldset config set mail smtp --smtp-host <host>
--smtp-username <user> --from-address <address> --password-stdin` (see
[configuration](configuration.md#mail)); everyone else reads "Bitten Sie den Betreiber der Instanz, den
E-Mail-Versand einzurichten." ("Ask your instance owner to set up mail delivery."). The banner disappears
once the instance delivers mail.

## Single sign-on (SSO)

With SSO, members sign in through your company's identity provider, for example Microsoft Entra ID,
Google Workspace, Keycloak, Authentik or Zitadel (OpenID Connect), or any SAML 2.0 provider. Open
"Team" → "Single Sign-on". Owners and Admins can open the page; only an **Owner** can set up the
connection and change its security settings. Each organization has one identity provider.

### 1. Connect the identity provider

Choose the protocol ("Protokoll"):

- **OpenID Connect.** Pick the provider ("Anbieter"):
  - "Microsoft Entra ID": enter the directory (tenant) ID ("Verzeichnis-ID (Mandanten-ID)").
  - "Google Workspace": nothing extra; sign-ins must come from a verified domain.
  - "Anderer OpenID-Connect-Anbieter": enter the issuer URL (HTTPS).

  Then the client ID, the client secret ("Client-Geheimnis"; stored encrypted and never shown again;
  leave it empty to keep it), scopes and the groups claim ("Gruppen-Claim", default `groups`). Register
  this redirect URI at the provider:
  `https://<your server>/auth/sso/<provider id>/callback`.
- **SAML 2.0.** Enter the identity provider's metadata URL (HTTPS) or paste its metadata XML. Choose the
  NameID format (persistent is recommended; other formats need a stable subject attribute) and the
  email, name and groups attributes. Give the provider:
  - the ACS URL `https://<your server>/auth/sso/<provider id>/saml/acs` (HTTP-POST);
  - the service provider metadata and entity ID
    `https://<your server>/auth/sso/<provider id>/saml/metadata`.

  Assertions must be signed.

The page shows these addresses ready to copy, even before the first save; they do not change later.

### 2. Verify your email domains

Under "Domains" add each email domain ("Domain hinzufügen"), for example `example.com`. Subdomains are
added separately. "DNS-Eintrag" ("DNS record") shows the TXT record to create at your DNS provider:

| Name (host) | Type | Value |
|---|---|---|
| `_fieldset-verification.example.com` | TXT | `fieldset-verification=<token>` |

Some DNS providers add the domain themselves; then enter only `_fieldset-verification` as the
name. Click "Verifizieren" ("Verify") once the record is published. Only one organization can verify a
domain. The server checks verified domains daily; after three failed checks in a row the domain loses
its verification and admins are notified.

Once a domain is verified, everyone who enters an address at that domain on a login page is offered
your identity provider.

### 3. Provisioning and mappings

- "Mitglieder bei der ersten Anmeldung anlegen (Just-in-Time)": creates members at their first sign-in,
  with the default role ("Standardrolle", Field Worker unless an Owner changes it) and default group.
  Without it, only existing and invited members can sign in.
- "Claim-Zuordnungen" ("Claim mappings"): map a claim value, for example the group `form-designers`,
  to a role or a group. Mappings are applied at every sign-in. The identity provider can never grant the
  Owner role.
- "Beim Identitätsanbieter immer nach dem Passwort fragen (gemeinsam genutzte Geräte)": always asks for
  the password at the provider, useful on shared devices.

### 4. Test

"Anmeldung testen" ("Test sign-in") opens a sign-in at the provider in a new tab and reports the result
on the SSO page: the email address, the groups received, or the reason for a failure. It does not sign
you in. A successful test activates the provider (status "Aktiv").

### 5. Enforce SSO (optional)

"Erzwingung" ("Enforcement") → "Single Sign-on erzwingen" turns off password sign-in and password reset
for all addresses at your verified domains. The page lists the preconditions:
- at least one verified domain;
- an active provider (a successful test);
- a break-glass Owner ("Notfall-Inhaber"): an active Owner with two-factor authentication;
- you are the break-glass Owner or have signed in through SSO yourself before.

The break-glass Owner keeps password and two-factor sign-in for emergencies, for example when the
identity provider is down. Each use is recorded in the [audit log](#audit-log). Turning enforcement on
ends the password sessions of the affected members. Only an Owner can turn it off.

### Health and problems

The server checks the provider hourly. If the provider or a domain stops working, the status shows
"Fehler" ("Error") with the last error, and administrators get the notification "Single Sign-on
erfordert Ihre Aufmerksamkeit" ("Single sign-on needs your attention"), at most every 6 hours per
problem. Changing the connection resets the status to draft (test again) and alerts the other Owners.
Signing out of the app does not sign you out of the identity provider.

See [Troubleshooting](troubleshooting.md#single-sign-on) for common errors.

## API tokens

Other systems (an ERP, a BI tool, a script) can use the REST API with a personal token. Open "Team" →
"API-Tokens" ("API tokens"):
- "Neues Token" ("New token"): a name, the permissions ("Berechtigungen") the integration needs, and
  the expiry (90 days preselected; 7 to 365 days, or never). The token is shown **only once**; copy it
  into the other system.
- The list shows each token's permissions, last use and expiry. "Widerrufen" ("Revoke") ends access at
  once.

A token works only in this organization and never allows more than your own role. Everyone with
dashboard access manages their own tokens; Owners and Admins also see and can revoke the tokens of all
members. Deactivating a member deletes their tokens for the organization. Creating and revoking a token
and its first use are recorded in the audit log. Details for developers:
[API and integrations](api.md#integration-tokens).

## Audit log

"Compliance" → "Audit-Log" ("Audit log") shows what happened in your organization: when, who ("Ausgeführt
von", a member, "System" or an API token), what ("Ereignis", "Event") and to which record ("Objekt",
"Subject"), with the IP address. Owners and Admins can open it; for a custom role, tick the permission
"Audit-Log". Nobody can change or delete entries: the dashboard has no such action, and the database
refuses it.

What is recorded, for example:
- sign-ins and sign-outs, failed sign-ins and two-factor codes, lockouts after too many attempts;
- changes to forms and versions, designs, workflows, roles, members, groups, SSO and mail settings,
  each with the old and new values; passwords and other secrets appear as `[redacted]`;
- the life of a submission: created, submitted, status changes, PDF created, downloaded or sent;
- exports, API tokens, retention, legal holds, data exports and erasure;
- every time a platform operator signs in as one of your members ("Identitätswechsel begonnen" /
  "Identitätswechsel beendet", "Impersonation started" / "ended"), with the operator's name and the
  reason. Everything the operator does meanwhile is recorded under the member's name, and each of these
  entries also names the operator (`impersonator`).

**Filter** by "Ausgeführt von", "Ereignis", "Objekttyp" ("Subject type") and a date range ("Von", "Bis",
in your time zone). Open an entry to see all details: the changes, the user agent, the request ID and its
position in the hash chain ("Hash-Kette").

**Export.** "CSV exportieren" ("Export CSV") exports the filtered entries. Up to 2,000 entries download
at once; larger exports run in the background, and a notification brings the download link, which works
for 7 days. Each export is itself recorded.

**History on records.** The pages of a form (classic builder), a submission, a workflow, the members
page and the SSO page end with a "Verlauf" ("History") table: the audit entries of that record and its
parts, for example a form's versions or a submission's emails.

**Retention.** Entries older than 365 days are deleted every night; the operator can change the period
(`AUDIT_RETENTION_DAYS`, at least 30 days). Entries are never deleted earlier.

**Tamper evidence.** Every entry is sealed into a hash chain of your organization: each entry's
fingerprint includes the one before. The operator's check (`audit:verify`) finds entries that were
changed or removed by anyone who cannot rewrite the whole table. Someone with full access to the
database could recompute the chain; the operator detects that by comparing it with copies of the chain's
latest fingerprints kept outside the database. Restores, rollbacks and resets start a new epoch of the
chain instead of breaking it; see [chain epochs](backup-restore.md#chain-epochs-and-the-ledger) (1.0 checkout
installs: [protect the audit log](archive/1.0-install.md#7-protect-the-audit-log)).

## Privacy and data retention

The pages under "Compliance" help you meet data protection rules (GDPR): delete or anonymize old
submissions, keep records that must be kept, and answer requests for personal data.

### Retention per form

"Compliance" → "Aufbewahrung" ("Retention"), Owners and Admins. The list shows each form's retention
("Unbegrenzt aufbewahren", "Keep indefinitely", until you set one) and whether it is under legal hold.
"Aufbewahrung" on a form:
- "Aufbewahrungsfrist in Monaten" ("Retention period in months"), 1 to 1200, counted from the submit
  date (drafts: from creation). Empty keeps submissions indefinitely.
- "Danach" ("Then"):
  - "Anonymisieren" ("Anonymize", preselected) keeps numbers, amounts, dates, times and choices, so
    reports and totals stay correct. It removes what identifies people: text, email, phone, photo, file,
    signature, location and hidden fields; fields marked as customer, site or payment details; the
    device; every reference to the people involved (creator, submitter, reviewers and their comments);
    and the submission's emails in the mail log. Attachments and PDF files are deleted; the record that
    a PDF was issued (with its checksum) stays.
  - "Löschen" ("Delete") removes the submission with its files, PDFs and emails.
  - Drafts that are due are always deleted.

A job runs every night and applies the retention. Each deleted or anonymized submission is recorded in
the audit log.

After anonymization the submission stays in the inbox and in reports with its kept values. Its PDF
cannot be opened any more: the page shows "Die PDF-Dateien wurden bei der Anonymisierung dieser
Einreichung gelöscht." ("The PDF files were deleted when this submission was anonymized."). Field App
devices may keep a copy until their next sync.

### Legal holds

A legal hold keeps submissions that must not be deleted, for example invoices, which German law requires
you to keep for years. While a hold is active, the retention job leaves the held submissions alone.
- **A whole form:** on "Aufbewahrung", the action "Aufbewahrungssperre" ("Legal hold") with a reason.
  The form's retention period must be at least 120 months (ten years; the operator can change this), or
  indefinite. A shorter period is refused while the hold is active.
- **One submission:** "Compliance" → "Aufbewahrungssperren" ("Legal holds") → "Einreichung sperren"
  ("Hold a submission"); search it by number and give a reason.

"Aufheben" ("Release") ends a hold; from the next night the retention applies again. Released holds stay
as history; the list shows active holds until you change the filter "Aktiv" ("Active"). Placing and
releasing holds are recorded in the audit log. The minimum period is a setting, not legal advice: check
which periods apply to you.

### Personal data of members

"Compliance" → "Personenbezogene Daten" ("Personal data"), Owners and Admins:
- **"Daten exportieren" ("Export data")** creates a ZIP file with the member's profile, memberships,
  devices, submissions and audit entries in this organization. A notification brings the download link,
  which works for 7 days. Passwords, two-factor secrets and tokens are never included.
- **"Löschen" ("Erase")** erases the account after you type its email address. Name, email address,
  password, two-factor secret, devices (they are asked to wipe), sessions, API tokens and SSO links are
  removed. Submissions and audit entries stay, because the organization must keep them, and show
  "Deleted user". Audit entries written before the erasure keep their values until the audit retention
  removes them. This cannot be undone.

Erasing is refused for your own account (use "Meine Daten"), for an account that also belongs to another
organization (the person deletes it themselves), for the last Owner, and for an Owner unless you are an
Owner.

Field workers have no access to the dashboard. When they ask for their data or want their account
deleted, an Owner or Admin uses this page.

### My data

"Compliance" → "Meine Daten" ("My data"), for everyone with dashboard access:
- "Meine Daten exportieren" ("Export my data"): the same ZIP file for your own account. Only one export
  of a kind runs at a time, and you can request 10 per hour.
- "Mein Konto löschen" ("Delete my account") asks for your current password and erases your whole
  account in all organizations, as described above. It is refused while you are the last Owner of an
  organization. If you only ever signed in through single sign-on, you may not know a password; ask an
  Owner or Admin.

### What the application does in any case

- Photos lose their metadata (EXIF), including the GPS position, before upload; the server strips it
  again.
- Secrets (SMTP password, identity provider secrets, webhook secrets, two-factor secrets) are stored
  encrypted.
- No trackers or telemetry.

## Platform operators (/platform)

This section is for the people who run the server. The owner named at the install is an operator;
`fieldset config set operator-email <email>` adds one ([configuration](configuration.md#keys)). On a 1.0 checkout
install operators get the role with `php artisan app:platform-admin <email>`
([1.0 install guide](archive/1.0-install.md#4-create-the-first-organization-and-owner)).
They sign in at `/platform/login`, separately from `/admin`; a sign-in through an organization's single
sign-on never opens `/platform`. Two-factor authentication is required, and the sign-in lasts at most 12
hours (`PLATFORM_SESSION_HOURS`).

- **"Organisationen" ("Organizations")**: every organization with active members, creation date and
  status. "Mitglieder" ("Members") lists its members.
- **"Zustand" ("Health")**: Horizon and its queues, failed jobs, Gotenberg, the mail transport, the
  scheduler and the free disk space, each "OK", "Warnung" ("Warning") or "Fehler" ("Failing").
- **"Instanz" ("Instance")** (instances installed with `fieldset`): problems first (a failed or stale backup,
  low disk space, an available update, backups held only on this host), each with the `fieldset` command to run on the
  server; then version and image digests, mode and URL, mail delivery with the catcher link, the backup schedule and
  last result, disk usage and, when the update check is on (`fieldset config set update-check on`), whether a newer
  release exists. Operators also get one e-mail per failed or stale backup.
- **"Als Mitglied anmelden" ("Impersonate")** on a member: the operator sees the admin dashboard as that
  member, for support. A reason of 10 to 500 characters is required. It works only for active members
  with dashboard access in an organization that is not archived, never for other operators and never in
  the Field App or the API. A banner on every page shows who is signed in on whose behalf and when it
  ends. "Anmeldung als Mitglied beenden" ("Exit impersonation") returns to `/platform`; after 30 minutes
  (`PLATFORM_IMPERSONATION_MINUTES`) it ends by itself. Start and end appear in the organization's
  [audit log](#audit-log) with the operator and the reason, and every entry written meanwhile records
  the operator (`impersonator`). No API token can be created while impersonating.
