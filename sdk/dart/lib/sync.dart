import 'dart:convert';
import 'dart:math';

class SyncError implements Exception {
  final String code;
  SyncError(this.code);
  @override
  String toString() => 'SyncError($code)';
}

abstract class SyncStateStore {
  Future<SyncStoredState> read();
  Future<bool> compareExchange(int version, String payload);
}

class SyncStoredState {
  final int version;
  final String? payload;
  SyncStoredState(this.version, this.payload);
}

/// Teaching/test adapter. Supply atomic durable/encrypted storage for real offline persistence.
class MemorySyncStore implements SyncStateStore {
  int _version = 0;
  String? _payload;
  @override
  Future<SyncStoredState> read() async => SyncStoredState(_version, _payload);
  @override
  Future<bool> compareExchange(int version, String payload) async {
    if (version != _version) return false;
    _payload = payload;
    _version++;
    return true;
  }
}

abstract class SyncTransport {
  Future<Map<String, dynamic>> mutate(Map<String, dynamic> mutation);
  Future<Map<String, dynamic>> changes(String? cursor);
}

bool _revision(dynamic v) => v is int && v >= 0 && v <= 9007199254740991;
bool _recordId(dynamic v) => v is String && RegExp(r'^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$').hasMatch(v);
bool _operationId(dynamic v) => v is String && RegExp(r'^[a-f0-9]{32}$').hasMatch(v);
Never _fail(String code) => throw SyncError(code);
bool _validText(String text) {
  final units = text.codeUnits;
  for (var i = 0; i < units.length; i++) {
    final unit = units[i];
    if (unit >= 0xd800 && unit <= 0xdbff) { i++;if (i >= units.length || units[i] < 0xdc00 || units[i] > 0xdfff) return false; }
    else if (unit >= 0xdc00 && unit <= 0xdfff) { return false; }
  }
  return true;
}
void _json(dynamic value, [int depth = 0]) {
  if (depth > 32) _fail('SYNC_JSON_DEPTH');
  if (value is String) { if (!_validText(value)) _fail('SYNC_JSON_VALUE');return; }
  if (value == null || value is bool) return;
  if (value is int && value.abs() <= 9007199254740991 || value is double && value.isFinite) return;
  if (value is List) { for (final item in value) { _json(item, depth + 1); } return; }
  if (value is Map<String, dynamic>) { for (final entry in value.entries) { if (!_validText(entry.key)) _fail('SYNC_JSON_VALUE');_json(entry.value, depth + 1); } return; }
  _fail('SYNC_JSON_VALUE');
}
void _data(dynamic value) {
  _json(value);
  if (value != null && (value is! Map<String, dynamic> && value is! List || utf8.encode(jsonEncode(value)).length > 16384)) _fail('SYNC_DATA_BOUNDS');
}
dynamic _stable(dynamic value) {
  if (value is List) return value.map(_stable).toList();
  if (value is Map<String, dynamic>) { final keys = value.keys.toList()..sort(); return {for (final key in keys) key: _stable(value[key])}; }
  return value;
}
bool _same(dynamic a, dynamic b) => jsonEncode(_stable(a)) == jsonEncode(_stable(b));
String _newId() { final random = Random.secure(); return List.generate(16, (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0')).join(); }

/// Single-flight network work; state writes use the host's compare/exchange port.
class OfflineSync {
  final SyncStateStore store;
  final String binding;
  final String Function() newOperationId;
  bool _busy = false;
  OfflineSync({required this.store, required this.binding, String Function()? newOperationId}) : newOperationId = newOperationId ?? _newId {
    if (binding.isEmpty || binding.length > 256) _fail('SYNC_CONFIGURATION');
  }
  Map<String, dynamic> _empty() => {'format': 1, 'binding': binding, 'cursor': null, 'sequence': 0, 'pending': <dynamic>[], 'records': <String, dynamic>{}};
  Map<String, dynamic> _validate(dynamic state) {
    if (state is! Map<String, dynamic> || state['format'] != 1 || state['binding'] != binding) _fail('SYNC_PARTITION_MISMATCH');
    if (!_revision(state['sequence']) || state['cursor'] != null && (state['cursor'] is! String || (state['cursor'] as String).length > 2048)
        || state['pending'] is! List || (state['pending'] as List).length > 100 || state['records'] is! Map<String, dynamic> || (state['records'] as Map).length > 5000) _fail('SYNC_STATE_INVALID');
    final seen = <String>{};
    for (final item in state['pending'] as List) {
      if (item is! Map || !item.containsKey('data') || !_operationId(item['operation_id']) || !seen.add(item['operation_id']) || !_recordId(item['id']) || !_revision(item['expected_revision'])
          || item['expected_revision'] >= 9007199254740991 || !['pending', 'conflict'].contains(item['state'])) _fail('SYNC_STATE_INVALID');
      _data(item['data']);
    }
    for (final entry in (state['records'] as Map<String, dynamic>).entries) {
      final item = entry.value;
      if (!_recordId(entry.key) || item is! Map || !item.containsKey('data') || !_revision(item['revision']) || item['revision'] < 1 || item['deleted'] is! bool || item['deleted'] != (item['data'] == null)) _fail('SYNC_STATE_INVALID');
      _data(item['data']);
    }
    _json(state);
    if (utf8.encode(jsonEncode(state)).length > 2097152) _fail('SYNC_STATE_CAPACITY');
    return state;
  }
  Future<Map<String, dynamic>> _read() async {
    final row = await store.read();
    if (!_revision(row.version)) _fail('SYNC_STORE_INVALID');
    if (row.payload != null && utf8.encode(row.payload!).length > 2097152) _fail('SYNC_STATE_CAPACITY');
    dynamic decoded;if (row.payload != null) { try { decoded = jsonDecode(row.payload!); } on FormatException { _fail('SYNC_STATE_INVALID'); } }
    return {'version': row.version, 'state': row.payload == null ? _empty() : _validate(decoded)};
  }
  Future<T> _change<T>(T Function(Map<String, dynamic>) work) async {
    for (var attempt = 0; attempt < 8; attempt++) {
      final row = await _read(), state = row['state'] as Map<String, dynamic>;
      final result = work(state);
      _validate(state);
      if (await store.compareExchange(row['version'], jsonEncode(state))) return result;
    }
    return _fail('SYNC_STORE_BUSY');
  }
  String _nextId() { final id = newOperationId(); if (!_operationId(id)) _fail('SYNC_OPERATION_ID'); return id; }
  Future<Map<String, dynamic>> snapshot() async => (await _read())['state'] as Map<String, dynamic>;
  Future<String> enqueue(String id, int expectedRevision, dynamic value) async {
    if (!_recordId(id) || !_revision(expectedRevision) || expectedRevision >= 9007199254740991) _fail('SYNC_MUTATION_INVALID');
    _data(value); final copied = jsonDecode(jsonEncode(value)), operation = _nextId();
    await _change<void>((state) {
      final pending = state['pending'] as List;
      if (pending.length >= 100 || pending.any((item) => item['operation_id'] == operation)) _fail('SYNC_QUEUE_CAPACITY');
      pending.add({'operation_id': operation, 'id': id, 'expected_revision': expectedRevision, 'data': copied, 'state': 'pending'});
    });
    return operation;
  }
  Future<Map<String, dynamic>> flush(SyncTransport transport, {int maximum = 20}) async {
    if (_busy) _fail('SYNC_BUSY'); if (maximum < 1 || maximum > 100) _fail('SYNC_CONFIGURATION'); _busy = true; var processed = 0;
    try {
      while (processed < maximum) {
        final pending = (await snapshot())['pending'] as List;
        if (pending.isEmpty) return {'status': 'idle', 'processed': processed};
        final item = pending.first as Map<String, dynamic>;
        if (item['state'] == 'conflict') return {'status': 'conflict', 'processed': processed};
        Map<String, dynamic> ack;
        try { ack = await transport.mutate({for (final key in ['operation_id','id','expected_revision','data']) key: item[key]}); }
        catch (error) {
          final code = error is SyncError ? error.code : '';
          if (['SYNC_REVISION_CONFLICT','SYNC_OPERATION_CONFLICT','SYNC_REJECTED'].contains(code)) {
            await _change<void>((state) { for (final entry in state['pending'] as List) { if (entry['operation_id'] == item['operation_id']) entry['state'] = 'conflict'; } });
            return {'status': 'conflict', 'processed': processed};
          }
          return {'status': ['AUTH_REQUIRED','FORBIDDEN'].contains(code) ? 'auth_required' : 'offline', 'processed': processed};
        }
        if (ack['operation_id'] != item['operation_id'] || ack['id'] != item['id'] || !_revision(ack['revision']) || ack['revision'] != item['expected_revision'] + 1
            || !_revision(ack['sequence']) || ack['sequence'] < 1 || ack['deleted'] != (item['data'] == null)) _fail('SYNC_ACK_INVALID');
        await _change<void>((state) {
          (state['pending'] as List).removeWhere((entry) => entry['operation_id'] == item['operation_id']);
          final records = state['records'] as Map<String, dynamic>, old = records[item['id']];
          if (old == null || old['revision'] < ack['revision']) records[item['id']] = {'revision': ack['revision'], 'deleted': ack['deleted'], 'data': item['data']};
          else if (old['revision'] == ack['revision'] && !_same(old['data'], item['data'])) _fail('SYNC_FEED_INCONSISTENT');
        });
        processed++;
      }
      return {'status': 'bounded', 'processed': processed};
    } finally { _busy = false; }
  }
  Future<Map<String, dynamic>> pull(SyncTransport transport, {int maximumPages = 5}) async {
    if (_busy) _fail('SYNC_BUSY'); if (maximumPages < 1 || maximumPages > 20) _fail('SYNC_CONFIGURATION'); _busy = true; var pages = 0;
    try {
      while (pages < maximumPages) {
        final before = await snapshot(); Map<String, dynamic> page;
        try { page = await transport.changes(before['cursor']); }
        catch (error) { final code = error is SyncError ? error.code : ''; return {'status': code == 'SYNC_CURSOR_REQUIRES_RESET' ? 'reset_required' : (['AUTH_REQUIRED','FORBIDDEN'].contains(code) ? 'auth_required' : 'offline'), 'pages': pages}; }
        if (page['changes'] is! List || (page['changes'] as List).length > 100 || page['cursor'] is! String || (page['cursor'] as String).length > 2048 || page['has_more'] is! bool) _fail('SYNC_FEED_INVALID');
        var sequence = before['sequence'] as int;
        for (final item in page['changes'] as List) {
          if (item is! Map || !item.containsKey('data') || !_recordId(item['id']) || !_revision(item['revision']) || item['revision'] < 1 || !_revision(item['sequence']) || item['sequence'] <= sequence || item['deleted'] is! bool || item['deleted'] != (item['data'] == null)) _fail('SYNC_FEED_INVALID');
          _data(item['data']); sequence = item['sequence'];
        }
        final applied = await _change<bool>((state) {
          if (state['cursor'] != before['cursor'] || state['sequence'] != before['sequence']) return false;
          final records = state['records'] as Map<String, dynamic>;
          for (final item in page['changes'] as List) {
            final old = records[item['id']];
            if (old == null || item['revision'] > old['revision']) records[item['id']] = {'revision': item['revision'], 'deleted': item['deleted'], 'data': item['data']};
            else if (item['revision'] == old['revision'] && !_same(old['data'], item['data'])) _fail('SYNC_FEED_INCONSISTENT');
          }
          state['sequence'] = sequence; state['cursor'] = page['cursor']; return true;
        });
        if (!applied) return {'status': 'busy', 'pages': pages}; pages++;
        if (page['has_more'] == false) return {'status': 'idle', 'pages': pages};
      }
      return {'status': 'bounded', 'pages': pages};
    } finally { _busy = false; }
  }
  Future<String?> resolveConflict(String operation, {required String action, int? expectedRevision, dynamic value}) async {
    if (_busy) _fail('SYNC_BUSY'); if (!_operationId(operation) || !['drop','rebase'].contains(action)) _fail('SYNC_RESOLUTION_INVALID');
    Map<String, dynamic>? next;
    if (action == 'rebase') { if (!_revision(expectedRevision) || expectedRevision! >= 9007199254740991) _fail('SYNC_RESOLUTION_INVALID'); _data(value); next = {'operation_id': _nextId(), 'expected_revision': expectedRevision, 'data': jsonDecode(jsonEncode(value)), 'state': 'pending'}; }
    return _change<String?>((state) {
      final pending = state['pending'] as List, index = pending.indexWhere((item) => item['operation_id'] == operation);
      if (index < 0 || pending[index]['state'] != 'conflict') _fail('SYNC_NOT_CONFLICTED');
      if (action == 'drop') { pending.removeAt(index); return null; }
      if (pending.any((item) => item['operation_id'] == next!['operation_id'])) _fail('SYNC_OPERATION_ID');
      pending[index] = {...next!, 'id': pending[index]['id']}; return next['operation_id'];
    });
  }
  Future<void> resetFeed() async { if (_busy) _fail('SYNC_BUSY'); await _change<void>((state) { state['cursor'] = null; state['sequence'] = 0; state['records'] = <String, dynamic>{}; }); }
  /// Explicit logout/discard; authentication failures never erase unsent edits automatically.
  Future<void> reset() async {
    if (_busy) _fail('SYNC_BUSY');
    for (var attempt = 0; attempt < 8; attempt++) { final row = await store.read(); if (await store.compareExchange(row.version, jsonEncode(_empty()))) return; }
    _fail('SYNC_STORE_BUSY');
  }
}
