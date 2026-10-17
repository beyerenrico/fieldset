# Field App guide

This guide is for field workers and reviewers who fill in forms on a phone or tablet. The Field App
runs in the browser at `https://<your server>/app`; your office tells you the address.

The app is available in German and English. This guide quotes the German text as the app shows it,
followed by the English text.

Contents:
- [Before you start](#before-you-start)
- [Install the app](#install-the-app)
- [Sign in](#sign-in)
- [Find a form](#find-a-form)
- [Fill in a form](#fill-in-a-form)
- [Check and submit](#check-and-submit)
- [Working offline](#working-offline)
- [When the app needs your decision](#when-the-app-needs-your-decision)
- [The PDF and "send to customer"](#the-pdf-and-send-to-customer)
- [Changes requested by the office](#changes-requested-by-the-office)
- [Review tasks (reviewers)](#review-tasks-reviewers)
- [Notifications](#notifications)
- [Settings](#settings)
- [Sign out, shared and lost devices](#sign-out-shared-and-lost-devices)
- [Keyboard and accessibility](#keyboard-and-accessibility)
- [Your personal data](#your-personal-data)

## Before you start

- You need an account in your organization. The office invites you by email, or your company's
  central sign-in (single sign-on) creates the account the first time you sign in.
- Use a current browser: Chrome or Edge on Android, Safari on iPhone and iPad.
- The app stores your drafts on the device, so it keeps working without a connection. You need a
  connection to sign in for the first time and to download the forms once.

## Install the app

Installing puts the app on your home screen. It then opens like a normal app and works offline.

**Android (Chrome, Edge).** The app shows a banner "Installieren Sie die App, um sie offline und vom
Home-Bildschirm aus zu nutzen." ("Install the app to use it offline and from your home screen."). Tap
"App installieren" ("Install app"). "Nicht jetzt" ("Not now") hides the banner on this device. You can
also install from the browser menu.

**iPhone and iPad (Safari).** Tap "Teilen" ("Share"; in Safari it may be under "•••"), then
"Zum Home-Bildschirm" ("Add to Home Screen").

Install the app on iOS. iOS deletes the data of web apps that are not on the home screen after 7 days
without use. While you have unsent work and the app is not installed, it warns you: "Noch nicht
synchronisierte Einreichungen: … iOS löscht die Daten von Web-Apps, die nicht auf dem
Home-Bildschirm liegen, nach 7 Tagen ohne Nutzung." ("Submissions not yet synced: … iOS deletes the
data of web apps that are not on the home screen after 7 days without use.")

**Updates.** When a new version is available, the app shows "Eine neue Version der App ist
verfügbar." ("A new version of the app is available."). Tap "Jetzt aktualisieren" ("Update now"): the
app saves your open drafts, installs the update and reloads. "Später" ("Later") keeps the current
version for now. The app never updates in the middle of your work without asking.

If you have the app open in several tabs, an update in one tab can ask the others to reload ("Neu
laden", "Reload").

## Sign in

1. Enter your work email address and tap "Weiter" ("Continue"). The app checks whether your company
   uses a central sign-in. This step needs a connection.
2. What happens next depends on your organization:
   - **Central sign-in required.** The app sends you to your company's sign-in page ("Sie werden zur
     Anmeldeseite Ihrer Organisation weitergeleitet …"). Sign in there; you come back to the app
     signed in.
   - **Central sign-in available.** The app asks "Wie möchten Sie sich anmelden?" ("How would you like
     to sign in?"). Choose "Weiter mit <organization>" ("Continue with <organization>") or
     "Mit Passwort anmelden" ("Sign in with a password").
   - **No central sign-in.** Enter your password and tap "Anmelden" ("Sign in").
3. If you have two-factor authentication, enter the code from your authenticator app. If you lost your
   phone, tap "Wiederherstellungscode verwenden" ("Use a recovery code"). The central sign-in does
   its own checks, so the app does not ask for a code after it.
4. If you belong to several organizations, choose one ("Organisation wählen", "Choose an
   organization"). You can switch later in the settings.

Forgot your password? The app has no reset screen: open `https://<your server>/admin/login`, tap
"Passwort vergessen?" ("Forgot password?") and follow the link in the email, or ask your office. If your
company uses central sign-in, reset the password there.

You stay signed in for 30 days from your last use. If your session expires, the app says "Die Sitzung
ist abgelaufen. Melden Sie sich erneut an – die Daten auf diesem Gerät bleiben erhalten." ("The
session has expired. Sign in again – the data on this device is kept."). Nothing is lost: unsent work
is sent after you sign in again.

Without a connection, an installed app that was signed in before starts with the data on the device.

## Find a form

The home screen ("Start") shows:
- "Offene Aufgaben" ("Open tasks"): submissions waiting for your review, with the due date;
- "Entwürfe fortsetzen" ("Continue drafts");
- "Neu beginnen" ("Start new"): your favorites ("Favoriten") and recently used forms ("Zuletzt
  verwendet"), and "Alle Formulare" ("All forms").

"Formulare" ("Forms") lists every form you may fill in. Search with "Formulare durchsuchen", filter by
category, and tap the star to add a form to your favorites. You only see forms the office has given you
access to.

Offline, the list shows the forms stored on the device. A form marked "Offline nicht verfügbar"
("Not available offline") has not been downloaded yet; connect once to download it.

## Fill in a form

Tap a form to start. The bottom navigation is hidden while you fill in a form.

**Saving.** The app saves every change on the device as you type. The indicator shows the state:

| Indicator | Meaning |
|---|---|
| "Ungespeicherte Änderungen" ("Unsaved changes") / "Wird gespeichert …" ("Saving…") | The change is being written to the device. |
| "Auf diesem Gerät gespeichert" ("Saved on this device") | Safe on the device. If the phone dies or the tab is closed, nothing is lost. |
| "Wartet auf Synchronisierung" ("Waiting to sync") | Saved on the device, not yet sent to the office. |
| "Wird synchronisiert …" ("Syncing…") / "Synchronisiert" ("Synced") | Being sent / the office has the latest version. |
| "Nicht gespeichert – der Speicher dieses Geräts ist voll." | The device storage is full. Keep the page open, sync, or free up space. |
| "Konflikt – jemand anderes hat diesen Entwurf geändert" | Someone else changed this draft; see [conflicts](#when-the-app-needs-your-decision). |
| "In einem anderen Tab geöffnet – hier nur lesbar" | The draft is open in another tab; edit it there. |

**Fields.** Required fields and checks are shown next to the field. Totals and other calculated
fields update while you type; the office's PDF uses the same calculation.

**Lists of items (repeaters).** Tap "Eintrag hinzufügen" ("Add entry") for each line, for example
each material used. There is no fixed number of lines unless the form sets one.

**Photos.** Tap "Foto aufnehmen oder wählen" ("Take or choose a photo"). The app shrinks photos to at
most 2048 pixels on the long edge before upload and removes the photo's metadata (EXIF), including the
GPS position.

**Location.** If the form has a location field, tap "Standort erfassen" ("Capture location"). The
browser asks for permission first.

**Signatures.** Sign in the box ("Bitte im Feld unten unterschreiben") with a finger, a pen or a mouse,
enter the signer's name ("Name der unterschreibenden Person") and tap "Unterschrift bestätigen"
("Confirm signature"). "Löschen" ("Clear") empties the box. The app stores the signature with the name
and the time. This is a simple electronic signature.

## Check and submit

Tap "Prüfen" ("Review") to see the summary screen "Prüfen und unterschreiben" ("Review and sign").
Fix any fields the app marks, then tap "Einreichen" ("Submit").

- **Online:** the office receives the submission and assigns the document number, for example
  `SR-2026-0042`. The PDF is created a moment later.
- **Offline:** the app shows "Bereit zum Senden" ("Ready to send") and "Nummer ausstehend" ("Number
  pending"). It sends the submission automatically when you are back online, and the number is assigned
  then. The submission is sent exactly once, even if you tap "Einreichen" again or lose the connection
  halfway.

Photos and files are uploaded before the submission. If an upload is still running, the app asks you
to wait ("Uploads laufen – bitte warten Sie, bis sie abgeschlossen sind.").

"Einreichungen" ("Submissions") lists your submissions with their status: "Entwurf" (draft),
"Eingereicht" (submitted), "In Prüfung" (in review), "Änderungen angefordert" (changes requested),
"Freigegeben" (approved), "Abgeschlossen" (completed) or "Storniert" (void). Work that is still only on
the device is listed under "Auf diesem Gerät" ("On this device").

## Working offline

You can fill in and submit forms without a connection. Everything stays on the device until it has
been sent.

**When the app syncs.** The app sends your work and fetches new forms and tasks:
- when it starts and when you come back to it;
- when the connection returns;
- about every minute while it is open and has unsent work;
- when you tap "Jetzt synchronisieren" ("Sync now") in the settings.

On Android, Chrome can also wake an open app when the connection returns. iPhones and iPads sync when
you open the app; open it once you are back online.

**What the header shows.**

| Header | Meaning |
|---|---|
| "Nicht synchronisierte Einreichungen: N" ("Submissions not yet synced: N") | Work waiting to be sent. It goes out by itself. |
| "Brauchen Ihre Entscheidung: N" ("Need your decision: N") | The office could not accept something; open it and choose what to do. |
| "Erneut anmelden, um zu synchronisieren" ("Sign in again to sync") | Your session expired. Sign in; your data stays on the device. |

Each item under "Auf diesem Gerät" has one of these states: "Nur auf diesem Gerät" ("Only on this
device"), "Wartet auf Synchronisierung" ("Waiting to sync"), "Wird synchronisiert …", "Synchronisiert",
"Konflikt" ("Conflict") or "Entscheidung nötig" ("Needs your decision").

If a send fails for a temporary reason, for example a weak signal, the app tries again by itself, with
growing pauses of up to 15 minutes. It never throws work away.

## When the app needs your decision

Sometimes the office cannot accept what the device sends. The app then marks the item "Entscheidung
nötig" ("Needs your decision"), keeps your entries and explains why. The usual cases and choices:

| Reason | What you can do |
|---|---|
| The submission was already submitted or is being processed in the office | "Als neuen Entwurf speichern" ("Save as new draft") |
| The form version was withdrawn | "In aktuelle Version kopieren" ("Copy to current version"), then check the copy |
| The draft belongs to another form version in the office, or no longer exists there | "Als neuen Entwurf speichern" |
| You no longer have access to the form | Ask the office to restore access, then "Erneut versuchen" ("Try again") |
| The draft is too large to send | Remove large photos or files, then "Erneut versuchen" |
| Fields need correcting, or an attachment is missing or was refused | "Formular öffnen" ("Open form"), fix the marked fields and submit again |

"Meine Änderungen verwerfen" ("Discard my changes") is always available, after a confirmation. It
cannot be undone.

**Conflicts.** If someone else changed the same draft, the app shows "Dieser Entwurf wurde anderswo
geändert" ("This draft was changed elsewhere") with both versions side by side. Choose "Meine
behalten" ("Keep mine") or "Version im Büro übernehmen" ("Use office version"). The other version is
discarded.

## The PDF and "send to customer"

Open a submission to see its document ("Dokument (PDF)"). While the PDF is being created the app shows
"Das PDF wird erstellt …"; then tap "PDF öffnen" ("Open PDF"). The PDF needs a connection.

Your organization can have old submissions anonymized after a set time. The PDF files of such a
submission are deleted, and the app shows "Die PDF-Dateien wurden bei der Anonymisierung dieser
Einreichung gelöscht." ("The PDF files were deleted when this submission was anonymized."). The PDF
cannot be opened any more; ask your office if you need the information.

If the form allows it, "An Kunden senden" ("Send to customer") emails the PDF. Enter the email address
and, optionally, a name; the app fills them in when the form has customer fields.
- Online: "Der Versand an … ist eingeplant." ("Sending to … is scheduled."), or the mail goes out as
  soon as the PDF is ready.
- Offline: the send is queued ("Der Versand an … ist vorgemerkt.") and goes out after the sync, once the
  number and the PDF exist. "Versand abbrechen" ("Cancel sending") removes it from the queue.

## Changes requested by the office

If a reviewer asks for changes, you get a notification and the submission shows "Änderungen
angefordert" ("Changes requested") with the reviewer's comment. Tap "Bearbeiten und erneut einreichen"
("Edit and resubmit"), make the changes and tap "Erneut einreichen" ("Resubmit"). The document number
stays the same.

## Review tasks (reviewers)

If you review submissions, your open tasks appear on the home screen under "Offene Aufgaben". Open one
to see the submission and the section "Ihre Prüfung" ("Your review"):
- "Freigeben" ("Approve") continues the office's workflow, for example emailing the PDF to the
  customer.
- "Änderungen anfordern" ("Request changes") needs a comment ("Was soll geändert werden?", up to 2000
  characters). The person who submitted is notified and can resubmit.

Review decisions need a connection. If the connection drops, try again: the decision is saved only once.

## Notifications

The bell in the header shows unread notifications, for example a new review task, changes requested,
an approved submission or a new form. "Alle als gelesen markieren" ("Mark all as read") clears the
count.

In "Einstellungen" → "Benachrichtigungen" you choose, per event, whether you are notified in the app
("In der App") and by email ("E-Mail"). The choice applies in all your organizations.

## Settings

"Einstellungen" ("Settings") contains:
- **"Synchronisierung" ("Sync"):** the time of the last sync, what is still waiting, and "Jetzt
  synchronisieren" ("Sync now").
- **"Speicher auf diesem Gerät" ("Storage on this device"):** how much space the app uses. If the
  browser may delete the data when space runs low, tap "Daten auf diesem Gerät behalten" ("Keep data on
  this device"). If the browser refuses, installing the app can help.
- **"Sprache" ("Language"):** Deutsch or English.
- **"Größere Schrift" ("Larger text").**
- **"Benachrichtigungen" ("Notifications").**
- **"Organisation":** switch organization, if you belong to several.
- **"Abmelden" ("Sign out").**

## Sign out, shared and lost devices

**Sign out deletes the data on the device.** The app asks first ("Beim Abmelden werden alle auf diesem
Gerät gespeicherten Daten gelöscht. Fortfahren?") and warns if work has not been sent yet. Tap "Jetzt
synchronisieren" first, then "Abmelden und Daten löschen" ("Sign out and delete data"). Offline, the app
signs you out on the device at once and tells the server when it is back online.

**Shared devices.** If someone else's unsent work is still on the device when you sign in, the app
shows "Daten eines anderen Benutzers" ("Another user's data"). That work stays hidden and is never sent
with your account. Keep it ("Behalten") if that person will sign in again to send it, or delete it
("Löschen").

**Lost or stolen device.** Tell your office. An administrator can revoke the device; the next time it
connects, the app deletes all local data and shows "Ihre Administration hat dieses Gerät abgemeldet und
die lokalen Daten gelöscht." ("Your administrator signed this device out and deleted the local data.").
The same happens when your account is deactivated.

See also: [Troubleshooting](troubleshooting.md#field-app) for problems such as work that stays
"Wartet auf Synchronisierung".

## Keyboard and accessibility

The app can be used with a keyboard, for example on a tablet with a keyboard:
- Tab and Shift+Tab move through the page in reading order. The field with focus is outlined.
- After you open a page, the focus is on its heading; in a form with several steps, on the heading of
  the step.
- Choose from a list by typing the first letters. Tick a check box with Space. Buttons such as "Prüfen"
  ("Review") and "Einreichen" ("Submit") work with Enter.
- **Signatures need a pointer:** a finger, a pen or a mouse. The name field, "Löschen" ("Clear") and
  "Unterschrift bestätigen" ("Confirm signature") work with the keyboard. If you cannot draw a
  signature, tell your office.

"Größere Schrift" ("Larger text") in the settings makes the text in the app and in forms larger.

The app was checked with automated accessibility tests (WCAG 2.2 AA) and keyboard tests. Tests with
screen readers by people are still to come. Please tell your office about anything that does not work
for you.

## Your personal data

The app has no screen to download your personal data or to delete your account. Ask an administrator
of your organization (an Owner or Admin): they can export your data as a ZIP file or delete your account
in the admin dashboard. Your submissions stay with your organization, because it must keep its records;
after an account is deleted, they show "Deleted user".
