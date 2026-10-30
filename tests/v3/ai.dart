import 'dart:convert';
import '../../sdk/dart/lib/ai.dart';
Future<void> main() async {
  final source = utf8.encode('event: delta\ndata: {"text":"hello 🌍"}\n\nevent: done\ndata: []\n\n');
  final result = await coreviaAiText(Stream.fromIterable(source.map((byte) => [byte]))).join();
  if (result != 'hello 🌍') throw StateError('AI stream mismatch.');
  var rejected = false;
  try { await coreviaAiText(Stream.value(utf8.encode('event: delta\ndata: {"text":"x"}\n\n'))).join(); } catch (_) { rejected = true; }
  if (!rejected) throw StateError('Truncated stream accepted.');
  print('PASS Dart fragmented UTF-8 AI streaming and truncation');
}
