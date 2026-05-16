# PBB Realtime Project Integration Checklist

## Purpose

Define the practical onboarding and implementation checklist for any PBB project integrating with `PBB Realtime`.

This checklist is written for:

- the future `PBB Realtime` team
- initial consumer app teams such as `PBB HQ` and `PBB Workspace`
- future PBB teams that need chat, presence, call signaling, or stream/session coordination
- coordination partners such as `PBB Helper`

## Required Reading Before Integration

Before starting implementation, the team should read:

- `docs/pbb-realtime-proposal.md`
- `docs/pbb-realtime-token-and-auth-spec.md`
- `docs/pbb-realtime-websocket-envelope-spec.md`
- `docs/pbb-realtime-room-and-presence-spec.md`
- `C:\wamp64\www\pbb\chat_log.md`
- `C:\wamp64\www\pbb\hub.ph\docs\pbb-helper-adoption-guide.md`
- `C:\wamp64\www\pbb\hub.ph\docs\pbb-laravel-application-baseline.md`

The team should also verify access to:

- the `pbb-chat-log` skill
- the shared chat log file

## Project Role Classification

Before work starts, identify whether the project is acting as:

- an initial consumer
- an integration partner
- a coordination-only team for the current phase

This should be made explicit in the rollout discussion so adoption planning stays clear.

## Team Coordination

- [ ] Confirm the project can access the shared PBB chat log workflow.
- [ ] Read the entire shared chat log before proposing new cross-project contracts.
- [ ] Review current `#Active Topics` and adjacent team responsibilities.
- [ ] Announce planned `PBB Realtime` integration work in the shared chat log before implementation begins.
- [ ] Identify which existing teams need to be coordinated with.
- [ ] Classify whether each involved team is a consumer, integration partner, or coordination-only participant.

## App-Level Readiness

- [ ] Confirm the app has a stable authenticated user/session model.
- [ ] Confirm the app backend can issue short-lived realtime access tokens.
- [ ] Confirm the app can expose a backend endpoint for the frontend to obtain realtime connection data.
- [ ] Confirm the app has clear rules for which users may use:
  - chat
  - presence
  - call signaling
  - stream/session features
- [ ] Confirm the app uses generated client/project identifiers from the PBB Realtime admin surface rather than operator-typed codes.

## Realtime Token Integration

- [ ] Implement an app-local endpoint such as `POST /api/realtime/token`.
- [ ] Ensure the browser receives only a short-lived user-scoped realtime access token.
- [ ] Do not expose backend-only project credentials to the browser.
- [ ] Ensure token claims include:
  - `aud`
  - `exp`
  - `project_code`
  - `app_code`
  - user identity
  - capability claims
- [ ] Define token refresh or reconnect behavior before expiry.
- [ ] Define how the app handles expired or rejected realtime auth.
- [ ] Align to the preferred V1 trust model of app-backend-issued tokens.

## Frontend Bootstrap Integration

- [ ] Include realtime bootstrap data in the frontend app bootstrap or equivalent initialization flow.
- [ ] Provide the frontend with:
  - gateway URL
  - short-lived token
  - app-specific realtime feature flags if needed
- [ ] Ensure realtime initialization does not block the entire app shell if the gateway is temporarily unavailable.

## WebSocket Client Integration

- [ ] Implement the client using the `pbb.realtime.v1` envelope.
- [ ] Ensure all outbound messages send:
  - `namespace`
  - `phase`
  - `id`
  - `type`
  - object `payload`
- [ ] Ensure request ids are unique within the session.
- [ ] Handle `ack`, `event`, and `error` phases explicitly.
- [ ] Add reconnect handling with explicit re-auth behavior.
- [ ] Add bounded duplicate-handling strategy where request retry is possible.

## Room And Capability Integration

- [ ] Define the rooms/channels the app needs using reserved prefixes.
- [ ] Ensure room joins are requested explicitly, not assumed automatically.
- [ ] Ensure the app only attempts room joins allowed by its realtime capabilities.
- [ ] Document tenant/workspace/org boundaries affecting room access.
- [ ] Document whether `allowed_rooms` or `allowed_room_prefixes` are needed.
- [ ] Document the app's room naming usage for review by the `PBB Realtime` team.

## Reliability Semantics

- [ ] Define which requests are safe to retry.
- [ ] Define idempotent request handling expectations.
- [ ] Define ordering assumptions the app is allowed to make.
- [ ] Define reconnect recovery behavior.
- [ ] Define presence heartbeat interval and stale/offline UX behavior.

## Module-Specific V1 Scope

### Chat

- [ ] Define which thread or room identifiers map to chat rooms.
- [ ] Define outbound chat message payload shape.
- [ ] Define how attachments are represented in chat events.
- [ ] Define duplicate and retry handling for outbound message publish.
- [ ] Define delivery/update expectations between app and gateway.

### Presence

- [ ] Define presence scope:
  - global
  - workspace-scoped
  - thread-scoped
  - session-scoped
- [ ] Define heartbeat frequency and stale/offline thresholds.

### Call Signaling

- [ ] Define signaling-only usage through `PBB Realtime`.
- [ ] Keep actual audio/video media transport outside the websocket payload path.
- [ ] Define invite, accept, reject, end, and signal event needs.

### Stream Or Session Coordination

- [ ] Clarify whether the app needs live event streaming, media session coordination, or both.
- [ ] Avoid using websocket raw payloads as the primary media transport strategy.

## Security And Operations

- [ ] Require TLS for all realtime connections.
- [ ] Ensure app/browser origins are reviewed and allowlisted where appropriate.
- [ ] Add per-app rate limits for token issuance and websocket auth attempts.
- [ ] Add room-join rate limits and abuse controls.
- [ ] Decide whether per-user concurrent session limits are needed.
- [ ] Add structured logs for:
  - token issuance
  - connect/auth success or failure
  - room join attempts
  - disconnect reasons
- [ ] Define what must be redacted from logs.
- [ ] Define incident/debug workflow for failed realtime auth and room access.
- [ ] Define audit expectations for abuse or anomalous usage.

## UX And Product Coordination

- [ ] Align frontend helper usage with `PBB Helper` where shared UI surfaces are involved.
- [ ] Ensure chat/call/presence features do not invent app-local realtime UI contracts prematurely when a shared helper contract is intended.
- [ ] Identify which user-visible states need UX guidance:
  - connecting
  - reconnecting
  - disconnected
  - message failed
  - presence stale
  - call signaling failed

## Testing

- [ ] Add local dev/test setup for the app's realtime integration.
- [ ] Verify successful auth and session establishment.
- [ ] Verify expired-token behavior.
- [ ] Verify room join denial behavior.
- [ ] Verify reconnect behavior after transient disconnect.
- [ ] Verify retry and duplicate-handling behavior where applicable.
- [ ] Verify presence stale/offline transitions.
- [ ] Verify app-local fallback or degraded UX when the gateway is unavailable.
- [ ] Verify logs and diagnostics are sufficient for debugging integration failures.

## Documentation Deliverables

Each integrating project should document:

- [ ] its realtime token endpoint
- [ ] its room usage
- [ ] its capability model
- [ ] its event types and payload shapes where project-specific
- [ ] its reconnect/expiry behavior
- [ ] its retry/idempotency assumptions
- [ ] any deviations from shared PBB realtime guidance

## Readiness Gate

A PBB project should not be treated as `PBB Realtime`-ready until:

- onboarding and shared chat-log reading are complete
- the project role in the rollout is explicit
- token issuance and validation flow are clear
- websocket envelope usage is aligned with the shared spec
- room and capability usage are documented
- reliability/reconnect/staleness rules are documented
- failure and abuse paths are tested
- affected teams have been informed through the shared chat log
