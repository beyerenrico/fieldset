# Fieldset Spec 1.0

Status: **1.0.0** · License: MIT · Meta-schema: [`meta-schema.json`](meta-schema.json) (JSON Schema 2020-12)
Meta-schema `$id`: `https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json`
Meta-schema SHA-256: `fc73ec9a396356008df49fd872a5bd20408c8c8eb9ef09155bdc09c256b8eaeb`
Role vocabulary: [`roles.json`](roles.json) · Changes: [`CHANGELOG.md`](CHANGELOG.md) · Conformance: [`../../conformance/`](../../conformance)

The `$id` is an identifier. The docs site serves the meta-schema at that URL, but validators are advised to bundle a
copy of `meta-schema.json`, checked against the SHA-256 above, rather than fetch it at runtime.

The key words MUST, MUST NOT, SHOULD and MAY are used as in RFC 2119. Two implementations (PHP
`packages/fieldset-php`, TypeScript `packages/fieldset-js`) MUST produce identical results for
every conformance case.

## 1. Concepts

A **Form Definition** is one JSON document that describes structure, logic and presentation hints of a
form. A **submission** is a JSON object with the data entered for a definition. Submission data is keyed
by element `key`; repeaters produce arrays of row objects. Layout containers (`section`, `columns`) and
pages do **not** create data nesting.

A **scope** is the root of the submission or one repeater row. Keys MUST be unique within a scope
(including keys of elements inside sections, columns and pages of that scope). Element `id`s MUST be
unique across the whole definition.

## 2. Definition

| Property | Type | Required | Notes |
|---|---|---|---|
| `$schema` | string (URI) | no | URL of this meta-schema |
| `specVersion` | string | yes | `1.0.0` (any `1.0.x` is accepted by 1.0 implementations) |
| `key` | key | yes | snake_case identifier of the form |
| `title` | string | yes | |
| `description` | string | no | |
| `locale` | string | yes | BCP 47 tag, e.g. `de-DE`. Base language of all labels |
| `settings` | object | no | see below |
| `pages` | array | yes | at least one page |
| `source` | object | no | template lineage (§8) |
| `x-*` | any | no | extensions (§9) |

`settings`:

| Property | Type | Default | Notes |
|---|---|---|---|
| `layout` | `wizard` \| `scroll` | `scroll` | one page at a time, or one long page |
| `numbering` | object | none | `{ "format": "SR-{YYYY}-{SEQ:5}", "reset": "never" \| "yearly" \| "monthly" }` (§7.3) |
| `document.design` | string | `default` | key of the document design used for renditions |
| `sendToCustomer` | boolean | `false` | the Field App may email the document to a recipient |

A **key** matches `^[a-z][a-z0-9_]{0,63}$`. An **id** is a UUID string (any version, lowercase or
uppercase hex).

Page: `{ "id"?, "key", "title", "description"?, "visibleIf"?, "elements": [...] }`. Page keys are unique
among pages; they are not data keys.

## 3. Elements

### 3.1 Common properties

| Property | Type | Notes |
|---|---|---|
| `id` | id | required; builder identity, stable across versions |
| `type` | string | required; §3.2 |
| `key` | key | required; immutable once published (§6) |
| `label` | string | required for data elements; optional for layout |
| `help`, `placeholder` | string | |
| `required` | boolean | default `false`; applies only while visible |
| `visibleIf`, `requiredIf`, `readOnlyIf` | logic | JsonLogic expression or boolean (§4) |
| `default` | logic | JsonLogic expression; literals are valid JsonLogic (§5.2) |
| `validation` | object | type-specific constraints (§3.3) |
| `pdf` | object | `hidden` (bool), `label` (string), `width` (`full`\|`half`\|`third`), `keepTogether` (bool) |
| `role` | string | semantic role from `roles.json` (§8) |
| `i18n` | object | label maps (§8) |
| `x-*` | any | extensions (§9) |

`readOnlyIf` is a presentation hint in 1.0: renderers disable the input; validators do not enforce it.

### 3.2 Types and data

| Type | Extra properties | Data value |
|---|---|---|
| `text` | | string |
| `textarea` | `rows` (int 2–20) | string |
| `email` | | string, a valid email address |
| `phone` | | string matching `^\+?[0-9 ()./-]{3,32}$` |
| `number` | | **decimal string** (§5.1) |
| `money` | `currency` (ISO 4217, required), `scale` (int 0–4, default 2) | decimal string, normalized to exactly `scale` fraction digits |
| `date` | | `YYYY-MM-DD` |
| `time` | | `HH:MM` or `HH:MM:SS` (24 h) |
| `datetime` | | RFC 3339 date-time with offset, e.g. `2026-10-16T09:41:00+02:00` |
| `select`, `radio` | `options` (required) | string: one option `value` |
| `multiselect` | `options` (required) | array of distinct option values |
| `checkbox` | | boolean |
| `toggle` | | boolean |
| `rating` | `max` (int 3–10, default 5) | integer 1…`max` |
| `photo`, `file` | `multiple` (bool, default false) | attachment id (UUID string); array of ids when `multiple` |
| `signature` | `signerNameFrom` (key) | `{ "attachment": id, "signerName": string, "signedAt": date-time }` |
| `location` | | `{ "lat": number, "lng": number, "accuracy": number ≥ 0, "capturedAt": date-time }` |
| `calculated` | `expression` (required), `format` (`number`\|`money`\|`text`, default `number`), `scale` (int 0–10), `currency` | computed (§5.4); client input is ignored |
| `hidden` | | string, integer or boolean; not shown to the user |
| `repeater` | `elements` (required), `display` (`cards`\|`table`, default `cards`), `minItems`, `maxItems`, `addLabel`, `itemLabel` | array of row objects |
| `section` | `elements` (required), `collapsible` (bool) | none (layout) |
| `columns` | `columns` (required): 2–4 items `{ "elements": [...] }` | none (layout) |
| `heading` | `text` (required), `level` (1–3, default 2) | none |
| `paragraph` | `text` (required; Markdown, rendered sanitized, no raw HTML) | none |
| `divider` | | none |
| `image` | `src` (required: `data:image/png\|jpeg\|webp;base64,…` or `asset:<uuid>`), `alt` | none |
| `page_break` | | none; forces a page break in PDFs only |

Options: `{ "value": string (1–100 chars), "label": string, "i18n"?: { "label": {…} } }`. Option values
MUST be unique within one element.

Reserved type names that 1.0 rejects: `record` (snapshot semantics reserved, §8), `barcode`,
`rich_text`, `lookup`.

Layout elements (`section`, `columns`, `heading`, `paragraph`, `divider`, `image`, `page_break`) carry
an `id` and a `key` but never data.

### 3.3 Validation constraints

| Constraint | Applies to | Meaning |
|---|---|---|
| `minLength`, `maxLength` | text, textarea, email, phone | length in Unicode code points |
| `pattern` | text, textarea, phone | regular expression in the restricted ECMAScript syntax of §3.4, implicitly unanchored |
| `min`, `max` | number, money | inclusive bounds (JSON numbers or decimal strings) |
| `min`, `max` | date, datetime, time | inclusive bounds as value strings of the same type |
| `min`, `max` | multiselect | number of selected options |
| `step` | number, money | value − (`min` or 0) MUST be an exact multiple of `step` |
| `accept` | photo, file | array of MIME types or `type/*` wildcards |
| `maxFiles` | photo, file (multiple) | maximum number of attachments |
| `maxSizeKb` | photo, file, signature | enforced by the server on upload, not by the validator |

Numeric constraint values in a definition MAY be JSON numbers with at most 15 significant digits.

Checks of one element run in this order and stop at the first failure: type/format → `scale` →
`min_length` → `max_length` → `pattern`, and `min` → `max` → `step` (numbers, money, dates, counts).

### 3.4 Pattern syntax and semantics

`pattern` uses a **restricted subset of ECMAScript regular expressions** so every implementation
matches identically and no pattern can trigger catastrophic backtracking. Anything outside the subset
is a lint error (`invalid_pattern`); evaluators treat an invalid pattern as "no constraint".

- **Atoms:** literal characters except the syntax characters `^ $ \ . * + ? ( ) [ ] { } |`; `.`;
  character classes `[…]` / `[^…]` with literals, ranges `a-z` and the class escapes below (an unescaped
  `[` inside a class is invalid); escapes
  `\d \D \w \W \s \S`, `\t \n \r \f \v`, `\0` (not followed by a digit), `\xHH`, `\uHHHH`,
  `\u{H…}` (≤ 10FFFF), and an escaped syntax character or `/` or `-`. `\b` / `\B` are assertions
  outside classes and invalid inside them.
- **Structure:** `^`, `$`, groups `(…)`, `(?:…)`, lookaheads `(?=…)`, `(?!…)`, alternation `|`.
- **Quantifiers:** `*`, `+`, `?`, `{n}`, `{n,}`, `{n,m}` with `n ≤ m ≤ 1000`, each optionally followed by
  `?` (lazy).
- **Not allowed:** backreferences, named groups, lookbehind, inline flags, Unicode property escapes,
  and any other syntax; a quantifier other than `?`/`{0,1}` applied to a group that contains a
  quantifier or an alternation (e.g. `(a+)+`, `(a|b)*`, `(\d{3}-){2}`).
- **Semantics:** ECMAScript with the `u` flag, case-sensitive, no multiline/dotAll: matching works on
  code points; `.` matches any code point except `\n \r \u2028 \u2029`; `^`/`$` match only at the start
  and end of the value; `\d` = `[0-9]`, `\w` = `[A-Za-z0-9_]`, `\b` uses that `\w`; `\s` = the
  ECMAScript WhiteSpace and LineTerminator set (`\t \n \v \f \r`, space, U+00A0, U+1680,
  U+2000–U+200A, U+2028, U+2029, U+202F, U+205F, U+3000, U+FEFF — not U+0085 or U+180E).
- **Size:** a value longer than 4,096 code points fails the `pattern` check; if an engine hits an internal
  matching limit the check fails as well (fail closed).

## 4. Logic (JsonLogic dialect)

All logic properties take a **JsonLogic** expression (jsonlogic.com) or a boolean. Implementations MUST
implement the dialect below exactly; they MUST NOT rely on host-language comparison or truthiness.

### 4.1 Truthiness and equality

- Falsy: `false`, `null`, `0`, `""`, `[]`. Everything else is truthy (including `"0"` and `{}`).
- `==` / `!=` use JavaScript abstract equality restricted to JSON values: `null == null`; a number and a
  string compare as numbers (`Number(string)` rules: whitespace trimmed, empty string = 0, `0x`/exponent
  forms accepted, unparsable = NaN); a boolean converts to 1/0 first; arrays (also array vs array)
  compare by converting to their comma-joined string; objects are never equal.
- `===` / `!==` compare type and value; arrays and objects are never strictly equal.
- `<`, `<=`, `>`, `>=`: if both operands are strings, compare by Unicode code points; otherwise convert
  both to numbers (`null` → 0, booleans → 1/0, numeric strings → number, other → NaN; any NaN → false).
  `<` and `<=` accept three operands (between).

### 4.2 Supported operators

`var`, `missing`, `missing_some`, `if` (alias `?:`), `==`, `===`, `!=`, `!==`, `!`, `!!`, `or`, `and`,
`<`, `<=`, `>`, `>=`, `in`, `cat`, `substr`, `merge`, `map`, `filter`, `reduce`, `all`, `none`, `some`,
`+`, `-`, `*`, `/`, `%`, plus the custom operators of §5.3. `min` and `max` are replaced by the decimal
versions of §5.3. Any other operator is a lint error (`unknown_operator`).

`or`/`and` return the deciding operand (not a boolean) and short-circuit. `if` evaluates only the chosen
branch. `!`/`!!` apply truthiness to their first argument (or the argument itself if not an array).
`in` tests substring (string haystack) or membership (array haystack, strict equality). `cat` joins the
string forms (`null` → `""`, numbers in shortest round-trip form, booleans `true`/`false`); `in` converts a
non-string needle with JavaScript `String()`; `substr` counts code points. `+ - * / %` operate on IEEE-754
doubles (NaN or infinite results become `null`) and MUST NOT be used on number or money values (lint
error). Unknown operators and nesting deeper than 512 levels (counting nested objects and arrays; a scalar is 0,
`{"!": true}` is 1) are evaluation errors; evaluators treat the
whole expression as `null`. Definitions and submission data nested deeper than 1,024 levels (objects and arrays) are rejected when
they are decoded.

### 4.3 Variables and scope

`{"var": "a.b"}` reads a dotted path; `{"var": ["a", fallback]}` returns `fallback` when the value is
`null`/missing; `{"var": ""}` returns the whole scope object. Numeric path segments index arrays.

Evaluation scope for an element:

1. Built-ins: `$today` (`YYYY-MM-DD`), `$now` (RFC 3339), `$user` `{name, email}`, `$org` `{name}`,
   `$form` `{version}` — supplied by the host as evaluation context (in the organization's time zone).
2. `$root`: the root data object.
3. The root scope: every key defined at root level (value or `null`).
4. Inside a repeater row: every key defined in that row, shadowing outer keys with the same name; nested
   repeaters shadow again. `$row` is the current row object.

Resolution is by **definition**, not by data presence: a key defined in the row resolves to the row's
value even when that value is `null`. Inside `map`/`filter`/`reduce`/`all`/`none`/`some`, `var` resolves
against the current item (`reduce`: `current`, `accumulator`) exactly as in JsonLogic.

Values of invisible elements (§5.5) resolve to `null`.

## 5. Values, calculations and validation

### 5.1 Decimal strings

`number` and `money` values are **decimal strings** matching `^-?(0|[1-9][0-9]*)(\.[0-9]+)?$` with at
most **60 digits** in total (integer plus fraction digits). Longer strings are `invalid_number`.
JSON numbers are rejected for these types (`decimal_string_required`). Canonical output form: no exponent,
no `+`, `-0` normalized to `0` (keeping scale, e.g. `0.00`).

### 5.2 Defaults

When a visible element's value is missing or `null`, its `default` expression is evaluated in the
element's scope and the result (if not `null`) becomes the value before validation. Defaults are not
applied to `calculated` elements.

### 5.3 Custom operators

Operands of decimal operators are decimal strings of at most 60 digits, JSON integers (a JSON number
whose IEEE-754 double value is integral, such as `2` or `2.0`, with magnitude ≤ 2^53), or `null`. Anything else (JSON numbers with a fractional part, invalid
strings, booleans, arrays, objects) counts as `null`. Aggregates (`sum`, `min`, `max`, `count`) treat a
non-array first operand as an empty array (for `min`/`max` see the table).

| Operator | Result |
|---|---|
| `dec.add` / `dec.sub` `[a, b, …]` | exact; result scale = largest input scale; `null` if any operand is `null` |
| `dec.mul` `[a, b, …]` | exact; result scale = sum of input scales; `null` if any operand is `null` |
| `dec.div` `[a, b, scale]` | rounded half-up (away from zero) to `scale` (required JSON integer 0–20, else `null`); `null` if `b` is 0 or an operand is `null` |
| `dec.round` `[a, scale]` | rounded half-up to `scale` (JSON integer 0–20, else `null`) |
| `dec.eq`, `dec.gt`, `dec.gte`, `dec.lt`, `dec.lte` `[a, b]` | numeric comparison; `false` if either is `null` |
| `sum` `[array, rowKey?]` | decimal sum of `array[i][rowKey]` (or of the items when `rowKey` is omitted), skipping `null`; `"0"` for an empty array; scale = largest item scale |
| `min` / `max` `[array, rowKey?]` or `[a, b, …]` | aggregate form when the first operand is an array or the only operand, otherwise variadic over all operands; smallest/largest decimal, skipping `null`; `null` if none; the result keeps the winning item's own scale, ties keep the first |
| `count` `[array]` | number of non-`null` items as an integer |
| `date.diff` `[from, to, unit]` | integer `to − from`, truncated toward zero. Dates: `days`, `weeks`, `months`, `years`. Date-times: `minutes`, `hours`, `days` (24 h). Mixed or invalid input → `null` |
| `date.add` `[value, amount, unit]` | date (or date-time keeping its offset) plus integer `amount` (JSON integer or integer string); months/years clamp the day to the month's end. Units as for `date.diff`. Date-times are output as `YYYY-MM-DDTHH:MM:SS` plus the input's offset (`Z` stays `Z`), without fractional seconds |

`date.diff` on date-times includes fractional seconds before truncating. Month difference between dates `a ≤ b`: `(yb − ya) × 12 + (mb − ma)`, minus 1 when `db < da`;
`date.diff(b, a) = −date.diff(a, b)`; `years = trunc(months / 12)`; `weeks = trunc(days / 7)`.

### 5.4 Calculated elements

A `calculated` element's value is its `expression` evaluated in its scope, then:

- `format: number` / `money`: converted to a decimal (non-decimal results become `null`), rounded half-up
  to `scale` (default 2 for money, 6 for number) and stored with **exactly** `scale` fraction digits.
- `format: text`: `null` stays `null`, strings stay, other values become their `cat` string form.

Client-supplied values for calculated elements are discarded. Calculations run in dependency order
(row-level calculations before aggregates that reference their repeater). A definition whose calculated
elements depend on each other cyclically is invalid (lint `cycle`).

### 5.5 Evaluation algorithm

Given a definition, input data and context, a conforming evaluator:

1. **Normalizes** the input: drops keys not defined in the scope (recursively in rows; layout keys are
   never data keys); a repeater value that is not an array becomes `[]` and reports `type_mismatch`;
   non-object rows are dropped with `type_mismatch` at the row pointer (later rows shift down; a `null`
   repeater counts as empty). Normalization errors are reported even if the repeater ends up invisible
   and in addition to validation errors; money values that pass the type
   check are rewritten with exactly `scale` fraction digits. Logic sees a **view** of the data in which
   values failing their element's type check (§5.6, codes up to `invalid_location` in the table) are
   `null`; the data itself keeps them for error reporting.
2. Sets every `calculated` value to `null`, then repeats up to 5 rounds until visibility **and** the data
   (calculated values and applied defaults) stop changing:
   a. compute visibility top-down in document order: a page, container or element is visible when its own
      `visibleIf` is truthy (missing = visible) and its parent is visible; inside repeaters per row. Each
      decision is visible to later elements of the same pass (an element found invisible reads as `null`);
   b. apply defaults (§5.2) to visible elements;
   c. compute calculated values in dependency order, treating invisible values as `null`. The order is a
      topological sort of the **static** dependencies (a `var` — including `$root.x` — whose first path
      segment resolves through the scope chain to another calculated element, or to a repeater, which
      depends on every calculated element inside its rows; references inside per-item arguments of
      iteration operators, computed paths and whole-scope references do not count); among elements that are ready,
      the earliest in document order goes first. Each value sees the values computed before it in the
      same round. Missing `today`/`now` context values resolve to `null`.
3. **Strips** invisible elements' values from the data (keys removed; invisible repeaters removed with
   their rows).
4. **Validates** every visible data element (§5.6) in document order, rows in index order.

Output:

- `data`: the normalized, stripped data. A key is present when the input supplied it, a default filled
  it, or it is a visible `calculated` element (always present, possibly `null`).
- `errors`: compared by conformance runners as a set of `(pointer, code)` pairs.
- `visibility`: a map from the scope pointer of every element (layout elements included, e.g.
  `/details` or `/items/0/note`) to a boolean.

The `hidden` type keeps a client-supplied string, integer or boolean; otherwise its `default` applies.

### 5.6 Validation rules and error codes

Errors are objects `{ "pointer": "/items/2/qty", "code": "...", "params"?: {...} }`. Pointers are JSON
Pointers (RFC 6901) into the submission data. Codes are stable and localized by clients.

An element is **required** when `required` is true or `requiredIf` is truthy. A value is **empty** when
it is missing, `null`, `""`, or `[]`. Empty values are valid unless required — except that a repeater's
`minItems` also applies to an empty or missing list (`min_items`). Type checks run before constraints;
the first failing check of an element is reported (one validation error per element per value).
`rating` has no type check: any non-integer or out-of-range value is `out_of_range`. Attachment shape
errors are always `invalid_attachment`. A non-object signature or location is `type_mismatch`; objects
must have exactly the documented members, otherwise `invalid_signature` / `invalid_location`. A
`hidden` value that is not a string, integer or boolean is replaced by its default when one applies,
otherwise `type_mismatch`. Email values longer than 254 code points are `invalid_email`; shorter ones
are matched against the email pattern with the §3.4 semantics. Location numbers must be finite. Type
checks run before the required check for non-empty values (a required checkbox holding `"true"` is
`type_mismatch`).

| Code | When |
|---|---|
| `required` | required and empty; for `checkbox`: required and not `true`; for `repeater`: required with no rows |
| `type_mismatch` | wrong JSON type (e.g. number for text, string for checkbox, object for select) |
| `decimal_string_required` | JSON number given for number/money |
| `invalid_number` | string that is not a decimal string |
| `scale_exceeded` | money value with more fraction digits than `scale` (params `scale`) |
| `invalid_email`, `invalid_phone`, `invalid_date`, `invalid_time`, `invalid_datetime` | malformed value. Email: `^[^\s@]+@[^\s@]+\.[^\s@]+$`. Date: a real calendar date. Time: `HH:MM` or `HH:MM:SS`, hours 00–23. Date-time: `YYYY-MM-DDTHH:MM:SS(.fraction)?(Z\|±HH:MM)` with a real date and time |
| `invalid_option` | value (or any multiselect item) not among the options; duplicates in multiselect |
| `invalid_attachment` | attachment id not a UUID; non-array for `multiple`, array for single |
| `invalid_signature`, `invalid_location` | malformed object |
| `min_length` / `max_length` | params `limit` |
| `pattern` | value does not match `pattern` |
| `min` / `max` | numeric, date/time or count bound violated; params `limit` |
| `step` | not a multiple of `step`; params `step` |
| `min_items` / `max_items` | repeater row count or `maxFiles`; params `limit` |
| `out_of_range` | rating outside 1…`max` or non-integer |

## 6. Versioning rules

- Keys are immutable once an element `id` appears in a published version (`key_locked`).
- A key that exists in a published version MUST keep its type (`key_type_changed`).
- A published version is immutable. Rollback creates a new version with copied content.
- The **checksum** of a definition is the lowercase hex SHA-256 of its canonical JSON: object keys sorted
  by code point, no insignificant whitespace, `/` not escaped, non-ASCII characters unescaped (UTF-8),
  numbers in shortest round-trip form.

## 7. Linting

Lint results: `{ "severity": "error" | "warning", "code", "path", "params"? }`, where `path` is a JSON
Pointer into the **definition**. A definition with lint errors MUST NOT be published.

| Code | Severity | Rule |
|---|---|---|
| `duplicate_key` | error | key not unique in its scope |
| `duplicate_page_key` | error | page key not unique |
| `duplicate_id` | error | element id not unique in the definition |
| `duplicate_option_value` | error | option value repeated in an element |
| `unknown_reference` | error | `var` path, `signerNameFrom`, or `sum`/`min`/`max` row key that resolves to no defined key or built-in |
| `unknown_operator` | error | operator outside §4.2 / §5.3 |
| `decimal_builtin_operator` | error | `+ - * / % < <= > >= == != === !==` with an operand that is a `var` of a number, money or numeric calculated element |
| `float_literal` | error | non-integer JSON number literal as an operand of a decimal operator (§5.3) |
| `cycle` | error | cyclic dependency between calculated elements |
| `invalid_numbering_format` | error | numbering format invalid (§7.3) |
| `invalid_repeater_bounds` | error | `minItems` > `maxItems` |
| `invalid_pattern` | error | `pattern` is outside the §3.4 subset |
| `logic_too_deep` | error | a logic expression nests deeper than 512 levels |
| `reserved_type` | error | element type reserved for later versions (e.g. `record`) |
| `key_locked` | error | element id published earlier with a different key |
| `key_type_changed` | error | key published earlier with a different type |
| `unknown_role` | warning | `role` not in `roles.json` |
| `duplicate_role` | error | same `role` twice in one scope (repeater rows are their own scope) |
| `empty_page` | warning | page without elements |

`path` points at the offending property (for logic: the logic property itself, e.g.
`/pages/0/elements/3/visibleIf`; `reserved_type` at `/type`; `duplicate_id` at the element). References
must resolve to **data** keys (layout keys are not data); built-in objects check their member
(`$user.name|email`, `$org.name`, `$form.version`). Whole-scope references (`""`, `$root`, `$row`) add no
calculation dependencies. References inside the per-item argument of `map`, `filter`, `reduce`,
`all`, `none` and `some` are not reference-checked. A cycle is reported once, at the first calculated
element of the cycle in document order (pre-order traversal of pages and elements). Duplicates are
reported at the second and later occurrences.

`key_locked` and `key_type_changed` need the published history; linters accept it as an optional list of
earlier definitions.

### 7.3 Numbering format

Tokens: `{YYYY}`, `{YY}`, `{MM}`, `{SEQ:n}` (n = 1–12, zero-padded width). `{SEQ:n}` MUST appear exactly
once; literal text is `[A-Za-z0-9._/-]`. `reset: yearly` requires `{YYYY}` or `{YY}`; `reset: monthly`
requires a year token and `{MM}`.

## 8. Reserved for later features (no behavior in 1.0 beyond storage and validation)

- **`role`** (D11): string from `roles.json`. Webhook payloads and exports include each value's role.
- **`source`** (D12): `{ "registry": URI, "template": key, "version": semver }`; set on template import,
  kept on clone.
- **`i18n`** (D13): `{ "label"?: {lang: string}, "help"?: {...}, "placeholder"?: {...} }` on elements and
  `{ "label"?: {...} }` on options. 1.0 renderers use only the base `locale`.
- **`record`** (D10): when it ships, selecting a record **copies** its values into the submission
  (snapshot), never a live reference. 1.0 rejects the type.

## 9. Extensions

Properties starting with `x-` are allowed on the definition, pages, elements and options and MUST be
preserved by tools that round-trip definitions. Documented extensions:

- `x-import` (element): `{ "source": "acroform", "field": string, "page": int, "rect": [x1, y1, x2, y2],
  "confidence": 0–1 }` — provenance of elements created by the fillable-PDF importer.

## 10. Data contract export

Implementations SHOULD export a JSON Schema 2020-12 describing the **data** of a definition (types and
structure only; not a validator): root object with one property per data element; repeaters as arrays of
objects; number/money as strings with the decimal pattern; `date`/`date-time` as `format`, `time` as a
pattern (JSON Schema's `time` format requires an offset). The schema describes non-empty values.
