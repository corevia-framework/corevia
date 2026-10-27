import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

class CoreviaError implements Exception {
  final String code;
  final String message;
  final bool retryable;
  CoreviaError(this.code, this.message, [this.retryable = false]);
  @override
  String toString() => 'CoreviaError($code): $message';
}

Map<String, dynamic> validateEnvelope(dynamic value) {
  void safe(dynamic item) {
    if (item == null || item is String || item is bool || item is num && item.isFinite) return;
    if (item is List) { for (final child in item) { safe(child); } return; }
    if (item is Map<String, dynamic>) { for (final child in item.values) { safe(child); } return; }
    throw CoreviaError('NON_JSON_VALUE', 'Finite JSON values required.');
  }
  safe(value);
  if (value is! Map<String, dynamic> || !['1.0', '2.0'].contains(value['cvp']) || value['id'] is! String || (value['id'] as String).isEmpty || (value['id'] as String).length > 128) {
    throw CoreviaError('INVALID_ENVELOPE', 'Invalid protocol envelope.');
  }
  final meta = value['meta'] ?? <String, dynamic>{};
  if (meta is! Map<String, dynamic>) throw CoreviaError('INVALID_META', 'Invalid metadata.');
  for (final key in ['trace_id', if (value['cvp'] == '2.0') 'parent_trace_id']) {
    final v = meta[key];
    if (v != null && (v is! String || v.length > 128 || value['cvp'] == '2.0' && v.isEmpty)) throw CoreviaError('INVALID_META', 'Invalid trace metadata.');
  }
  if (value['cvp'] == '2.0') {
    final timeout = meta['timeout_ms'] ?? 5000;
    if (timeout is! int || timeout < 1 || timeout > 300000) throw CoreviaError('INVALID_TIMEOUT', 'Invalid deadline.');
    if (value.containsKey('ok') && value['type'] != 'action.result') throw CoreviaError('INVALID_MESSAGE_TYPE', 'Invalid reply type.');
  }
  if (value.containsKey('ok')) {
    final error = value['error'];
    if (value['ok'] is! bool || !value.containsKey('result') || !value.containsKey('error') || value['ok'] == true && error != null || value['ok'] == false && (error is! Map || error['code'] is! String || error['message'] is! String || error['retryable'] is! bool)) {
      throw CoreviaError('INVALID_RESPONSE', 'Invalid response.');
    }
  } else {
    if (!['action.call', 'action.result', 'event', 'subscribe', 'unsubscribe', 'ping', 'pong'].contains(value['type']) || !(value['payload'] is Map || value['payload'] is List)) throw CoreviaError('INVALID_ENVELOPE', 'Invalid message.');
    if (['action.call', 'event', 'subscribe', 'unsubscribe'].contains(value['type']) && (value['target'] is! String || (value['target'] as String).isEmpty || (value['target'] as String).length > 128)) throw CoreviaError('INVALID_TARGET', 'Invalid target.');
  }
  return value;
}

class CoreviaClient {
  final Uri baseUrl;
  final Uri? realtimeUrl;
  final FutureOr<String?> Function()? token;
  final Duration timeout;
  final int maxBytes;
  final String version;
  final _random = Random.secure();
  final _events = <String, StreamController<Map<String, dynamic>>>{};
  final _errors = StreamController<CoreviaError>.broadcast();
  WebSocket? _socket;
  Timer? _retry;
  bool _connecting = false, _stopped = true;
  int _attempt = 0, _generation = 0;

  CoreviaClient({required this.baseUrl, this.realtimeUrl, this.token, this.timeout = const Duration(seconds: 5), this.maxBytes = 65536, this.version = '2.0'}) {
    if (!['http', 'https'].contains(baseUrl.scheme) || !['1.0', '2.0'].contains(version) || timeout.inMilliseconds < 1 || timeout.inMilliseconds > 300000 || maxBytes < 1) throw ArgumentError('HTTP URL, supported version and bounded timeout required.');
  }
  Stream<CoreviaError> get errors => _errors.stream;
  String _id() => List.generate(16, (_) => _random.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
  Map<String, dynamic> _envelope(String type, String target, dynamic payload) => {'cvp': version, 'id': _id(), 'type': type, 'target': target, 'payload': payload, 'meta': {'trace_id': _id(), if (version == '2.0') 'timeout_ms': timeout.inMilliseconds}};

  Future<Map<String, dynamic>> action(String target, Map<String, dynamic> payload, {String? parentTraceId}) async {
    final client = HttpClient()..connectionTimeout = timeout;
    final request = _envelope('action.call', target, payload);
    if (parentTraceId != null && version == '2.0') request['meta']['parent_trace_id'] = parentTraceId;
    try {
      return await (() async {
        validateEnvelope(request);
        final bytes = utf8.encode(jsonEncode(request));
        if (bytes.length > maxBytes) throw CoreviaError('PAYLOAD_TOO_LARGE', 'Request exceeds limit.');
        final http = await client.postUrl(Uri.parse('${baseUrl.toString().replaceFirst(RegExp(r'/$'), '')}/cvp'));
        http.followRedirects = false;
        http.headers.contentType = ContentType.json;
        final auth = await token?.call();
        if (auth != null) http.headers.set('Authorization', 'Bearer $auth');
        http.add(bytes);
        final response = await http.close();
        final body = <int>[];
        await for (final chunk in response) { body.addAll(chunk); if (body.length > maxBytes) throw CoreviaError('PAYLOAD_TOO_LARGE', 'Response exceeds limit.'); }
        final result = validateEnvelope(jsonDecode(utf8.decode(body)));
        if (result['id'] != request['id'] || result['cvp'] != version || result['ok'] is! bool) throw CoreviaError('PROTOCOL_ERROR', 'Mismatched reply.');
        if (!result['ok']) { final e = result['error']; throw CoreviaError(e['code'], e['message'], e['retryable']); }
        if (response.statusCode >= 400) throw CoreviaError('HTTP_ERROR', 'HTTP request failed.');
        return result;
      })().timeout(timeout);
    } on TimeoutException { throw CoreviaError('REMOTE_TIMEOUT', 'Request timed out.', true); }
    on SocketException { throw CoreviaError('REMOTE_UNAVAILABLE', 'Runtime unavailable.', true); }
    on FormatException { throw CoreviaError('PROTOCOL_ERROR', 'Invalid reply.'); }
    finally { client.close(force: true); }
  }

  Stream<Map<String, dynamic>> channel(String name) {
    if (!RegExp(r'^[a-zA-Z0-9_.:*-]{1,128}$').hasMatch(name) || name.contains('*') && (!name.endsWith('.*') || name.substring(0, name.length - 1).contains('*') || name.startsWith('private-') || name.startsWith('presence-'))) throw ArgumentError('Invalid channel.');
    if (!_events.containsKey(name)) { _events[name] = StreamController.broadcast(); if (_socket != null) _subscribe(name); }
    connect(); return _events[name]!.stream;
  }
  Future<void> _subscribe(String name) async {
    final generation = _generation;
    try {
      final auth = await token?.call();
      if (generation == _generation && _events.containsKey(name)) _send(_envelope('subscribe', name, {if (auth != null) 'token': auth}));
    } catch (_) { _errors.add(CoreviaError('AUTH_ERROR', 'Subscription authorization failed.')); }
  }
  void _send(Map<String, dynamic> message) {
    validateEnvelope(message); final wire = jsonEncode(message);
    if (utf8.encode(wire).length > maxBytes) throw CoreviaError('PAYLOAD_TOO_LARGE', 'Realtime request exceeds limit.');
    _socket?.add(wire);
  }
  Future<void> connect() async {
    if (realtimeUrl == null) { _errors.add(CoreviaError('REALTIME_UNAVAILABLE', 'Provide a realtime URL.')); return; }
    _stopped = false;
    if (_connecting || _socket != null) return;
    _connecting = true; final generation = ++_generation;
    try {
      final socket = await WebSocket.connect(realtimeUrl.toString()).timeout(timeout);
      if (generation != _generation || _stopped) { await socket.close(); return; }
      _socket = socket; _attempt = 0;
      for (final name in _events.keys) { _subscribe(name); }
      socket.listen((data) {
        if (generation != _generation) return;
        try {
          if (data is! String || utf8.encode(data).length > maxBytes) throw CoreviaError('PAYLOAD_TOO_LARGE', 'Realtime message rejected.');
          final message = validateEnvelope(jsonDecode(data));
          if (message['ok'] == false) { final e = message['error']; _errors.add(CoreviaError(e['code'], e['message'], e['retryable'])); }
          if (message['type'] == 'event') {
            for (final entry in _events.entries) {
              final target = message['target'] as String;
              if (entry.key == target || entry.key.endsWith('.*') && !target.startsWith('private-') && !target.startsWith('presence-') && target.startsWith(entry.key.substring(0, entry.key.length - 1))) entry.value.add(message);
            }
          }
          if (message['type'] == 'ping') _send({..._envelope('pong', '', {}), 'id': message['id']});
        } catch (_) { _errors.add(CoreviaError('PROTOCOL_ERROR', 'Invalid realtime message.')); }
      }, onError: (Object _) { _errors.add(CoreviaError('REALTIME_ERROR', 'Connection failed.', true)); }, onDone: () {
        if (generation == _generation) { _socket = null; _schedule(); }
      });
    } catch (_) { if (generation == _generation) _schedule(); }
    finally { if (generation == _generation) _connecting = false; }
  }
  void _schedule() {
    if (_stopped) return;
    _retry?.cancel(); _retry = Timer(Duration(milliseconds: min(10000, 250 * pow(2, min(_attempt++, 6)).toInt())), connect);
  }
  Future<void> unsubscribe(String name) async { final events = _events.remove(name); _send(_envelope('unsubscribe', name, {})); await events?.close(); }
  Future<void> dispose() async {
    _stopped = true; ++_generation; _retry?.cancel(); await _socket?.close(); _socket = null;
    for (final controller in _events.values) { await controller.close(); } _events.clear(); await _errors.close();
  }
}
