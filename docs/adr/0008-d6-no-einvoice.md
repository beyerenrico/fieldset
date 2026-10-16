# 0008: D6 Invoice compliance — generic documents, no XRechnung/ZUGFeRD in v1
- Status: accepted
- Date: 2026-10-16

## Context
§0 D6 default. German B2B e-invoice issuing obligations start 2027/2028 (secondary sources).

## Decision
v1 renders a generic invoice layout only. The data model stays ready via `role` (D11) and
`company_details` (tax id, bank). No e-invoice output.

## Consequences
Organizations above the 2027 threshold need another tool for e-invoices. Logged in QUESTIONS.md.
