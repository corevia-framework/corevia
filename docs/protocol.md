# Corevia Protocol v1 (CVP/1)

Transport-independent UTF-8 JSON envelopes. Protocol version is `cvp: "1.0"`; unsupported versions are rejected explicitly. Unknown optional fields are ignored for forward compatibility. Definitions live in `protocol/cvp-1.schema.json`, with shared valid/invalid fixtures tested by PHP, JavaScript and Python.

## Action request / response

```json
{"cvp":"1.0","id":"req_1","type":"action.call","target":"ai.analyze","payload":{"text":"hello world"},"meta":{"trace_id":"trace_1"}}
```

```json
{"cvp":"1.0","id":"req_1","ok":true,"result":{"words":2},"error":null,"meta":{"trace_id":"trace_1"}}
```

```json
{"cvp":"1.0","id":"req_1","ok":false,"result":null,"error":{"code":"PAYMENT_DECLINED","message":"Payment declined","retryable":false},"meta":{"trace_id":"trace_1"}}
```

Request ID is a non-empty string up to 128 characters, echoed in the response. Trace ID is an optional string of up to 128 characters; transports generate it when omitted. Response metadata can contain null trace IDs when no context is available. Successful responses require `error: null`; unsuccessful responses require an error object with string `code`, string `message`, and boolean `retryable`. `result` always exists; failed flow endpoints attach their execution record there. Clients throw typed errors on unsuccessful action responses.

Message categories: `action.call`, `action.result`, `event`, `subscribe`, `unsubscribe`, `ping`, `pong`. HTTP action replies use the `ok/result/error` response envelope; `action.result` is reserved as a typed transport category and is not worker RPC over WebSocket. WebSocket never dispatches arbitrary actions: it accepts subscription/unsubscription/ping operations only. Invalid/unauthorized operations are rejected.

## Serialization

Allowed values: JSON null, booleans, finite numbers, UTF-8 strings, lists and maps. Payload is an object or array; domain requests should use objects. Empty PHP arrays serialize as `[]`, which all clients accept. Do not transmit PHP/Python objects, resources, bytes, dates or non-finite numbers. Represent timestamps as ISO-8601 strings and large integers/precise decimals as strings when JavaScript precision matters. Raw JSON containing NaN/Infinity is invalid. PHP's serializer rejects arbitrary objects before encoding; Python rejects non-finite values.

Default maximum envelope size is 65,536 bytes, configurable in transports and SDKs. Incoming WebSocket frames must be masked, have valid lengths/UTF-8, and respect message bounds. Text fragmentation is supported; binary application messages/extensions are not. Ping/pong/close control frames are supported. Browser WebSocket handles control pongs automatically.

## WebSocket subscriptions

```json
{"cvp":"1.0","id":"sub_1","type":"subscribe","target":"orders","payload":{},"meta":{"trace_id":"trace_1"}}
```

Acknowledgement:

```json
{"cvp":"1.0","id":"sub_1","ok":true,"result":{"channel":"orders","subscribed":true},"error":null,"meta":{"trace_id":"trace_1"}}
```

Event:

```json
{"cvp":"1.0","id":"evt_1","type":"event","target":"orders","payload":{"event":"order.updated","data":{"order_id":"ord_1","status":"created"}},"meta":{"trace_id":"trace_1"}}
```

Unsubscribe uses the same shape with `type: "unsubscribe"`. Application ping has `type: "ping"` and an empty payload; pong echoes its ID. Private channel subscriptions include `payload.token` and target a `private-` channel; server-side auth decides whether to grant access. Connection origin checking is separate from private-channel authorization. The JS client reacquires tokens and resubscribes after reconnect; events during disconnection are not buffered/replayed. Consumers should reconcile with an HTTP query. Event consumers must tolerate duplicate broadcasts after retry.

## Stable error codes

| Boundary | Codes |
| --- | --- |
| Envelope | `INVALID_JSON`, `INVALID_ENVELOPE`, `UNSUPPORTED_VERSION`, `INVALID_REQUEST_ID`, `INVALID_MESSAGE_TYPE`, `INVALID_TARGET`, `INVALID_PAYLOAD`, `INVALID_META`, `INVALID_RESPONSE`, `NON_JSON_VALUE`, `PAYLOAD_TOO_LARGE` |
| Runtime | `ACTION_NOT_FOUND`, `ACTION_EXCEPTION`, `INVALID_STATE`, `CONDITION_EXCEPTION`, `EVENT_HANDLER_FAILED`, `IDEMPOTENCY_CONFLICT`, `RUN_BUSY` |
| HTTP | `INVALID_REQUEST`, `NOT_FOUND`, `METHOD_NOT_ALLOWED`, `ORIGIN_DENIED`, `FORBIDDEN`, `CONFLICT`, `INTERNAL_ERROR` |
| PHP remote | `REMOTE_UNAVAILABLE`, `REMOTE_TIMEOUT`, `REMOTE_PROTOCOL_ERROR`, `INVALID_AUTH` |
| JS client | `TIMEOUT`, `ABORTED`, `NETWORK_ERROR`, `PROTOCOL_ERROR`, `HTTP_ERROR`, `REALTIME_UNAVAILABLE`, `REALTIME_ERROR`, `AUTH_ERROR` |
| Python worker/client | `UNAUTHORIZED`, `REMOTE_UNAVAILABLE`, `PROTOCOL_ERROR`, `HTTP_ERROR` |
| Demo business logic | `VALIDATION_FAILED`, `PAYMENT_DECLINED`, `FRAUD_REJECTED`, `NOTIFICATION_UNAVAILABLE`, `EVENT_DELIVERY_FAILED` |

Expected business failures may define additional stable codes. Unexpected server exceptions return generic messages and log class/context; raw internal exception text is never sent to remote clients. PHP CLI reports operator-facing setup/argument errors directly.
