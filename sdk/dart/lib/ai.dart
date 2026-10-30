import 'dart:convert';
import 'corevia.dart' show CoreviaError;

/// Parse your authenticated application SSE response; never put provider keys in a client.
Stream<String> coreviaAiText(Stream<List<int>> bytes) async* {
  var event = 'message';
  final data = <String>[];
  var size = 0;
  await for (final line in bytes.transform(utf8.decoder).transform(const LineSplitter())) {
    size += line.length;
    if (size > 65536) throw CoreviaError('AI_STREAM_INVALID', 'AI event exceeds its limit.');
    if (line.isEmpty) {
      if (data.isNotEmpty) {
        final payload = jsonDecode(data.join('\n'));
        if (event == 'error') throw CoreviaError('AI_STREAM_FAILED', 'AI generation failed.');
        if (event == 'done') return;
        if (event == 'delta') {
          if (payload is! Map<String, dynamic> || payload['text'] is! String) throw CoreviaError('AI_STREAM_INVALID', 'Invalid AI delta.');
          yield payload['text'] as String;
        }
      }
      data.clear(); event = 'message'; size = 0;
    } else if (line.startsWith('event:')) {
      event = line.substring(6).trim();
    } else if (line.startsWith('data:')) {
      var value = line.substring(5);
      if (value.startsWith(' ')) value = value.substring(1);
      data.add(value);
    }
  }
  throw CoreviaError('AI_STREAM_INTERRUPTED', 'AI stream ended before completion.');
}
