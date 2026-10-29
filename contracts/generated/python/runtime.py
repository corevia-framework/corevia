import math

class ContractViolation(ValueError):
    pass

def _unicode(value):
    if any(0xd800 <= ord(character) <= 0xdfff for character in value):
        raise ContractViolation('Invalid Unicode string.')

def _json(value, depth):
    if depth > 60:
        raise ContractViolation('Contract nesting exceeds limit.')
    if isinstance(value, str):
        _unicode(value)
    if isinstance(value, dict) and not all(isinstance(key, str) for key in value):
        raise ContractViolation('Object keys must be strings.')
    if value is None or isinstance(value, (str, bool, int)) or isinstance(value, float) and math.isfinite(value):
        return
    if not isinstance(value, (dict, list)):
        raise ContractViolation('Non-JSON contract value.')
    for item in value.values() if isinstance(value, dict) else value:
        _json(item, depth + 1)

def validate(manifest, schema, value, depth=0):
    if depth > 60:
        raise ContractViolation('Contract nesting exceeds limit.')
    if '$ref' in schema:
        return validate(manifest, manifest['$defs'][schema['$ref'][8:]], value, depth + 1)
    types = schema['type'] if isinstance(schema['type'], list) else [schema['type']]
    if isinstance(value, str):
        _unicode(value)
    if isinstance(value, dict) and not all(isinstance(key, str) for key in value):
        raise ContractViolation('Object keys must be strings.')
    try:
        numeric = type(value) in (int, float) and math.isfinite(value)
    except OverflowError:
        numeric = False
    matches = {'object':isinstance(value, dict), 'array':isinstance(value, list), 'string':isinstance(value, str), 'integer':numeric and int(value) == value and abs(value) <= 9007199254740991, 'number':numeric, 'boolean':isinstance(value, bool), 'null':value is None}
    if not any(matches[t] for t in types):
        raise ContractViolation('Contract type mismatch.')
    if 'enum' in schema and not any((type(item) is type(value) or numeric and type(item) in (int,float)) and item == value for item in schema['enum']):
        raise ContractViolation('Contract enum mismatch.')
    if isinstance(value, str) and not schema.get('minLength',0) <= len(value) <= schema.get('maxLength',float('inf')):
        raise ContractViolation('Contract string length mismatch.')
    if numeric and not schema.get('minimum',float('-inf')) <= value <= schema.get('maximum',float('inf')):
        raise ContractViolation('Contract numeric bound mismatch.')
    if isinstance(value, dict):
        if any(key not in value for key in schema.get('required', [])):
            raise ContractViolation('Required contract field is missing.')
        for key,item in value.items():
            if key in schema.get('properties',{}):
                validate(manifest,schema['properties'][key],item,depth+1)
            elif not schema.get('additionalProperties',False):
                raise ContractViolation('Unexpected contract field.')
            else:
                _json(item,depth+1)
    elif isinstance(value,list):
        if not schema.get('minItems',0) <= len(value) <= schema.get('maxItems',float('inf')):
            raise ContractViolation('Contract array length mismatch.')
        for item in value:
            validate(manifest,schema['items'],item,depth+1)
    return value

class Runtime:
    def __init__(self,manifest,transport):
        self.manifest,self.transport=manifest,transport
    def _action(self,name):
        if name not in self.manifest['actions']:
            raise ContractViolation('Unknown action.')
        return self.manifest['actions'][name]
    def validate_input(self,name,value):
        return validate(self.manifest,self._action(name)['input'],value)
    def validate_output(self,name,value):
        return validate(self.manifest,self._action(name)['output'],value)
    def validate_event(self,name,value):
        if name not in self.manifest['events']:
            raise ContractViolation('Unknown event.')
        return validate(self.manifest,self.manifest['events'][name]['payload'],value)
    def validate_error(self,name,code,retryable):
        if code not in self._action(name).get('errors',[]) or self.manifest['errors'][code]['retryable'] is not retryable:
            raise ContractViolation('Undeclared action error contract.')
    def requirements(self,name):
        return self._action(name).get('auth',[])
    def action(self,name,payload):
        self.validate_input(name,payload)
        result=self.transport(name,payload,self.requirements(name))
        self.validate_output(name,result)
        return result
