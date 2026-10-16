# 0021: Own JsonLogic dialect evaluator in both SDKs (json-logic-php/js not used)
- Status: accepted
- Date: 2026-10-16

## Context
§5/§7.1 suggest wrapping jwadhams/json-logic-php 1.5.1 and json-logic-js 2.0.5 and proving parity
with a shared conformance suite. Inspection (2026-10-16): json-logic-php evaluates truthiness with PHP
rules (`"0"` is falsy, JS/JsonLogic say truthy), uses PHP loose `==`/`<` (`"1.00" == "1"` is true,
numeric strings compare numerically), and special-cases `if`/`and`/`or` before custom operations so
they cannot be overridden. Both libraries are dormant since 2024-07. Overriding most core operators
would leave little of either library while still inheriting its edge cases.

## Decision
Each SDK ships a small evaluator for the JsonLogic dialect normatively specified in the Form Schema
Spec 1.0 README §4–§5 (JS truthiness, JS abstract equality restricted to JSON values, code-point string
comparison, our decimal/date/aggregate operators replacing `min`/`max`). It sits behind a
`LogicEvaluator` interface in each package. The shared conformance suite (`spec/conformance/logic`)
pins every operator, including the official JsonLogic semantics for the supported subset.

## Consequences
~300 lines per language to maintain instead of two dormant dependencies; parity is enforced by CI.
jwadhams/json-logic-php is removed from composer.json; json-logic-js is not installed.
