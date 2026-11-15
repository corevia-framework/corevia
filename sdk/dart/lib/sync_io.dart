import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';
import 'sync.dart';

/// Native Dart/Flutter HTTP adapter. Tokens are fetched afresh and never passed to offline storage.
class IoSyncTransport implements SyncTransport {
  final Uri base;
  final Future<String?> Function() getToken;
  final Duration timeout;
  late final String _prefix;
  IoSyncTransport({required this.base, required this.getToken, this.timeout = const Duration(seconds: 5), bool developmentLoopback = false}) {
    final local = developmentLoopback && base.scheme == 'http' && ['127.0.0.1', '::1'].contains(base.host);
    if ((!local && base.scheme != 'https') || base.host.isEmpty || base.userInfo.isNotEmpty || base.hasQuery || base.hasFragment || timeout.inMilliseconds < 1 || timeout.inMilliseconds > 30000) throw SyncError('SYNC_TRANSPORT_CONFIGURATION');
    _prefix = base.path.isEmpty || base.path == '/' ? '/api/v1/sync' : base.path.replaceFirst(RegExp(r'/$'), '');
    if (!RegExp(r'^/[A-Za-z0-9/_-]{1,100}$').hasMatch(_prefix)) throw SyncError('SYNC_TRANSPORT_CONFIGURATION');
  }
  Future<Map<String, dynamic>> _request(String path, {Map<String, dynamic>? input, String? cursor}) async {
    HttpClient? client;
    HttpClientRequest? active;
    var expired = false;
    Future<Map<String, dynamic>> run() async {
      final token = await getToken();
      if (expired) throw SyncError('SYNC_TIMEOUT');
      if (token == null || token.isEmpty || token.length > 4096 || RegExp(r'[^\x21-\x7e]').hasMatch(token)) throw SyncError('AUTH_REQUIRED');
      client = HttpClient()..connectionTimeout = timeout;
      final target = base.replace(path: _prefix + path, queryParameters: cursor == null ? null : {'cursor': cursor});
      final request = await client!.openUrl(input == null ? 'GET' : 'POST', target);
      active = request;request.followRedirects = false;
      request.headers.set(HttpHeaders.authorizationHeader, 'Bearer $token');request.headers.set(HttpHeaders.acceptHeader, 'application/json');
      if (input != null) { request.headers.contentType = ContentType.json; request.write(jsonEncode(input)); }
      final response = await request.close();
      if (response.statusCode >= 300 && response.statusCode < 400) throw SyncError('SYNC_REDIRECT');
      if (response.statusCode == 401 || response.statusCode == 403) throw SyncError('AUTH_REQUIRED');
      if (response.contentLength > 2097152) throw SyncError('SYNC_RESPONSE_CAPACITY');
      final body = BytesBuilder(copy: false);var length = 0;
      await for (final chunk in response) { length += chunk.length; if (length > 2097152) throw SyncError('SYNC_RESPONSE_CAPACITY'); body.add(chunk); }
      dynamic wire;try { wire = jsonDecode(utf8.decode(body.takeBytes())); } catch (_) { throw SyncError('SYNC_PROTOCOL'); }
      if (response.statusCode < 200 || response.statusCode >= 300) {
        final code = wire is Map && wire['error'] is Map ? wire['error']['code'] : null;
        if (response.statusCode == 409 && ['SYNC_REVISION_CONFLICT','SYNC_OPERATION_CONFLICT','SYNC_CURSOR_REQUIRES_RESET'].contains(code)) throw SyncError(code as String);
        if (response.statusCode == 400 || response.statusCode == 422) throw SyncError('SYNC_REJECTED');
        throw SyncError('SYNC_UNAVAILABLE');
      }
      if (wire is! Map || wire['data'] is! Map<String, dynamic>) throw SyncError('SYNC_PROTOCOL');
      return wire['data'] as Map<String, dynamic>;
    }
    try { return await run().timeout(timeout, onTimeout: () { expired = true;active?.abort(); throw SyncError('SYNC_TIMEOUT'); }); }
    finally { client?.close(force: true); }
  }
  @override
  Future<Map<String, dynamic>> mutate(Map<String, dynamic> mutation) => _request('/mutations', input: mutation);
  @override
  Future<Map<String, dynamic>> changes(String? cursor) => _request('/changes', cursor: cursor);
}
