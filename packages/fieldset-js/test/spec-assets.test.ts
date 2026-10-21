import { describe, expect, it } from 'vitest';
import { validateDefinition } from '../src/index.ts';
import { metaSchema } from '../src/spec-assets.ts';
import { el, form } from './helpers.ts';

const META_SCHEMA_ID = 'https://beyerenrico.github.io/fieldset/spec/form-definition/1.0/meta-schema.json';
/** The pre-rename $id; `$schema` is pattern-only, so definitions naming it stay valid. */
const LEGACY_META_SCHEMA_ID = 'https://localhost/spec/form-definition/1.0/meta-schema.json';

describe('meta-schema identity', () => {
    it('carries the Fieldset $id and title', () => {
        expect(metaSchema.$id).toBe(META_SCHEMA_ID);
        expect(metaSchema.title).toBe('Form Definition (Fieldset Spec 1.0)');
    });

    it.each([META_SCHEMA_ID, LEGACY_META_SCHEMA_ID, 'urn:fieldset:bundled-templates'])(
        'accepts $schema %s',
        ($schema) => {
            expect(validateDefinition(form([el('text', 'a')], { $schema }))).toEqual({ valid: true, errors: [] });
        },
    );
});
