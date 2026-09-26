/**
 * Validator for the JSON Schema subset used in LexRanked's structured AI
 * outputs (type incl. nullable unions, properties/required/
 * additionalProperties:false, items, enum, min/max, min/maxLength,
 * min/maxItems). The API's strict mode already constrains the model; every
 * response is validated again here because model output is never trusted.
 * In-house to avoid a dependency for ~80 lines of well-defined logic.
 */

export type JsonSchema = {
  type?: string | string[];
  properties?: Record<string, JsonSchema>;
  required?: string[];
  additionalProperties?: boolean;
  items?: JsonSchema;
  enum?: unknown[];
  minimum?: number;
  maximum?: number;
  minLength?: number;
  maxLength?: number;
  minItems?: number;
  maxItems?: number;
  description?: string;
};

function typeOf(value: unknown): string {
  if (value === null) return 'null';
  if (Array.isArray(value)) return 'array';
  if (typeof value === 'number') return Number.isInteger(value) ? 'integer' : 'number';
  return typeof value;
}

export function validateJson(schema: JsonSchema, value: unknown, path = '$'): string[] {
  const errors: string[] = [];
  const actual = typeOf(value);
  if (schema.type !== undefined) {
    const allowed = Array.isArray(schema.type) ? schema.type : [schema.type];
    const ok = allowed.includes(actual) || (actual === 'integer' && allowed.includes('number'));
    if (!ok) return [`${path}: expected ${allowed.join('|')}, got ${actual}`];
  }
  if (schema.enum !== undefined && !schema.enum.some((e) => e === value)) {
    errors.push(`${path}: not one of the allowed values`);
  }
  if (typeof value === 'string') {
    if (schema.minLength !== undefined && value.length < schema.minLength) errors.push(`${path}: shorter than ${schema.minLength}`);
    if (schema.maxLength !== undefined && value.length > schema.maxLength) errors.push(`${path}: longer than ${schema.maxLength}`);
  }
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) errors.push(`${path}: not a finite number`);
    if (schema.minimum !== undefined && value < schema.minimum) errors.push(`${path}: below ${schema.minimum}`);
    if (schema.maximum !== undefined && value > schema.maximum) errors.push(`${path}: above ${schema.maximum}`);
  }
  if (Array.isArray(value)) {
    if (schema.minItems !== undefined && value.length < schema.minItems) errors.push(`${path}: fewer than ${schema.minItems} items`);
    if (schema.maxItems !== undefined && value.length > schema.maxItems) errors.push(`${path}: more than ${schema.maxItems} items`);
    if (schema.items) value.forEach((v, i) => errors.push(...validateJson(schema.items as JsonSchema, v, `${path}[${i}]`)));
  }
  if (actual === 'object') {
    const obj = value as Record<string, unknown>;
    for (const key of schema.required ?? []) {
      if (!(key in obj)) errors.push(`${path}.${key}: required`);
    }
    for (const [key, v] of Object.entries(obj)) {
      const sub = schema.properties?.[key];
      if (sub) errors.push(...validateJson(sub, v, `${path}.${key}`));
      else if (schema.additionalProperties === false) errors.push(`${path}.${key}: not allowed`);
    }
  }
  return errors;
}

/**
 * Checks a schema meets the strict-mode rules we rely on: every object lists
 * all its properties as required and forbids additional ones.
 */
export function assertStrictSchema(schema: JsonSchema, path = '$'): void {
  const types = Array.isArray(schema.type) ? schema.type : [schema.type];
  if (types.includes('object')) {
    const props = Object.keys(schema.properties ?? {});
    if (schema.additionalProperties !== false) throw new Error(`${path}: additionalProperties must be false`);
    const required = new Set(schema.required ?? []);
    for (const p of props) {
      if (!required.has(p)) throw new Error(`${path}.${p}: must be required in strict mode`);
      assertStrictSchema((schema.properties as Record<string, JsonSchema>)[p] as JsonSchema, `${path}.${p}`);
    }
  }
  if (schema.items) assertStrictSchema(schema.items, `${path}[]`);
  if (schema.enum !== undefined && schema.enum.length === 0) throw new Error(`${path}: enum must not be empty`);
}
