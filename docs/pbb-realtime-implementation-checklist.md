# PBB Realtime Implementation Checklist

## Current Status

Based on the current repository state:

- the proposal and related specs are drafted and aligned
- the Laravel runtime scaffold is in place
- health/readiness/metrics endpoints and baseline tests are wired up
- the websocket runtime, auth/session admission path, room/presence flow, and envelope routing are bootstrapped

Current phase:

- `Phase 0` complete
- `Phase 1` complete
- `Phase 2` complete
- `Phase 3` complete
- `Phase 4` complete
- `Phase 5` complete
- `Phase 6` complete
- `Phase 7` in progress

## Phase 0 - Alignment And Contract Finalization

Goal: make sure the project has a stable written contract before code starts.

### Checklist

- [x] Read the shared PBB chat log
- [x] Confirm `PBB Realtime` project identity in the shared chat log
- [x] Review the proposal, token spec, websocket spec, room/presence spec, and integration checklist
- [x] Normalize terminology across docs
- [x] Define the preferred V1 trust model
- [x] Define room authorization precedence
- [x] Define canonical presence subject and payload shape

### Exit Criteria

- The doc set is consistent
- The team knows the initial implementation order
- No major contract ambiguity remains in the written specs

## Phase 1 - Project Scaffold

Goal: create the runtime foundation for the service.

### Checklist

- [x] Create the application skeleton for `PBB Realtime`
- [x] Add environment/config support for gateway URL, issuer trust, and app credentials
- [x] Add health/readiness endpoints
- [x] Add structured logging setup
- [x] Add baseline test harness
- [x] Add websocket server bootstrap
- [x] Add configuration for allowed origins

### Exit Criteria

- The service boots cleanly
- Health endpoints respond correctly
- Logging and config are in place
- Websocket bootstrap is in place

## Phase 2 - Auth And Session Admission

Goal: accept only valid browser sessions.

### Checklist

- [x] Parse and validate realtime access tokens
- [x] Enforce issuer/signature trust
- [x] Enforce expiry and audience checks
- [x] Enforce `project_code` and `app_code`
- [x] Enforce required capabilities for session admission
- [x] Implement websocket auth request handling
- [x] Reject invalid/expired/unauthorized sessions with stable error codes
- [x] Add auth failure audit logging

### Exit Criteria

- A browser can connect only after valid auth succeeds
- Invalid tokens are rejected deterministically
- Auth failures are visible in logs

## Phase 3 - Room Membership And Presence

Goal: make room access and presence stable before chat/signaling.

### Checklist

- [x] Implement room prefix validation
- [x] Enforce room authorization precedence
- [x] Enforce tenant/workspace/org boundary checks
- [x] Implement explicit room join/leave handling
- [x] Add idempotent join/leave behavior
- [x] Implement canonical presence subject handling
- [x] Implement canonical presence payload handling
- [x] Add heartbeat and stale/offline transitions
- [x] Add presence subscribe/publish enforcement

### Exit Criteria

- Authorized sessions can join only allowed rooms
- Presence state is emitted in one canonical shape
- Stale/offline transitions behave predictably

## Phase 4 - WebSocket Envelope And Transport Semantics

Goal: establish the stable message contract for all realtime traffic.

### Checklist

- [x] Implement the `pbb.realtime.v1` envelope
- [x] Validate `namespace`, `phase`, `id`, `type`, and `payload`
- [x] Implement `request`, `ack`, `event`, and `error` handling
- [x] Add duplicate request detection within a bounded reconnect window
- [x] Define retry-safe behavior for idempotent operations
- [x] Add correlation logging for request and response ids

### Exit Criteria

- All traffic uses one stable envelope
- Request/ack/error correlation is reliable
- Duplicate handling is deterministic

## Phase 5 - Chat And Call Signaling

Goal: deliver the first consumer-facing realtime features.

### Checklist

- [x] Implement chat publish routing
- [x] Implement chat event fanout
- [x] Add chat delivery/update semantics
- [x] Implement call signal publish routing
- [x] Implement call signal fanout
- [x] Enforce room and capability checks for chat/signaling paths
- [x] Keep media transport outside the websocket control plane

### Exit Criteria

- Chat messages can publish and fan out to authorized rooms
- Call signaling events relay between authorized participants
- Media transport remains separate from the gateway

## Phase 6 - Observability, Abuse Protection, And Hardening

Goal: make the gateway safe to operate in real usage.

### Checklist

- [x] Add per-app auth rate limits
- [x] Add room-join abuse controls
- [x] Add per-user/session limits if needed
- [x] Add structured operational logs
- [x] Add sensitive-data redaction rules
- [x] Add metrics for auth, joins, presence, and signaling
- [x] Add disconnect/failure diagnostics
- [x] Add regression tests for auth, room joins, presence, and envelope handling

### Exit Criteria

- The service is observable
- Abuse controls are in place
- Core behaviors are covered by tests

## Phase 7 - Consumer Onboarding And Rollout

Goal: bring the first PBB apps onto the service safely.

### Checklist

- [x] Publish the token/auth integration steps for app teams
- [x] Publish the room/presence integration steps for app teams
- [x] Publish the websocket envelope usage guide for app teams
- [ ] Validate `PBB HQ` integration first
- [ ] Validate `PBB Workspace` integration second
- [x] Coordinate with `PBB Helper` for shared frontend surfaces
- [x] Document rollout assumptions in the shared chat log
- [x] Confirm fallback behavior for apps not yet integrated

### Exit Criteria

- At least one consumer app is connected successfully
- Integration guidance is stable enough for reuse
- Rollout risks are documented and tracked

## Project Readiness View

If you want a simple summary of where the project is right now:

- documentation and contract alignment are done
- the runtime implementation is complete through the gateway control plane
- consumer validation is the remaining Phase 7 work
- `PBB HQ` and `PBB Workspace` are the first validation targets

## Suggested Working Order

1. scaffold the service
2. implement auth/session admission
3. implement room membership and presence
4. implement envelope semantics
5. add chat and signaling
6. harden ops and tests
7. onboard consumer apps
