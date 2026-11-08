# Corevia Dart / Flutter mobile client

This dependency-free Dart IO client supports authenticated HTTP actions and reconnecting WebSocket channels using the same CVP/2 fixtures as PHP, Python and JavaScript. It targets Dart and Flutter mobile/desktop; Flutter web requires a browser transport adapter.

```dart
final client = CoreviaClient(
  baseUrl: Uri.parse('https://api.example.com'),
  realtimeUrl: Uri.parse('wss://api.example.com/realtime'),
  token: () => currentAccessToken,
);
final result = await client.action('orders.create', {'item': 42});
final events = client.channel('orders').listen((envelope) {
  print(envelope['payload']);
});
// await events.cancel(); await client.unsubscribe('orders'); await client.dispose();
```

Keep business logic in Corevia modules. Client errors expose machine codes and retryability. HTTP results preserve the envelope and trace IDs. Channel streams emit versioned event envelopes, including presence events. Listen to `client.errors` for subscription failures. Reconnect restores subscriptions; offline event replay is not promised. Run `dart analyze` and `dart run test/contracts.dart` from this directory. This development package is not published.

Optional V4 offline synchronization is in `sync.dart`, with a native HTTP adapter in `sync_io.dart`. It uses explicit current-auth transport and app-owned atomic durable storage; `MemorySyncStore` is for learning/tests only. See [offline synchronization](../../docs/v4/offline-sync.md) for the API, conflict policy, limits and Flutter/React Native page examples. Run `dart run test/sync.dart`; actual PHP HTTP acceptance is `python tests/v4/sync-http.py` from the repository root. Native device acceptance remains separate.
