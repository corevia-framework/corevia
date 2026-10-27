import 'dart:async';
import 'dart:convert';
import 'dart:io';
import '../lib/corevia.dart';

Future<void> main() async {
  final server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
  var subscriptions = 0;
  final sockets = <WebSocket>[];
  server.listen((request) async {
    if (request.uri.path == '/realtime') {
      final socket = await WebSocketTransformer.upgrade(request); sockets.add(socket);
      socket.listen((wire) async {
        final message = validateEnvelope(jsonDecode(wire as String));
        if (message['type'] == 'subscribe') {
          subscriptions++;
          socket.add(jsonEncode({'cvp':'2.0','id':'event_$subscriptions','type':'event','target':'orders.42','payload':{'event':'Updated','data':{'attempt':subscriptions}},'meta':{'event_version':1}}));
          if (subscriptions == 1) await socket.close();
        }
      });
      return;
    }
    final value = validateEnvelope(jsonDecode(await utf8.decoder.bind(request).join()));
    request.response.headers.contentType = ContentType.json;
    final allowed = request.headers.value('Authorization') == 'Bearer test-only-token';
    request.response.write(jsonEncode({'cvp':value['cvp'],'id':value['id'],'type':'action.result','ok':allowed,'result':allowed?value['payload']:null,'error':allowed?null:{'code':'UNAUTHORIZED','message':'Denied','retryable':false},'meta':value['meta']}));
    await request.response.close();
  });
  final base = 'http://127.0.0.1:${server.port}';
  final client = CoreviaClient(baseUrl:Uri.parse(base),realtimeUrl:Uri.parse('ws://127.0.0.1:${server.port}/realtime'),token:()=>'test-only-token');
  final denied = CoreviaClient(baseUrl:Uri.parse(base));
  try {
    final result = await client.action('echo',{'id':42},parentTraceId:'parent');
    if (result['result']['id'] != 42 || result['meta']['parent_trace_id'] != 'parent') throw StateError('HTTP contract failed');
    var forbidden = false;
    try { await denied.action('echo',{}); } on CoreviaError catch (e) { forbidden = e.code == 'UNAUTHORIZED'; }
    if (!forbidden) throw StateError('Authorization error missing');
    final events = await client.channel('orders.*').take(2).toList().timeout(const Duration(seconds:5));
    if (events.length != 2 || subscriptions != 2) throw StateError('Reconnect failed');
    print('PASS Dart HTTP authorization, parent trace, wildcard realtime and reconnect restoration');
  } finally {
    await client.dispose(); await denied.dispose(); for(final socket in sockets){await socket.close();} await server.close(force:true);
  }
}
