/** Single source of truth: the normative spec files are imported, never copied. */
import metaSchemaJson from '../../../spec/form-definition/1.0/meta-schema.json' with { type: 'json' };
import rolesJson from '../../../spec/form-definition/1.0/roles.json' with { type: 'json' };

export const metaSchema: Readonly<Record<string, unknown>> = metaSchemaJson;

/** Semantic role vocabulary (spec §8). */
export const knownRoles: ReadonlySet<string> = new Set(Object.keys(rolesJson.roles));
