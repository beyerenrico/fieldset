# 0017: Capability permissions instead of Filament Shield
- Status: accepted
- Date: 2026-10-16

## Context
§5 lists bezhansalleh/filament-shield. Shield generates per-resource permissions (`view_any_form`, …)
and policies from Filament resources. §8.6 defines a capability matrix (one row per capability)
that the API must share with the panel.

## Decision
Permissions are a fixed capability catalog (`App\Domain\Identity\Enums\Permission`, one per §8.6
row, plus `admin.access` and `forms.fill_all`). Roles are spatie roles in teams mode, seeded per
organization. Custom roles are edited in a small Filament Role resource (checkbox list of
capabilities) instead of Shield. Shield was removed from composer.json.

## Consequences
Fewer generated permissions; policies map directly to the matrix and are reused by the API.
Adding a capability = enum case + translations + matrix test row.
