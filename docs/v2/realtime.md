# Realtime V2

The single-node `php corevia realtime:start` server needs no Redis. It supports CVP/1 and CVP/2 and fixes a socket's version on its first message. Subscription replies include connection ID, heartbeat interval, event serialization version, and member snapshot. Reconnect reauthorizes subscriptions; offline event replay is not guaranteed.

Public channels use names such as `orders` or `orders.42`. `orders.*` subscribes to public subtopics. Only a trailing `.*` wildcard is allowed. Private and presence subscriptions require exact names; public wildcards never match channels beginning `private-` or `presence-`.

Pass an authorization closure to Server. Private channels expect a permission boolean. Presence channels require a trusted identity `['id' => 'user_42', 'info' => ['name' => 'Ada']]`, or false. Never trust a client-supplied user ID without authentication. The development CLI's shared private token grants private access only; configure an application-specific callback for presence.

Presence replies include unique members on that node. PresenceJoined and PresenceLeft events track first join and last departure for an identity, including abrupt closure. Multiple tabs do not duplicate members. Keep visible member info minimal and nonsecret.

Events carry `meta.event_version: 1`. Frame/body, connection, subscription and rate limits, bounded output buffers, fragmentation, origin checks, heartbeat, and stale cleanup remain active. Slow clients disconnect instead of growing unbounded queues.

An optional PubSub adapter provides bounded nonblocking poll and publish methods for external fan-out. Node IDs prevent reflecting a node's own messages. The adapter must deduplicate and authenticate transport delivery. Presence membership remains node-local; a distributed index requires an application adapter. No Redis dependency is added.

Run `node tests/v2/realtime.mjs` for live presence, wildcard authorization, versioning, and heartbeat checks. The original integration suite checks reconnect, private auth, rate limits, malformed/fragmented frames, and HTTP-to-WebSocket delivery.
