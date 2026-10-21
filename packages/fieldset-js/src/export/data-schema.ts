/** Data contract export (spec §10): JSON Schema 2020-12 of the submission data (not a validator). */
import { parseDefinition, type ElementNode, type ScopeNode } from '../definition/model.ts';
import { DECIMAL_PATTERN } from '../decimal/decimal.ts';
import { getOwn, integerProp, stringProp, type JsonObject, type JsonValue } from '../json/types.ts';
import { optionValues } from '../evaluate/checks/type-checks.ts';

const DECIMAL: JsonObject = { type: 'string', pattern: DECIMAL_PATTERN.source };
const UUID: JsonObject = { type: 'string', format: 'uuid' };
const DATE_TIME: JsonObject = { type: 'string', format: 'date-time' };
const STRING: JsonObject = { type: 'string' };
const BOOLEAN: JsonObject = { type: 'boolean' };

function objectSchema(properties: Readonly<Record<string, JsonValue>>): JsonObject {
    return {
        type: 'object',
        properties,
        required: Object.keys(properties),
        additionalProperties: false,
    };
}

const SIGNATURE = objectSchema({ attachment: UUID, signerName: STRING, signedAt: DATE_TIME });
const LOCATION = objectSchema({
    lat: { type: 'number', minimum: -90, maximum: 90 },
    lng: { type: 'number', minimum: -180, maximum: 180 },
    accuracy: { type: 'number', minimum: 0 },
    capturedAt: DATE_TIME,
});

type SchemaFor = (node: ElementNode) => JsonObject;

const enumOf = (node: ElementNode): JsonObject => ({
    type: 'string',
    enum: [...optionValues(node)],
});

const attachment: SchemaFor = (node) =>
    getOwn(node.raw, 'multiple') === true ? { type: 'array', items: UUID } : UUID;

const calculated: SchemaFor = (node) =>
    (stringProp(node.raw, 'format') ?? 'number') === 'text'
        ? { type: ['string', 'null'] }
        : { type: ['string', 'null'], pattern: DECIMAL_PATTERN.source };

const SCHEMAS: Readonly<Record<string, SchemaFor>> = {
    text: () => STRING,
    textarea: () => STRING,
    email: () => ({ type: 'string', format: 'email' }),
    phone: () => ({ type: 'string', pattern: '^\\+?[0-9 ()./-]{3,32}$' }),
    number: () => DECIMAL,
    money: () => DECIMAL,
    date: () => ({ type: 'string', format: 'date' }),
    // `HH:MM(:SS)` is not RFC 3339 full-time, so `format: time` would reject valid values.
    time: () => ({ type: 'string', pattern: '^([01][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$' }),
    datetime: () => DATE_TIME,
    select: enumOf,
    radio: enumOf,
    multiselect: (node) => ({ type: 'array', items: enumOf(node), uniqueItems: true }),
    checkbox: () => BOOLEAN,
    toggle: () => BOOLEAN,
    rating: (node) => ({ type: 'integer', minimum: 1, maximum: integerProp(node.raw, 'max') ?? 5 }),
    photo: attachment,
    file: attachment,
    signature: () => SIGNATURE,
    location: () => LOCATION,
    calculated,
    hidden: () => ({ type: ['string', 'integer', 'boolean'] }),
    repeater: (node) => ({
        type: 'array',
        items: node.rowScope === null ? {} : scopeSchema(node.rowScope),
    }),
};

function annotate(node: ElementNode, schema: JsonObject): JsonObject {
    const label = stringProp(node.raw, 'label');
    const role = stringProp(node.raw, 'role');
    return {
        ...schema,
        ...(label === undefined ? {} : { title: label }),
        ...(role === undefined ? {} : { 'x-role': role }),
    };
}

function scopeSchema(scope: ScopeNode): JsonObject {
    const properties = [...scope.dataKeys].flatMap(([key, node]): [string, JsonValue][] => {
        const schemaFor = SCHEMAS[node.type];
        return schemaFor === undefined ? [] : [[key, annotate(node, schemaFor(node))]];
    });
    return {
        type: 'object',
        properties: Object.fromEntries(properties),
        additionalProperties: false,
    };
}

/** JSON Schema 2020-12 describing the data of a definition (types and structure only). */
export function exportDataSchema(definition: unknown): JsonObject {
    const model = parseDefinition(definition);
    const raw = model.definition as unknown as Readonly<Record<string, unknown>>;
    const title = stringProp(raw, 'title');
    return {
        $schema: 'https://json-schema.org/draft/2020-12/schema',
        ...(title === undefined ? {} : { title }),
        ...scopeSchema(model.root),
    };
}
