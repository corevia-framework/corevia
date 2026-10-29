class ContractViolation implements Exception {
  final String message;
  ContractViolation(this.message);
  @override
  String toString() => 'ContractViolation: $message';
}
void unicode(String value) { if(value.runes.any((rune)=>rune>=0xd800 && rune<=0xdfff)) throw ContractViolation('Invalid Unicode string.'); }
void jsonValue(dynamic value, int depth) {
  if(depth>60) throw ContractViolation('Contract nesting exceeds limit.');
  if(value is String) unicode(value);
  if(value==null || value is String || value is bool || value is num && value.isFinite) return;
  if(value is! List && value is! Map<String,dynamic>) throw ContractViolation('Non-JSON contract value.');
  for(final item in value is List ? value : (value as Map).values){jsonValue(item,depth+1);}
}
dynamic validate(Map<String,dynamic> manifest, Map<String,dynamic> schema, dynamic value, [int depth=0]) {
  if(depth>60) throw ContractViolation('Contract nesting exceeds limit.');
  if(value is String) unicode(value);
  if(schema.containsKey(r'$ref')) return validate(manifest,manifest[r'$defs'][(schema[r'$ref'] as String).substring(8)],value,depth+1);
  final types=schema['type'] is List ? schema['type'] as List : [schema['type']];
  final numeric=value is num && value.isFinite;
  final matches={'object':value is Map<String,dynamic>,'array':value is List,'string':value is String,'integer':numeric && value.roundToDouble()==value && value.abs()<=9007199254740991,'number':numeric,'boolean':value is bool,'null':value==null};
  if(!types.any((type)=>matches[type]==true)) throw ContractViolation('Contract type mismatch.');
  if(schema['enum']!=null && !(schema['enum'] as List).contains(value)) throw ContractViolation('Contract enum mismatch.');
  if(value is String && (value.runes.length<(schema['minLength']??0) || value.runes.length>(schema['maxLength']??double.infinity))) throw ContractViolation('Contract string length mismatch.');
  if(numeric && (value<(schema['minimum']??double.negativeInfinity) || value>(schema['maximum']??double.infinity))) throw ContractViolation('Contract numeric bound mismatch.');
  if(value is Map<String,dynamic>){
    for(final key in schema['required']??[]){if(!value.containsKey(key)) throw ContractViolation('Required contract field is missing.');}
    final properties=schema['properties'] as Map? ?? {};
    for(final entry in value.entries){
      if(properties.containsKey(entry.key)){validate(manifest,properties[entry.key],entry.value,depth+1);}
      else if(schema['additionalProperties']!=true){throw ContractViolation('Unexpected contract field.');}
      else{jsonValue(entry.value,depth+1);}
    }
  }else if(value is List){
    if(value.length<(schema['minItems']??0) || value.length>(schema['maxItems']??double.infinity)) throw ContractViolation('Contract array length mismatch.');
    for(final item in value){validate(manifest,schema['items'],item,depth+1);}
  }
  return value;
}
class Runtime {
  final Map<String,dynamic> manifest;
  final Future<dynamic> Function(String,dynamic,List<dynamic>) transport;
  Runtime(this.manifest,this.transport);
  Map<String,dynamic> actionDefinition(String name){final action=manifest['actions'][name];if(action==null) throw ContractViolation('Unknown action.');return action;}
  dynamic validateInput(String name,dynamic value)=>validate(manifest,actionDefinition(name)['input'],value);
  dynamic validateOutput(String name,dynamic value)=>validate(manifest,actionDefinition(name)['output'],value);
  dynamic validateEvent(String name,dynamic value){final event=manifest['events'][name];if(event==null) throw ContractViolation('Unknown event.');return validate(manifest,event['payload'],value);}
  void validateError(String name,String code,bool retryable){if(!(actionDefinition(name)['errors']??[]).contains(code) || manifest['errors'][code]['retryable']!=retryable) throw ContractViolation('Undeclared action error contract.');}
  List<dynamic> requirements(String name)=>actionDefinition(name)['auth']??[];
  Future<dynamic> action(String name,dynamic payload)async{validateInput(name,payload);final result=await transport(name,payload,requirements(name));validateOutput(name,result);return result;}
}
