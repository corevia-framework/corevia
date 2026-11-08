import 'package:flutter/material.dart';
import 'package:corevia/sync.dart';

/// Inject a partitioned durable queue and current-auth transport from the host application.
class OfflineNotesPage extends StatefulWidget {
  final OfflineSync queue;
  final SyncTransport transport;
  const OfflineNotesPage({super.key, required this.queue, required this.transport});
  @override
  State<OfflineNotesPage> createState() => _OfflineNotesPageState();
}
class _OfflineNotesPageState extends State<OfflineNotesPage> {
  final title = TextEditingController();
  Map<String, dynamic>? snapshot;
  String status = 'Ready.';
  bool busy = false;
  @override
  void initState() { super.initState();refresh(); }
  @override
  void dispose() { title.dispose();super.dispose(); }
  Future<void> refresh() async {
    try { final value = await widget.queue.snapshot();if (mounted) setState(() => snapshot = value); }
    catch (_) { if (mounted) setState(() => status = 'Saved data is unavailable on this device.'); }
  }
  void message(String value) { if (mounted) setState(() => status = value); }
  Future<void> action(Future<void> Function() work) async {
    if (busy) return;setState(() => busy = true);
    try { await work();await refresh(); } catch (_) { message('This change could not be completed. Your saved edits remain on this device.'); }
    finally { if (mounted) setState(() => busy = false); }
  }
  @override
  Widget build(BuildContext context) {
    final edits = (snapshot?['pending'] as List? ?? []).where((item) => item['id'] == 'welcome-note').toList();
    final pending = edits.isEmpty ? null : edits.first;
    final record = snapshot?['records']['welcome-note'];
    return Scaffold(appBar: AppBar(title: const Text('My offline note')),body: ListView(padding: const EdgeInsets.all(24),children: [
      TextField(controller: title,maxLength: 120,enabled: !busy && pending == null,decoration: const InputDecoration(labelText: 'Note title',border: OutlineInputBorder())),
      FilledButton(onPressed: busy || pending != null ? null : () => action(() async { if (title.text.trim().isEmpty) return;await widget.queue.enqueue('welcome-note',record?['revision'] ?? 0,{'title': title.text});message('Saved on this device. Sync when you are ready.'); }),child: const Text('Save offline')),
      OutlinedButton(onPressed: busy ? null : () => action(() async {
        final pulled = await widget.queue.pull(widget.transport);
        if (pulled['status'] == 'reset_required') { message('Choose Reload server data. Your offline edits will be kept.');return; }
        final result = await widget.queue.flush(widget.transport);
        if (result['status'] == 'conflict') { await widget.queue.pull(widget.transport);message('This note changed or was deleted on another device. Review both versions.'); }
        else if (result['status'] == 'auth_required') { message('Sign in again to sync. Your offline edits are still saved.'); }
        else if (result['status'] == 'offline') { message('You are offline. Your edits are still saved.'); }
        else { message('Synchronization complete.'); }
      }),child: const Text('Sync now')),
      Semantics(liveRegion: true,child: Text(status)),Text('${snapshot?['pending'].length ?? 0} edits waiting to sync'),
      if (pending?['state'] == 'conflict') ...[
        const SizedBox(height: 24),Text('Resolve this edit',style: Theme.of(context).textTheme.titleLarge),
        Text('Your draft: ${pending['data']?['title'] ?? ''}'),Text('Server: ${record?['deleted'] == true ? 'Deleted' : record?['data']?['title'] ?? 'No server note'}'),
        OutlinedButton(onPressed: busy ? null : () => action(() async { await widget.queue.resolveConflict(pending['operation_id'],action: 'drop');title.text = record?['data']?['title'] ?? '';message('Using the server version.'); }),child: const Text('Use server version')),
        OutlinedButton(onPressed: busy ? null : () => action(() async { await widget.queue.resolveConflict(pending['operation_id'],action: 'rebase',expectedRevision: record?['revision'] ?? 0,value: pending['data']);message('Your reviewed edit is queued. Choose Sync now to send it.'); }),child: const Text('Keep my edit')),
      ],
      TextButton(onPressed: busy ? null : () => action(() async { await widget.queue.resetFeed();await widget.queue.pull(widget.transport);message('Server data reloaded. Offline edits were kept.'); }),child: const Text('Reload server data')),
    ]));
  }
}
