export class ContractViolation extends Error {}
const plain = v => v !== null && typeof v === 'object' && !Array.isArray(v) && [Object.prototype, null].includes(Object.getPrototypeOf(v));
function unicode(value) { if ([...value].some(c => c.codePointAt(0) >= 0xd800 && c.codePointAt(0) <= 0xdfff)) throw new ContractViolation('Invalid Unicode string.'); }
function jsonValue(v, depth) {
  if (depth > 60) throw new ContractViolation('Contract nesting exceeds limit.');
  if (typeof v === 'string') unicode(v);
  if (v === null || typeof v === 'string' || typeof v === 'boolean' || typeof v === 'number' && Number.isFinite(v)) return;
  if (!Array.isArray(v) && !plain(v)) throw new ContractViolation('Non-JSON contract value.');
  for (const item of Object.values(v)) jsonValue(item, depth + 1);
}
export function validate(manifest, schema, value, depth = 0) {
  if (depth > 60) throw new ContractViolation('Contract nesting exceeds limit.');
  if (typeof value === 'string') unicode(value);
  if (schema.$ref) return validate(manifest, manifest.$defs[schema.$ref.slice(8)], value, depth + 1);
  const types = Array.isArray(schema.type) ? schema.type : [schema.type];
  const matches = {object:plain(value), array:Array.isArray(value), string:typeof value === 'string', integer:Number.isSafeInteger(value), number:typeof value === 'number' && Number.isFinite(value), boolean:typeof value === 'boolean', null:value === null};
  if (!types.some(type => matches[type])) throw new ContractViolation('Contract type mismatch.');
  if (schema.enum && !schema.enum.some(item => typeof item === typeof value && item === value)) throw new ContractViolation('Contract enum mismatch.');
  if (typeof value === 'string' && ([...value].length < (schema.minLength ?? 0) || [...value].length > (schema.maxLength ?? Infinity))) throw new ContractViolation('Contract string length mismatch.');
  if (typeof value === 'number' && (value < (schema.minimum ?? -Infinity) || value > (schema.maximum ?? Infinity))) throw new ContractViolation('Contract numeric bound mismatch.');
  if (plain(value)) {
    for (const key of schema.required ?? []) if (!Object.hasOwn(value, key)) throw new ContractViolation('Required contract field is missing.');
    for (const [key, item] of Object.entries(value)) {
      if (Object.hasOwn(schema.properties ?? {}, key)) validate(manifest, schema.properties[key], item, depth + 1);
      else if (!schema.additionalProperties) throw new ContractViolation('Unexpected contract field.');
      else jsonValue(item, depth + 1);
    }
  } else if (Array.isArray(value)) {
    if (value.length < (schema.minItems ?? 0) || value.length > (schema.maxItems ?? Infinity)) throw new ContractViolation('Contract array length mismatch.');
    for (const item of value) validate(manifest, schema.items, item, depth + 1);
  }
  return value;
}
export function createRuntime(manifest, transport) {
  const action = name => { if (!Object.hasOwn(manifest.actions, name)) throw new ContractViolation('Unknown action.'); return manifest.actions[name]; };
  return {
    validateInput(name, value) { return validate(manifest, action(name).input, value); },
    validateOutput(name, value) { return validate(manifest, action(name).output, value); },
    validateEvent(name, value) { if (!Object.hasOwn(manifest.events, name)) throw new ContractViolation('Unknown event.'); return validate(manifest, manifest.events[name].payload, value); },
    validateError(name, code, retryable) { if (!action(name).errors?.includes(code) || manifest.errors[code].retryable !== retryable) throw new ContractViolation('Undeclared action error contract.'); },
    requirements(name) { return action(name).auth ?? []; },
    async action(name, payload) { validate(manifest, action(name).input, payload); const result = await transport(name, payload, action(name).auth ?? []); return validate(manifest, action(name).output, result); },
  };
}
