# fieldset-php

Reference PHP implementation of the **Fieldset Spec 1.0**
([`spec/form-definition/1.0/README.md`](../../spec/form-definition/1.0/README.md)): meta-schema validation,
linter, JsonLogic dialect, evaluator and data contract export. Framework-free, PHP 8.4.

Every case of [`spec/conformance`](../../spec/conformance) passes (`tests/ConformanceTest.php`).

## Install

The package is consumed through a Composer path repository (`packages/fieldset-php`). Runtime
dependencies: `brick/math` ^1.0 (decimals) and `opis/json-schema` ^2.6 (meta-schema, draft 2020-12).

## JSON representation

All APIs take and return JSON exactly as `json_decode($json, false)` produces it: objects are
`stdClass`, arrays are PHP lists, numbers are `int|float`. This keeps `{}` and `[]` apart, which matters
for truthiness and for checksums. A JSON number with an integral value up to 2^53 counts as an integer
everywhere (`1.0` behaves like `1`).

```php
use Fieldset\Json\Json;

$definition = Json::decode($jsonString);
Json::encode($value);     // compact, unescaped slashes and unicode
Json::canonical($value);  // spec §6: sorted keys, shortest numbers (1.0 → 1)
Json::checksum($value);   // lowercase hex SHA-256 of the canonical JSON
```

## Usage

```php
use Fieldset\Definition\Definition;
use Fieldset\Evaluation\EvaluationContext;
use Fieldset\Evaluation\Evaluator;
use Fieldset\Export\DataSchemaExporter;
use Fieldset\Lint\Linter;
use Fieldset\Logic\JsonLogic;
use Fieldset\Validation\MetaSchemaValidator;

// Structure (meta-schema.json; pass a path to use another copy)
$result = (new MetaSchemaValidator)->validate($definition);
$result->valid;   // bool
$result->errors;  // list<array{path: string, message: string}>

// Semantics (§7); published versions enable key_locked / key_type_changed
$findings = (new Linter)->lint($definition, $publishedDefinitions);
Linter::isPublishable($findings); // false when any finding is an error

// Logic (§4, §5.3)
(new JsonLogic)->evaluate(Json::decode('{"dec.add": ["0.1", "0.2"]}'), new stdClass); // "0.3"

// Evaluation (§5.5)
$context = new EvaluationContext(today: '2026-10-16', now: '2026-10-16T09:41:00+02:00',
    userName: 'Willi Worker', userEmail: 'worker@demo.test', orgName: 'Demo GmbH', formVersion: 3);
$result = (new Evaluator)->evaluate($definition, $submission, $context);
$result->data;        // stdClass: normalized, stripped data incl. calculated values
$result->errors;      // list<ValidationError{pointer, code, params}>
$result->visibility;  // array<string, bool> keyed by scope pointer, e.g. "/items/0/note"

// Data contract (§10): JSON Schema 2020-12 of the submission data
$schema = (new DataSchemaExporter)->export($definition);

// Model: immutable value objects that keep the raw JSON for lossless output
$model = Definition::fromJson($definition);
foreach ($model->elements as $element) {
    $element->path;     // "/pages/0/elements/2"
    $element->scopeId;  // '' (root) or the path of the enclosing repeater
}
```

`EvaluationContext::fromArray()` accepts the conformance shape
(`today`, `now`, `user {name, email}`, `org {name}`, `formVersion`).

## Layout

| Namespace | Contents |
|---|---|
| `Json` | decode/encode, canonical JSON and checksum, JavaScript number formatting, JSON Pointer |
| `Logic` | `LogicEvaluator`, `JsonLogic`, operator families (`Operators/*`), `Decimal`, date math |
| `Definition` | `Definition`, `Page`, `Element`, `Scope`, reference resolution, calculation dependency graph |
| `Validation` | `MetaSchemaValidator` (opis/json-schema) |
| `Lint` | `Linter`, one class per rule family (`Rules/*`), numbering format, role vocabulary |
| `Evaluation` | `Evaluator` and its passes: normalize, visibility, defaults, calculations, strip, validate |
| `Export` | `DataSchemaExporter` |

## Decisions where the spec leaves room

These readings match the TypeScript SDK (`packages/fieldset-js`, README "Implementation notes");
both SDKs were cross-checked on ~40,000 generated logic, evaluation, lint and export inputs.

- JSON numbers behave like JavaScript doubles: integers beyond 2^53 are rounded as `JSON.parse`
  would (also in canonical JSON), integral floats such as `3.0` count as integers everywhere.
- `==` compares arrays through their comma-joined string (also array vs array); objects never
  equal; a missing operand equals nothing. `+ - * / %` convert with `Number()` rules and return
  `null` for NaN/infinite results.
- `min`/`max` use the aggregate form when the first operand is an array or the only operand;
  `dec.div`/`dec.round` scales must be JSON integers 0–20; date-time differences use exact
  fractional seconds.
- `JsonLogic::evaluate()` returns `null` for evaluation errors (unknown operator, nesting deeper
  than 512 levels of objects/arrays); `evaluateStrict()` throws the `LogicError` instead.
  `Json::decode()` rejects documents nested deeper than 1,024 levels.
- `pattern` uses the restricted ECMAScript subset of §3.4 (`Support\Pattern\PatternParser`); valid
  patterns are translated to PCRE with every class spelled out as code point ranges, values over
  4,096 code points and engine errors fail closed.
- Decimal strings hold at most 60 digits; calculations run in topological order with ready
  elements taken in document order (a repeater reference depends on its row calculations).
- Missing `today`/`now` in the context resolve to `null`.
- Normalization errors are always reported (before validation errors); a `null` repeater is empty;
  repeater `minItems` applies to an empty list; `rating` has no type check (`out_of_range`);
  attachment shape errors are `invalid_attachment`; non-object signature/location values are
  `type_mismatch` and objects must have exactly the documented members; an invalid `hidden` value
  is replaced by its default, otherwise `type_mismatch`.
- The logic view keeps empty values (`""`, `[]`) and nulls non-empty values that fail their type check.
- Unknown and reserved element types still count as data keys. Lint checks only the first data key
  of a `var` path; `reserved_type` points at `<element>/type`, `duplicate_id` at the element.
- Out-of-range `scale` values (money outside 0–4, calculated outside 0–10) fall back to the defaults.
- Data export describes non-empty values (no `null` except for calculated values); `time` uses a
  pattern; labels and roles are exported as `title` and `x-role`.
- `MetaSchemaValidator` drops opis follow-up `additionalProperties`/`unevaluatedProperties` errors
  of an object that also has a nested error (the result stays invalid).

## Development

```bash
vendor/bin/pest packages/fieldset-php/tests
vendor/bin/pest packages/fieldset-php/tests --group=conformance
vendor/bin/phpstan analyse -c phpstan-schema.neon --memory-limit=1G --no-progress
vendor/bin/pint packages/fieldset-php
```
