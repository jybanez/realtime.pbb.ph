# PBB Realtime Proposal

## PBB Context

PBB is a growing ecosystem of browser-based operational systems and platform services.

Official website:

- `https://pbb.ph`

New teams should treat PBB as:

- a shared product ecosystem
- a cross-project engineering environment
- a set of projects that are expected to coordinate, not operate in isolation

That means a new team is not only building its own service or app.

It is also joining an existing working environment with:

- established PBB projects
- existing cross-project contracts
- shared helper-library conventions
- shared communication expectations

Early PBB projects and platform surfaces already include work such as:

- `PBB HQ`
- `PBB Workspace`
- `PBB Relay`
- `PBB Maestro`
- `PBB Helper`
- other PBB-facing apps and services already listed in the shared chat log

## Required New-Team Orientation

Every new PBB team should complete the following onboarding steps before beginning feature implementation.

### 1. Understand The PBB Ecosystem

The team should understand:

- what PBB is trying to build as an ecosystem
- which projects already exist
- which cross-project seams already exist
- how their new project is expected to communicate with the rest of PBB

### 2. Verify Access To Shared Cross-Project Communication

The new team must verify that it can access:

- the `pbb-chat-log` skill
- the shared chat log file:
  - `C:\wamp64\www\pbb\chat_log.md`

This is a required onboarding step.

The team should not begin operating as an isolated project with no visibility into ongoing cross-project work.

### 3. Read The Shared Chat Log

The new team should read the **entire contents** of:

- `C:\wamp64\www\pbb\chat_log.md`

This is important because the chat log is the shared cross-project coordination record for:

- active topics
- current project responsibilities
- ongoing proposals
- resolved and unresolved integration seams
- expected communication practices between teams

### 4. Read Required HQ Onboarding References

The new team should also read:

- `C:\wamp64\www\pbb\hub.ph\docs\pbb-helper-adoption-guide.md`
- `C:\wamp64\www\pbb\hub.ph\docs\pbb-laravel-application-baseline.md`

These should be treated as required onboarding references, not optional reading.

### 5. Learn How To Communicate With Other Teams

The new team should understand that PBB projects are expected to coordinate through the shared chat log structure.

That includes:

- checking current active topics
- understanding which existing teams already own adjacent concerns
- raising shared gaps and proposals clearly
- avoiding isolated local decisions when a cross-project contract is involved

## Purpose

Define the product and architecture direction for `PBB Realtime` as a shared backend service for realtime communication and signaling across PBB projects.

This proposal is intentionally product/system oriented.

It defines:

- what `PBB Realtime` should be
- what responsibilities it should own
- what responsibilities should remain in each app
- how PBB apps should authenticate and consume it

It does not yet define:

- the full module-specific event catalogs
- the final room naming matrix
- the full WebRTC signaling payloads

Those should follow as separate specs after the new team is formed.

## Team Onboarding Requirement For `PBB Realtime`

The future `PBB Realtime` team should not treat this proposal as the only onboarding material.

Before implementation starts, the team should:

1. read the entire shared chat log:
   - `C:\wamp64\www\pbb\chat_log.md`
2. confirm access to the `pbb-chat-log` skill and the shared chat log file
3. read:
   - `C:\wamp64\www\pbb\hub.ph\docs\pbb-helper-adoption-guide.md`
   - `C:\wamp64\www\pbb\hub.ph\docs\pbb-laravel-application-baseline.md`
4. identify which existing teams are likely early consumers, integration partners, and coordination-only teams

This proposal should therefore be treated as:

- architecture direction
- plus onboarding guidance for joining the wider PBB ecosystem

## Canonical Vocabulary

The following vocabulary should remain consistent across all `PBB Realtime` docs.

### Capabilities

Use capability names in the form:

- `<domain>.<action>`

Recommended V1 examples:

- `session.connect`
- `room.join`
- `presence.subscribe`
- `presence.publish`
- `chat.publish`
- `chat.subscribe`
- `call.signal`
- `stream.publish`
- `stream.subscribe`

### Message Types

Use transport-facing websocket message types in the form:

- `<domain>.<resource>.<action>`

Recommended V1 examples:

- `session.auth.request`
- `room.join.request`
- `room.leave.request`
- `chat.message.publish`
- `chat.message.event`
- `presence.state.event`
- `call.signal.publish`

### Rooms

Use reserved room prefixes in the form:

- `presence.global.<scope_id>`
- `presence.workspace.<workspace_id>`
- `chat.thread.<thread_id>`
- `call.session.<session_id>`
- `stream.session.<session_id>`

Room rules are expanded in:

- `docs/pbb-realtime-room-and-presence-spec.md`

## Product Direction

`PBB Realtime` should be a shared backend gateway for:

- websocket session management
- presence state and heartbeat handling
- room membership and authorization
- chat event fanout
- audio/video call signaling
- stream/session coordination

It should become the common realtime control plane used by multiple PBB browser apps instead of letting each project build its own ad hoc websocket/signaling service.

## Why This Should Exist

Without a shared realtime service, each project will drift into its own:

- auth and token model
- room naming model
- presence model
- chat event model
- call signaling contract
- stream/session lifecycle

That creates avoidable cross-project inconsistency and makes future integration harder.

A shared service gives PBB a consistent foundation for:

- chat and messaging
- presence and online status
- audio/video call signaling
- stream-oriented operational tools

## What `PBB Realtime` Should Be

`PBB Realtime` should be a **shared realtime gateway**, not a browser-app feature hidden inside one existing PBB project.

It should be treated as a platform service.

Recommended positioning:

- shared backend service
- used by multiple PBB apps
- owned by its own team
- aligned with the rest of the PBB platform through explicit contracts

## V1 Trust And Token Issuance Model

The preferred V1 trust model should be explicit.

### Preferred V1 Path

The preferred V1 model is:

1. each app authenticates its own user normally
2. the app backend issues a short-lived signed realtime access token for that user
3. the browser connects to `PBB Realtime` with that short-lived token
4. `PBB Realtime` validates the token against the approved issuer/signing trust model

This keeps:

- user auth app-owned
- browser credentials short-lived
- gateway validation centralized
- project-local business authorization out of the gateway

### Acceptable Fallback Path

A server-to-server token issuance path from `PBB Realtime` is acceptable later if needed, but should be treated as a fallback or later-phase model.

V1 should optimize for:

- app-backend-issued user-scoped tokens

This is the authoritative V1 direction unless the Realtime team formally revises it.

## What `PBB Realtime` Should Own

`PBB Realtime` should own:

- websocket session authentication and lifecycle
- capability validation
- room join/leave enforcement
- presence heartbeat and staleness handling
- chat event routing/fanout
- signaling/control-plane events for audio/video sessions
- stream/session membership coordination
- structured logs, auditing, and rate limiting for gateway behavior

## What Each App Should Still Own

Each consuming app should still own:

- user authentication
- business authorization decisions beyond gateway capability checks
- persistence of app-specific records
- app-specific domain logic
- frontend realtime UX and app state management
- media transport implementation details

## Keep Media Transport Separate

`PBB Realtime` should not be the primary raw media transport path for audio/video traffic.

Recommended model:

- `PBB Realtime` handles signaling and coordination
- actual media transport uses WebRTC or another media-specific path

This is an important architectural boundary and should remain explicit.

## Reliability Semantics That Must Be Defined Early

Before implementation starts, the Realtime team should treat the following as V1 requirements, not optional future polish:

- duplicate request/event handling rules
- idempotency expectations for publish and join requests
- ordering expectations per room/session
- reconnect semantics after transient disconnect
- token-expiry reconnect semantics
- presence staleness and heartbeat expiry rules

These are expanded further in:

- `docs/pbb-realtime-websocket-envelope-spec.md`
- `docs/pbb-realtime-room-and-presence-spec.md`

## Operational Scope

`PBB Realtime` is a platform dependency.

That means its operational contract should be stricter than a normal app feature.

V1 should include:

- TLS-only access
- per-app and per-user rate limits where appropriate
- structured logging and auditability
- origin allowlists where needed
- room join abuse protection
- clear disconnect/failure diagnostics
- explicit redaction rules for sensitive payload fields in logs

## Consumers, Integration Partners, And Coordination-Only Teams

The Realtime team should separate these categories early.

### Likely Early Consumers

- `PBB HQ`
- `PBB Workspace`
- future communication-enabled PBB apps

### Likely Integration Partners

- `PBB Helper`
  - shared frontend communication surfaces
- `PBB Workspace`
  - embedded app/runtime coordination
- `PBB HQ`
  - likely early app-side adoption

### Likely Coordination-Only Teams At First

- teams that do not consume the gateway directly yet but need visibility into cross-project contract changes

This distinction matters for rollout sequencing and communication.

## Recommended Follow-On Specs

Before implementation, the new team should produce or finalize these follow-on specs in this order:

1. token and auth contract
2. websocket envelope contract
3. room and presence contract
4. chat event contract
5. call signaling contract
6. project integration checklist

## Bottom Line

`PBB Realtime` should be built as:

- a shared backend gateway
- with app-backend-issued short-lived user-scoped tokens as the preferred V1 trust model
- with explicit room authorization
- with strict reliability and operational semantics
- and with actual media transport kept outside the websocket control plane
