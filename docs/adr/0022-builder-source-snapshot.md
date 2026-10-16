# 0022: Builder blocks carry a `_source` snapshot instead of only `_extra`
- Status: accepted
- Date: 2026-10-16

## Context
§8.2: Filament drops undeclared state keys, so each block needs a hidden `id` and a hidden `_extra`
with unmapped properties, and the Builder ↔ spec mapping must be lossless. Livewire also turns `{}`
into `[]` and numbers into strings, and Toggle fields cannot distinguish `false` from "absent".

## Decision
Every page, column, option and element block holds `_source`: its original JSON (minus children) as a
string. When mapping back, a property whose builder value still equals the value derived from
`_source` is written back verbatim (type, `{}`/`[]`, explicit `false` preserved); edited values go
through `FieldCodec`. Unmapped and `x-*` properties therefore survive untouched. A repeated element id
marks a Filament clone: it gets a new UUIDv7 and a `_copy` key suffix; ids of published elements keep
their keys (`key_locked`).

## Consequences
Lossless round trips are proven over every template and conformance definition
(`tests/Unit/Forms/BuilderMapperTest.php`). `_source` grows the Livewire payload roughly 2×; acceptable
for v1 (Builder v2 in M7 edits JSON directly).
