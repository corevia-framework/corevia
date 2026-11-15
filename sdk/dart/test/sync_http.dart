import 'dart:convert';
import 'dart:io';
import '../lib/sync.dart';
import '../lib/sync_io.dart';

void check(bool value) { if (!value) throw StateError('Native sync HTTP contract failed.'); }
/// Sequential-process fixture only; production multi-owner storage must implement a real atomic CAS.
class FileStore implements SyncStateStore {
  final File file;
  FileStore(this.file);
  @override
  Future<SyncStoredState> read() async {
    if (!await file.exists()) return SyncStoredState(0, null);
    final row = jsonDecode(await file.readAsString());return SyncStoredState(row['version'], row['payload']);
  }
  @override
  Future<bool> compareExchange(int version, String payload) async {
    final row = await read();if (row.version != version) return false;
    final next = File('${file.path}.tmp');await next.writeAsString(jsonEncode({'version': version + 1, 'payload': payload}), flush: true);await next.rename(file.path);return true;
  }
}
class LostReply implements SyncTransport {
  final SyncTransport inner;bool lost = true;
  LostReply(this.inner);
  @override
  Future<Map<String, dynamic>> mutate(Map<String, dynamic> input) async { final reply = await inner.mutate(input);if (lost) { lost = false;throw SyncError('FIXTURE_LOST_ACK'); }return reply; }
  @override
  Future<Map<String, dynamic>> changes(String? cursor) => inner.changes(cursor);
}
Future<void> main(List<String> arguments) async {
  final base = Uri.parse(arguments[0]), directory = Directory(arguments[1]).resolveSymbolicLinksSync();
  if (base.host != '127.0.0.1' || !directory.replaceAll('\\', '/').contains('/storage/sync-http-')) throw StateError('Use the private fixture runner.');
  final javascriptState = jsonDecode(await File('$directory/js-state.json').readAsString()), importedStore = MemorySyncStore();
  await importedStore.compareExchange(0,javascriptState['payload']);final imported = await OfflineSync(store: importedStore,binding: 'alpha:alice').snapshot();check(imported['pending'][0]['id'] == 'unsent-js' && imported['records']['shared-note']['revision'] == 2);
  var token = 'alpha-test', tokenReads = 0;
  final transport = IoSyncTransport(base: base, developmentLoopback: true, getToken: () async { tokenReads++;return token; });
  final store = FileStore(File('$directory/dart-state.json')), sync = OfflineSync(store: FileStore(File('$directory/cache.json')), binding: 'alpha:alice');
  await sync.pull(transport);check((await sync.snapshot())['records']['shared-note']['revision'] == 2);
  await sync.enqueue('shared-note', 2, null);await sync.flush(transport);await sync.pull(transport);check((await sync.snapshot())['records']['shared-note']['deleted'] == true);
  final queue = OfflineSync(store: store, binding: 'alpha:alice');await queue.enqueue('dart-note', 0, {'title': 'from Dart'});
  final flaky = LostReply(transport);check((await queue.flush(flaky))['status'] == 'offline');
  final resumed = OfflineSync(store: store, binding: 'alpha:alice');check((await resumed.flush(transport))['processed'] == 1);check((await resumed.snapshot())['records']['dart-note']['revision'] == 1);
  final feed = await transport.changes(null);check((feed['changes'] as List).length == 4);
  token = 'revoked-test';await resumed.enqueue('unsent-dart', 0, {'title': 'kept'});check((await resumed.flush(transport))['status'] == 'auth_required');check((await resumed.snapshot())['pending'].length == 1);
  final other = OfflineSync(store: MemorySyncStore(), binding: 'beta:bob');await other.pull(IoSyncTransport(base: base, developmentLoopback: true, getToken: () async => 'beta-test'));check(((await other.snapshot())['records'] as Map).isEmpty);
  check(tokenReads > 3);final wire = await File('$directory/dart-state.json').readAsString();check(!wire.contains('alpha-test') && !wire.contains('revoked-test'));
  print('PASS actual Dart/PHP receipt retry, JS/Dart feed/tombstone interoperability, fresh-token authority, durable fixture state and tenant isolation');
}
