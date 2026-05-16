# PBB Realtime SDK Integration Guide

## Purpose

Use the Realtime SDK when a product team needs:

- websocket session lifecycle
- client health and latency probes
- room join/leave
- presence publish/subscribe
- chat transport
- attachment transport
- media chunk transport
- call signaling
- small-group mesh conference behavior

Do not use the SDK to push business orchestration into Realtime. The SDK owns transport behavior. Product applications still own workflow, routing, authorization decisions before token issuance, and business persistence.

## Ownership Boundary

Realtime SDK owns:

- session admission handoff
- websocket connection management
- request envelopes
- authenticated health/latency probing
- room membership helpers
- presence roster state
- chat message normalization
- attachment chunk transport and reassembly helpers
- media chunk payload helpers
- call signaling payloads
- small-group mesh conference helpers

Product teams own:

- who may talk or call whom
- call routing and escalation rules
- case, ticket, or incident lifecycle
- operator assignment
- business persistence
- product-specific UI

## Package Shape

Current in-repo SDK layout:

- `resources/js/sdk/core`
- `resources/js/sdk/presence`
- `resources/js/sdk/chat`
- `resources/js/sdk/attachments`
- `resources/js/sdk/media`
- `resources/js/sdk/call`

Current first consumer:

- `/admin/sandbox`
- `/admin/presence-inspector`

## Recommended Integration Order

1. Core transport
   - use `RealtimeSocketClient`
   - use SDK envelope parsing
   - use `client.measureLatency()` when product UI needs a transport-quality signal
2. Presence
   - use presence join/subscribe helpers
   - use presence roster reducers
3. Chat
   - use chat publish payload builders
   - use chat normalization helpers
4. Attachments
   - use SDK validation
   - use SDK chunk transport helpers
   - use SDK receiver-side reassembly
5. Media ingest
   - enable media ingest on the project scope that should forward chunks
   - join the authorized media room
   - send `media.chunk.publish` with `buildMediaChunkPublishPayload(...)`
6. Calls
   - use SDK call signal helpers
   - use SDK conference state helpers

## Minimal Terminal Flow

1. Fetch admission/session token from the product backend.
2. Create `RealtimeSocketClient`.
3. Connect websocket.
4. On auth ack:
   - join room
   - subscribe presence
   - publish presence
5. Send and receive chat with SDK chat helpers.
6. If attachments are needed:
   - validate against policy
   - publish chunk transport
   - resolve attachment URLs from reassembled store
7. If browser-originated media chunk transport is needed:
   - enable media ingest on the project scope used by the admitted session
   - join the authorized `call.session.*` or `stream.session.*` room
   - publish `media.chunk.publish` with `buildMediaChunkPublishPayload(...)`
   - treat the immediate `media.chunk.publish` ack as Realtime queue acceptance only
   - retain local chunk data until the same room emits `media.chunk.forwarded`
   - handle `media.chunk.failed` by retrying or surfacing the product-owned failure state
   - keep storage and lifecycle handling in the product backend
8. If browser-originated product backend query forwarding is needed:
   - enable product query forwarding on the project scope used by the admitted session
   - configure the product backend URL/path, backend secret, allowed queries, payload limit, and rate limit
   - join the authorized request/response room
   - publish `app.event.publish` with `event_type: "product.query.request"`
   - include `schema_version`, `request_id`, and an allowlisted `query` in `payload.data`
   - treat the immediate ack as accepted for forwarding only
   - wait for a later `product.query.response` event from the product backend through the existing backend ingress
   - tolerate no response, stale responses, or a standardized Realtime forwarding error response
9. If calls are needed:
   - join call room
   - publish call signals
   - maintain one peer connection per remote participant for mesh

## Client Health

`RealtimeSocketClient.measureLatency({ timeoutMs = 3000, allowConcurrent = false } = {})` sends an authenticated `session.health.request` over the existing websocket and resolves to a snapshot such as:

```js
{
    ok: true,
    rtt_ms: 84,
    measured_at: "2026-05-06T04:00:00.100Z",
    server_time: "2026-05-06T04:00:00.085Z",
    authenticated: true,
    rooms_joined_count: 2,
}
```

Health requests do not require a room, do not fan out, and do not touch presence/chat/media state. Product apps should map the returned transport facts into their own signal-strength UI. `client.getConnectionState()` exposes transport/auth state only; reconnect attempt state remains product-owned in V1.

## Integration Rules

- Keep transport logic headless where possible.
- Keep UI concerns outside the SDK.
- Keep event logs, debug views, and admin pages outside the SDK.
- Preserve the sandbox as the reference terminal and first regression surface.
- For media ingest, use `media.chunk.forwarded` as the downstream persistence boundary. Do not delete browser-local chunk recovery data solely because `media.chunk.publish` returned an ack with `delivery: "queued"`.
- For binary media ingest, first send `media.chunk.prepare`, wait for `delivery: "awaiting_binary"`, send the framed binary payload built by `buildBinaryMediaChunkFrame(...)`, then wait for sender-scoped `media.chunk.queued` before treating Realtime queue acceptance as complete. Keep the same downstream durability rule: delete browser-local recovery data only after `media.chunk.forwarded`.
- For product query forwarding, Realtime only validates and forwards allowlisted `product.query.request` payloads. Product backends must re-authorize the user/context and publish `product.query.response` through backend ingress.

## Current Limits

- mesh conference hard limit: `5` participants
- caution warning begins at `4` participants
- attachment limits remain policy-aware and must still be enforced before transport
