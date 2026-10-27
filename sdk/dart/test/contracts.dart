import 'dart:convert';
import 'dart:io';
import '../lib/corevia.dart';
void main() {
  final fixtures = jsonDecode(File('../../protocol/v2/fixtures.json').readAsStringSync());
  for (final value in fixtures['valid']) { validateEnvelope(value); }
  for (final value in fixtures['invalid']) {
    var rejected = false;
    try { validateEnvelope(value); } on CoreviaError { rejected = true; }
    if (!rejected) throw StateError('Accepted invalid fixture: $value');
  }
  print('PASS shared CVP/2 Dart contracts');
}
