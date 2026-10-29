import 'dart:convert';
import 'dart:io';
import '../../contracts/generated/dart/client.dart';
Future<void> main()async{
  final client=Client((name,payload,auth)async=>{'message':'Hello ${payload['name']}'});
  final fixtures=jsonDecode(File('contracts/fixtures.json').readAsStringSync());
  for(final fixture in fixtures){var valid=true;try{switch(fixture['kind']){case 'input':client.validateInput(fixture['name'],fixture['value']);case 'output':client.validateOutput(fixture['name'],fixture['value']);case 'event':client.validateEvent(fixture['name'],fixture['value']);}}catch(_){valid=false;}if(valid!=fixture['valid'])throw StateError('Contract fixture failed: $fixture');}
  if((await client.action_demo_greet(Type_GreetingInput.fromJson({'name':'Ada'}))).field_message!='Hello Ada')throw StateError('Client failed');
  if(client.requirements('demo.greet')[0]['scheme']!='session')throw StateError('Auth metadata lost');
  client.validateError('demo.greet','UNAVAILABLE',true);
  print('PASS ${fixtures.length} generated Dart application contracts and client');
}
