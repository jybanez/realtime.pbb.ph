# PBB Realtime SDK Proposal

## Goal

Provide a reusable client SDK that lets other PBB teams integrate with `PBB Realtime` without rebuilding transport and media behavior from scratch.

The SDK should make `PBB Realtime` the owner of:

- websocket transport
- room and presence behavior
- chat transport
- attachment transport behavior
- call signaling
- small-group mesh conference behavior

The SDK should not own product-specific business orchestration.

## Why

The sandbox is now proving transport behavior directly:

- session admission
- room membership
- presence
- chat
- attachment chunk transport
- audio/video calling
- small-group mesh media

That behavior should not stay trapped in the sandbox page.

If each client team implements its own websocket, presence, chat, and call logic:

- behavior will drift
- bug fixes will duplicate
- policy behavior will be interpreted inconsistently
- call/conference reliability will be harder to improve centrally

The correct platform direction is:

- `Sandbox` as reference terminal and test harness
- `SDK` as reusable transport library
- client apps as product-specific orchestration and UI

## Ownership Boundary

### Realtime SDK Should Own

- token/session admission flow
- websocket lifecycle
- reconnect strategy
- room join/leave
- presence publish/subscribe
- chat publish/receive
- attachment chunk transport
- call signaling
- mesh conference link management
- media device helpers
- consistent transport-state events

### Product Teams Should Own

- operator assignment
- who should call whom
- room naming strategy at the business level
- case/ticket state
- dispatch workflow
- storage and ingestion outside explicit Realtime transport behavior
- app-specific UI and workflow composition

This is the key rule:

- Realtime owns transport behavior
- product apps own business behavior

## Recommended SDK Shape

Start with a headless SDK.

Do not start with a UI framework wrapper.

### Layer 1 - Core Client

Suggested responsibilities:

- bootstrap from issued session token
- open websocket
- authenticate session
- room join/leave
- reconnect lifecycle
- request/event envelope handling
- event emitter / subscription API

Suggested surface:

- `RealtimeClient`
- `connect()`
- `disconnect()`
- `joinRoom(room)`
- `leaveRoom(room)`
- `on(event, handler)`
- `off(event, handler)`
- `destroy()`

### Layer 2 - Presence Module

Suggested responsibilities:

- publish presence state
- subscribe to presence
- maintain presence roster per room
- expose roster updates as state or events

Suggested surface:

- `presence.publish(room, state, meta?)`
- `presence.subscribe(room)`
- `presence.getRoster(room)`

### Layer 3 - Chat Module

Suggested responsibilities:

- publish chat messages
- normalize chat events
- attachment metadata support
- outgoing/incoming delivery lifecycle

Suggested surface:

- `chat.send(room, text, attachments?)`
- `chat.onMessage(handler)`

### Layer 4 - Attachment Transport Module

Suggested responsibilities:

- sender-side file validation
- chunking
- chunk publish lifecycle
- attachment transport progress
- receiver-side reassembly

Suggested surface:

- `attachments.send(room, files, options?)`
- `attachments.onChunk(handler)`
- `attachments.onTransferProgress(handler)`

This module should continue to respect current policy semantics:

- max attachment count
- max bytes per attachment
- max bytes per message
- chunk event / byte limits

### Layer 5 - Call / Conference Module

Suggested responsibilities:

- call room participation
- targeted call signaling
- peer connection lifecycle
- per-remote mesh connections
- renegotiation
- local media management
- remote media state map

Suggested surface:

- `calls.start({ room, mode })`
- `calls.answer()`
- `calls.end()`
- `calls.setMicEnabled(enabled)`
- `calls.setCameraEnabled(enabled)`
- `calls.getParticipants()`
- `calls.getRemoteStreams()`
- `calls.getLocalStream()`

Important boundary:

- the SDK should support small-group mesh
- it should not pretend to be an SFU abstraction yet

## Architecture Recommendation

### Package Name

Recommended working name:

- `@pbb/realtime-sdk`

### Internal Modules

Recommended internal structure:

- `core/`
- `presence/`
- `chat/`
- `attachments/`
- `calls/`
- `media/`
- `types/`
- `utils/`

### State Model

The SDK should be explicit about transport state.

Suggested state buckets:

- connection state
- joined rooms
- presence rosters
- chat streams
- attachment transfers
- call participants
- peer connection map
- remote stream map

The current sandbox implementation already proves that these concerns should be distinct.

## Extraction Strategy

Do not copy the whole sandbox page into a package.

Instead:

1. identify the headless transport behavior in `resources/js/app.js`
2. move it into SDK-ready modules
3. keep the sandbox page as a thin reference UI using those modules

The SDK should absorb:

- envelope send/receive
- websocket session lifecycle
- presence roster logic
- attachment chunk transport logic
- mesh call signaling and per-remote peer connection logic

The sandbox should keep:

- page layout
- operator controls
- event log
- media display composition

## Initial Target Consumer

The first practical SDK target should be:

- PBB Hotline terminals

Why:

- it needs chat and calls concurrently
- it already matches the sandbox behavior closely
- it is the strongest validation case for transport ownership

## Recommended Rollout Phases

### Phase 1 - Headless Core

- extract websocket/session lifecycle
- extract room and presence behavior
- extract chat transport
- keep sandbox page running on the new core

### Phase 2 - Attachment And Call Modules

- extract attachment chunk transport
- extract call signaling
- extract mesh peer-connection management
- keep sandbox as the first real consumer

### Phase 3 - Public Integration Contract

- publish typed SDK API
- write integration guide for other teams
- provide one reference terminal implementation

### Phase 4 - Optional UI Bindings

Only after the headless SDK is stable:

- React hooks or adapters
- helper-component integrations
- UI-ready utilities

## Non-Goals For V1

The SDK should not attempt all of these immediately:

- SFU abstraction
- server-side media routing
- business workflow orchestration
- product-specific chat UI
- product-specific dispatch logic
- persistence/storage APIs beyond transport-owned behavior

## Recommendation

Proceed with the SDK.

The sandbox now contains enough real transport behavior that continuing to keep it page-local would create duplication across teams.

The correct move is:

- extract transport and media behavior into a headless SDK
- keep business logic outside
- keep the sandbox as the reference terminal built on the SDK
