---
layout: home
title: Fieldset
titleTemplate: Self-hosted digital forms
description: Fieldset is a free, self-hostable platform for digital forms. Office staff design forms; field workers fill them in on a phone or tablet, also offline.
hero:
  name: Fieldset
  text: Digital forms you host yourself
  tagline: Office staff design forms in the admin dashboard; field workers fill them in on a phone or tablet with the Field App, also offline. The server numbers each submission, renders a branded PDF and runs workflows.
# Links: `to` names a target of lib/root-home.mjs in the newest release's documentation or on this site (guides,
# install, integrations, api-contract, api-reference, changelog, spec); the build turns it into the URL.
# Under the hero (theme/HomeStart.vue), followed by the newest release's install command.
actions:
  - text: Read the guides
    to: guides
  - text: Fieldset Spec
    to: spec
    theme: alt
features:
  - title: Form builders
    details: Start from a template or a fillable PDF, build on a drag-and-drop canvas, preview on a phone frame and publish versions.
  - title: Field App, also offline
    details: Fill in, sign, photograph and submit on a phone or tablet. Drafts stay on the device; the outbox syncs exactly once.
  - title: Numbered PDFs
    details: The server assigns gap-free numbers and renders branded, immutable PDFs to send to the customer.
  - title: Workflows
    details: Triggers and conditions start reviews with reminders, emails and signed webhooks.
  - title: One command to run it
    details: Signed Docker images, installed, configured, backed up and upgraded with the fieldset command-line tool.
  - title: An open format
    details: Form definitions follow the open Fieldset Spec (MIT), with PHP and JavaScript SDKs.
# PLACEHOLDER (U9): English-UI screenshots under the Fieldset name, files in public/screenshots/. Each entry:
#   - src: screenshots/forms-list.png
#     alt: The forms list of the admin dashboard
#     caption: Forms and their versions
# The section stays hidden while the list is empty (theme/HomeMore.vue).
screenshots: []
# Under the features (theme/HomeMore.vue).
docs:
  - to: guides
    title: Guides
    details: Install, upgrade, back up and configure an instance; the admin and Field App guides.
  - to: integrations
    title: API and integrations
    details: The REST API, webhooks and signature verification.
  - to: api-contract
    title: Field App API contract
    details: The normative contract of /api/v1.
  - to: api-reference
    title: API reference
    details: The OpenAPI document of /api/v1, to browse.
  - to: spec
    title: Fieldset Spec 1.0
    details: The open JSON format for form definitions and its meta-schema.
  - to: changelog
    title: Changelog
    details: What changed in each version.
---
