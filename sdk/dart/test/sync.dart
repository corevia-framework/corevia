import '../lib/sync.dart';

void check(bool value) { if (!value) throw StateError('Dart offline sync contract failed.'); }
Future<void> rejects(Future<dynamic> Function() work, [String? code]) async {
  try { await work(); } catch (error) { if (code != null && (error is! SyncError || error.code != code)) rethrow; return; }
  throw StateError('Unsafe offline operation accepted.');
}
Map<String, dynamic> ack(Map<String, dynamic> item, [int sequence = 1]) => {'operation_id': item['operation_id'], 'id': item['id'], 'revision': item['expected_revision'] + 1, 'sequence': sequence, 'deleted': item['data'] == null};
class Transport implements SyncTransport {
  Future<Map<String, dynamic>> Function(Map<String, dynamic>)? onMutate;
  Future<Map<String, dynamic>> Function(String?)? onChanges;
  @override
  Future<Map<String, dynamic>> mutate(Map<String, dynamic> item) => onMutate == null ? Future.error(SyncError('SYNC_UNAVAILABLE')) : onMutate!(item);
  @override
  Future<Map<String, dynamic>> changes(String? cursor) => onChanges == null ? Future.error(SyncError('SYNC_UNAVAILABLE')) : onChanges!(cursor);
}
class FailingStore implements SyncStateStore {
  final memory = MemorySyncStore();
  bool failNext = false;
  @override
  Future<SyncStoredState> read() => memory.read();
  @override
  Future<bool> compareExchange(int version, String payload) async { if (failNext) { failNext = false; throw StateError('disk unavailable'); }return memory.compareExchange(version, payload); }
}
Future<void> main() async {
  final store = MemorySyncStore(), sync = OfflineSync(store: MemorySyncStore(), binding: 'alpha:alice');
  final offline = OfflineSync(store: store, binding: 'alpha:alice');
  final input = <String, dynamic>{'title': 'original'};
  final operation = await offline.enqueue('note', 0, input);input['title'] = 'caller changed';
  final receipts = <String, Map<String, dynamic>>{};var writes = 0, lost = true;
  final transport = Transport()..onMutate = (item) async { if (!receipts.containsKey(item['operation_id'])) { receipts[item['operation_id']] = ack(item);writes++; }if (lost) { lost = false; throw StateError('lost response'); }return receipts[item['operation_id']]!; };
  check((await offline.flush(transport))['status'] == 'offline');check((await offline.snapshot())['pending'][0]['operation_id'] == operation);
  final restored = OfflineSync(store: store, binding: 'alpha:alice');check((await restored.flush(transport))['processed'] == 1 && writes == 1);
  check((await restored.snapshot())['records']['note']['data']['title'] == 'original');
  await rejects(() => OfflineSync(store: store, binding: 'beta:bob').snapshot(), 'SYNC_PARTITION_MISMATCH');

  final failingStore = FailingStore();
  final retry = OfflineSync(store: failingStore, binding: 'alpha:alice');await retry.enqueue('receipt', 0, {'title': 'hello'});
  Map<String, dynamic>? receipt;var committed = 0;
  final once = Transport()..onMutate = (item) async { if (receipt == null) { receipt = ack(item);committed++;failingStore.failNext = true; }return receipt!; };
  await rejects(() => retry.flush(once));check((await retry.snapshot())['pending'].length == 1);await retry.flush(once);check(committed == 1);

  final ids = <String>[for (var n = 1; n <= 10; n++) n.toRadixString(16).padLeft(32, '0')];
  final conflicts = OfflineSync(store: MemorySyncStore(), binding: 'alpha:alice', newOperationId: () => ids.removeAt(0));
  final oldId = await conflicts.enqueue('conflict', 0, {'title': 'mine'});
  final conflict = Transport()..onMutate = (_) async => throw SyncError('SYNC_REVISION_CONFLICT');
  check((await conflicts.flush(conflict))['status'] == 'conflict');
  final newId = await conflicts.resolveConflict(oldId, action: 'rebase', expectedRevision: 5, value: {'title': 'reviewed merge'});check(oldId != newId);
  await conflicts.flush(Transport()..onMutate = (item) async => ack(item));check((await conflicts.snapshot())['records']['conflict']['revision'] == 6);

  var disconnected = true;
  final feed = Transport()..onChanges = (cursor) async {
    if (cursor == null) return {'changes': [{'id': 'note', 'revision': 1, 'sequence': 1, 'deleted': false, 'data': {'title': 'first'}}], 'cursor': 'c1', 'has_more': true};
    if (disconnected) { disconnected = false;throw StateError('offline'); }
    check(cursor == 'c1');return {'changes': [{'id': 'note', 'revision': 2, 'sequence': 2, 'deleted': true, 'data': null}], 'cursor': 'c2', 'has_more': false};
  };
  check((await sync.pull(feed))['status'] == 'offline');check((await sync.snapshot())['cursor'] == 'c1');await sync.pull(feed);check((await sync.snapshot())['records']['note']['deleted'] == true);
  check((await sync.pull(Transport()..onChanges = (_) async => throw SyncError('SYNC_CURSOR_REQUIRES_RESET')))['status'] == 'reset_required');check((await sync.snapshot())['cursor'] == 'c2');
  await sync.resetFeed();check((await sync.snapshot())['cursor'] == null);
  await sync.enqueue('unsent', 0, {'title': 'keep'});check((await sync.flush(Transport()..onMutate = (_) async => throw SyncError('AUTH_REQUIRED')))['status'] == 'auth_required');check((await sync.snapshot())['pending'].length == 1);
  await rejects(() => sync.enqueue('bad', 0, {'value': double.nan}), 'SYNC_JSON_VALUE');await rejects(() => sync.enqueue('bad', 0, 'text'), 'SYNC_DATA_BOUNDS');
  await rejects(() => sync.enqueue('bad', 0, {'broken': String.fromCharCode(0xd800)}), 'SYNC_JSON_VALUE');
  await rejects(() => sync.enqueue('bad', 9007199254740991, {}), 'SYNC_MUTATION_INVALID');
  final corrupted = MemorySyncStore();await corrupted.compareExchange(0,'{private-account-data');await rejects(() => OfflineSync(store: corrupted,binding: 'alpha:alice').snapshot(),'SYNC_STATE_INVALID');
  print('PASS Dart durable-CAS retry/lost-ack semantics, conflict rebase, feed resume/tombstones, partition/auth boundaries and JSON bounds');
}
