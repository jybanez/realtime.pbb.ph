# PBB Realtime WebSocket Envelope Spec

## Purpose

Define the V1 websocket message envelope for `PBB Realtime`.

This spec defines:

- the shared outer message shape for websocket traffic
- request, event, ack, and error phases
- message correlation and routing expectations
- how PBB apps should structure typed realtime payloads
- baseline reliability semantics

This spec does not define:

- the full chat event catalog
- the full call-signaling payload catalog
- full room naming policy

Those should follow as separate module-specific specs.

## Required Context

This spec assumes the future `PBB Realtime` team has already read:

- `docs/pbb-realtime-proposal.md`
- `docs/pbb-realtime-token-and-auth-spec.md`
- `docs/pbb-realtime-room-and-presence-spec.md`

## Canonical Vocabulary

Use these consistent V1 terms.

- `request`
  - client asks the gateway to do something
- `ack`
  - gateway confirms a request was accepted or completed
- `event`
  - server emits realtime activity to subscribed clients
- `error`
  - gateway rejects a request or reports a transport-level failure
- `session.auth.request`
  - websocket auth request type
- `room.join.request`
  - room join request type
- `chat.message.publish`
  - outbound client chat publish request type
- `chat.message.event`
  - inbound server chat event type
- `presence.state.event`
  - inbound presence event type
- `call.signal.publish`
  - outbound call signal request type

## Core Design Rule

Every websocket message should use the same typed envelope.

The outer envelope must remain stable even as new event families are added.

This keeps:

- message validation predictable
- logging consistent
- correlation straightforward
- future modules easier to add without inventing new transport shapes

## Namespace

Recommended V1 namespace:

- `pbb.realtime.v1`

All websocket messages should identify this namespace explicitly.

## Envelope Shape

Recommended V1 envelope:

```json
{
  "namespace": "pbb.realtime.v1",
  "phase": "request",
  "id": "msg_001",
  "type": "chat.message.publish",
  "room": "chat.thread.thread_123",
  "payload": {},
  "meta": {}
}
```

## Common Fields

- `namespace`
  - protocol namespace and version
- `phase`
  - message lifecycle phase
- `id`
  - message identifier
- `type`
  - typed action or event name
- `room`
  - optional room/channel identifier
- `payload`
  - typed data body
- `meta`
  - optional transport/context metadata

## Supported Phases

Recommended V1 phases:

- `request`
- `ack`
- `event`
- `error`
- `system`

## Phase Semantics

### `request`

Used when a client wants to:

- authenticate a websocket session
- request sender-scoped session health
- join a room
- leave a room
- publish a chat message
- publish typing or presence state
- publish call signaling data

### `ack`

Used when the server wants to confirm:

- a request was accepted
- a request completed successfully
- a normalized or server-assigned value is returned

### `event`

Used when the server broadcasts or emits:

- presence changes
- chat events
- typing events
- call signaling events
- system lifecycle events if needed

### `error`

Used when:

- validation fails
- auth fails
- capability checks fail
- room membership is denied
- payload is malformed
- an idempotent request conflicts with server rules

## Message Id Rules

- `id` must be unique within the client session
- client-generated request ids are recommended for correlation
- `ack` and `error` responses should echo the originating request `id`
- server-pushed `event` messages may use their own event ids
- request ids should be safe to use for duplicate detection within a bounded reconnect window

## Type Naming Rules

Use transport-facing dotted action names.

Recommended V1 examples:

- `session.auth.request`
- `session.health.request`
- `room.join.request`
- `room.leave.request`
- `presence.publish`
- `presence.state.event`
- `chat.message.publish`
- `chat.message.event`
- `chat.message.update`
- `call.signal.publish`
- `call.signal.event`
- `product.query.request`
- `product.query.response`

`product.query.request` is carried inside `app.event.publish` and is not broadcast as a normal custom app event. When the project scope has product query forwarding enabled, Realtime validates and forwards the query to the configured product backend. Product-specific behavior belongs in the allowlisted `query` value, not in separate websocket event names.

## Session Health

`session.health.request` is an authenticated sender-scoped request for measuring live websocket health. It uses the normal envelope with `phase=request`, `type=session.health.request`, `room` omitted or `null`, and an optional payload such as:

```json
{
  "client_time": "2026-05-06T04:00:00.000Z",
  "sequence": 1
}
```

Realtime accepts it only after token authentication with `session.connect`. It does not require a joined room, does not fan out, and does not touch presence, chat, media, or product event state.

The ack echoes the request id/type and returns transport facts:

```json
{
  "ok": true,
  "server_time": "2026-05-06T04:00:00.085Z",
  "authenticated": true,
  "session_id": "rt_abc",
  "connection_id": "00000000000000010000000000000000",
  "rooms_joined_count": 2,
  "heartbeat_interval_seconds": 30
}
```

Product applications should calculate round-trip time locally and own the final signal-strength mapping.

Avoid helper-function or internal implementation names.

## Room Field

`room` is optional for some message types, but should be used where room/session scope matters.

Examples:

- `presence.global.org_001`
- `presence.workspace.workspace_001`
- `chat.thread.thread_123`
- `call.session.session_456`

The exact room naming policy is standardized in:

- `docs/pbb-realtime-room-and-presence-spec.md`

## Meta Field

`meta` is optional.

Recommended uses:

- client timestamp
- trace id
- retry count
- source app metadata
- server timestamp in acks/events

Do not put core business data in `meta` if it belongs in `payload`.

## Example Messages

### Session Auth Request

```json
{
  "namespace": "pbb.realtime.v1",
  "phase": "request",
  "id": "msg_auth_001",
  "type": "session.auth.request",
  "payload": {
    "token": "<short-lived-token>"
  }
}
```

### Session Auth Ack

```json
{
  "namespace": "pbb.realtime.v1",
  "phase": "ack",
  "id": "msg_auth_001",
  "type": "session.auth.request",
  "payload": {
    "session_id": "sess_001",
    "project_code": "hq",
    "app_code": "pbb-hq",
    "user_id": "1024"
  }
}
```

### Join Room Request

```json
{
  "namespace": "pbb.realtime.v1",
  "phase": "request",
  "id": "msg_join_001",
  "type": "room.join.request",
  "room": "chat.thread.thread_123",
  "payload": {}
}
```

### Join Room Ack

```json
{
  "namespace": "pbb.realtime.v1",
  "phase": "ack",
  "id": "msg_join_001",
  "type": "room.join.request",
  "room": "chat.thread.thread_123",
  "payload": {
    "joined": true
  }
}
```

### Chat Publish Request

```json
{
  "namespace": "pbb.realtime.v1",
  "phase": "request",
  "id": "msg_chat_publish_001",
  "type": "chat.message.publish",
  "room": "chat.thread.thread_123",
  "payload": {
    "client_message_id": "local_abc123",
    "text": "Team is en route to Lahug.",
    "attachments": []
  }
}
```

### Chat Event

```json
{
  "namespace": "pbb.realtime.v1",
  "phase": "event",
  "id": "evt_chat_001",
  "type": "chat.message.event",
  "room": "chat.thread.thread_123",
  "payload": {
    "message_id": "msg_9001",
    "sender": {
      "user_id": "1024",
      "display_name": "Dispatcher Jane"
    },
    "text": "Team is en route to Lahug.",
    "attachments": [],
    "sent_at": "2026-03-28T07:45:00Z"
  }
}
```

### Call Signaling Request

```json
{
  "namespace": "pbb.realtime.v1",
  "phase": "request",
  "id": "msg_call_signal_001",
  "type": "call.signal.publish",
  "room": "call.session.session_456",
  "payload": {
    "signal_type": "offer",
    "target_user_id": "2048",
    "sdp": "..."
  }
}
```

### Error Response

```json
{
  "namespace": "pbb.realtime.v1",
  "phase": "error",
  "id": "msg_join_001",
  "type": "room.join.request",
  "room": "chat.thread.thread_123",
  "payload": {
    "code": "auth.room-denied",
    "message": "Room access denied."
  }
}
```

## Validation Rules

`PBB Realtime` should reject messages that are missing:

- valid `namespace`
- valid `phase`
- valid `id`
- valid `type`
- object `payload`

When a room-scoped type requires `room`, that field must be present and valid.

## Reliability Semantics

V1 should define these baseline behaviors explicitly.

### Duplicate Handling

- duplicate `request` ids within a bounded reconnect window should be detectable server-side
- idempotent requests such as room join should not create duplicate membership records
- message publish duplicate handling should be documented per module, especially for chat

### Retry Expectations

- clients may retry after transient disconnect or timeout
- retries should reuse a stable client request id where idempotency matters
- apps must not assume blind retries are always safe unless the module contract says so

### Idempotency Expectations

Safe idempotent V1 candidates include:

- `session.auth.request`
- `room.join.request`
- `room.leave.request`

Module-specific idempotency for publish/update actions must be documented separately.

### Ordering Guarantees

V1 should only assume best-effort ordering within a room/session on a live connection.

Apps should not assume a global total ordering across reconnects or across different rooms.

### Reconnect Behavior

- reconnect requires re-authentication
- prior room membership should be restored explicitly by client rejoin requests unless the gateway later formalizes session recovery
- apps should treat reconnect as a transport event, not silent continuity

### Presence Staleness

Presence expiry and staleness rules are defined in:

- `docs/pbb-realtime-room-and-presence-spec.md`

## Ack Strategy

Recommended V1 rule:

- transport-level requests get `ack` or `error`
- server-pushed `event` messages do not require client ack in V1 unless a specific module requires it later

## Error Contract

Error payload should use a stable shape:

- `code`
- `message`
- optional `details`

Example:

```json
{
  "code": "validation.invalid-payload",
  "message": "Payload.text must be a string.",
  "details": {
    "field": "payload.text"
  }
}
```

## Logging And Observability

The envelope must be log-friendly.

Recommended logged fields:

- `session_id`
- `project_code`
- `app_code`
- `user_id`
- `phase`
- `type`
- `room`
- request/event `id`
- result status

Sensitive values should be redacted, including:

- access tokens
- raw auth payloads
- SDP bodies if they are treated as sensitive in operational logging
- any personally sensitive chat payload content where redaction policy requires it

## Ownership Boundaries

`PBB Realtime` owns:

- envelope validation
- transport semantics
- session and room routing
- ack/error behavior
- duplicate-detection behavior where defined

Apps own:

- business payload content
- module-specific semantics inside `payload`
- client-side reconnect and retry behavior

## Acceptance Criteria

The websocket envelope V1 is acceptable when:

- all websocket traffic uses one stable outer envelope
- `request`, `ack`, `event`, and `error` phases are clearly defined
- request/response correlation uses `id`
- type naming stays transport-oriented and normalized across docs
- room-scoped traffic can be validated consistently
- duplicate, retry, reconnect, and ordering semantics are documented at a baseline level
- error payloads use a stable code/message structure
