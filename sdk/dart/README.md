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
