# PBB Realtime Room And Presence Spec

## Purpose

Define the V1 room taxonomy, room authorization model, and presence lifecycle for `PBB Realtime`.

This spec exists because room scope affects:

- presence subscriptions
- chat fanout
- call signaling
- stream/session coordination
- authorization boundaries

## Required Context

This spec assumes the future `PBB Realtime` team has already read:

- `docs/pbb-realtime-proposal.md`
- `docs/pbb-realtime-token-and-auth-spec.md`
- `docs/pbb-realtime-websocket-envelope-spec.md`

## Core Design Rule

Room names must be standardized early.

Apps should not invent project-local room prefixes once `PBB Realtime` goes live.

## Reserved Room Prefixes

Recommended V1 reserved prefixes:

- `presence.global.`
- `presence.workspace.`
- `chat.thread.`
- `call.session.`
- `stream.session.`

These prefixes should be treated as gateway-owned protocol surface.

## Room Naming Model

Recommended room format:

- `<domain>.<scope>.<scope_id>`

Examples:

- `presence.global.org_001`
- `presence.workspace.workspace_001`
- `chat.thread.thread_123`
- `call.session.session_456`
- `stream.session.session_789`

## Room Categories

### Global Presence Rooms

Used for:

- broad online/offline presence within a project, tenant, or org scope

Example:

- `presence.global.org_001`

### Workspace Presence Rooms

Used for:

- workspace-scoped presence and activity views

Example:

- `presence.workspace.workspace_001`

### Chat Thread Rooms

Used for:

- thread-scoped chat events and typing activity

Example:

- `chat.thread.thread_123`

### Call Session Rooms

Used for:

- signaling for one call session

Example:

- `call.session.session_456`

### Stream Session Rooms

Used for:

- stream or live-session coordination events

Example:

- `stream.session.session_789`

## Authorization Model

Room authorization must combine:

- token capabilities
- project/app identity
- room prefix rules
- scope boundaries such as tenant/workspace/org
- optional per-room or prefix-based grants

A valid authenticated session must not be able to join arbitrary rooms by default.

## Authorization Precedence And Composition Rules

To prevent inconsistent authorization outcomes, V1 should use the following precedence model.

### Rule 1: Capability Gate First

The session must have the capability required for the room and intended action.

Examples:

- `room.join`
- `presence.subscribe`
- `presence.publish`
- `chat.subscribe`
- `chat.publish`
- `call.signal`

If the capability is missing, authorization fails immediately.

### Rule 2: Project Scope Boundary Is Mandatory

`project_code` is the required project-scope boundary under a trusted client.

A token may only access rooms belonging to its own project unless a future shared-cross-project room model is explicitly designed and documented.

### Rule 3: App Boundary Narrows Within Project

`app_code` narrows access within the project.

If the room contract is app-specific, the token's `app_code` must match the room's app scope or be otherwise explicitly authorized by the gateway's room policy.

### Rule 4: Tenant And Org Boundaries Narrow Further

If a token includes:

- `tenant_id`
- `org_id`

then room access must remain inside those scopes where the room category is tenant- or org-scoped.

A token scoped to one tenant or org must not access rooms from another tenant or org.

### Rule 5: Workspace Boundary Overrides Generic Presence Scope Where Applicable

If a room is workspace-scoped, such as:

- `presence.workspace.<workspace_id>`

then `workspace_id` must match the token's allowed workspace scope where present.

Workspace scope is narrower than generic project scope and must be enforced before room membership succeeds.

### Rule 6: Explicit Room Grants Narrow Last

If the token includes:

- `allowed_rooms`
- `allowed_room_prefixes`

these claims narrow the final allowed set.

They must not expand access beyond:

- capability checks
- project/app boundary checks
- tenant/org/workspace scope checks

### Effective Composition Rule

The final allowed room set is the intersection of:

- capability permission
- project/app boundary permission
- tenant/org/workspace scope permission
- explicit room or prefix grants if present

If any layer denies access, the room join fails.

## Capability Linkage

Recommended V1 linkage:

- joining any room requires `room.join`
- subscribing to presence state requires `presence.subscribe`
- publishing presence state requires `presence.publish`
- publishing chat events requires `chat.publish`
- receiving chat events requires `chat.subscribe`
- participating in call signaling requires `call.signal`
- participating in stream coordination requires `stream.publish` and/or `stream.subscribe`

## Prefix-Based Grants

Optional V1 token claims may include:

- `allowed_rooms`
- `allowed_room_prefixes`

Examples:

- `allowed_rooms: ["chat.thread.thread_123"]`
- `allowed_room_prefixes: ["presence.workspace.workspace_001", "chat.thread."]`

These claims should narrow room access, not broaden it beyond capability and scope rules.

## Project And Tenant Boundaries

Room access should remain bounded by:

- `project_code`
- `app_code`
- optional `tenant_id`
- optional `workspace_id`
- optional `org_id`

A token issued for one project or tenant should not silently join rooms belonging to another boundary unless explicitly designed and authorized.

## Presence Model

Presence should be treated as ephemeral state, not durable business truth.

Recommended V1 presence data examples:

- `online`
- `offline`
- `idle`
- `busy`
- `in_call`

The exact state catalog may expand later, but the gateway should keep the first version narrow.

## Canonical Presence Subject Shape

Presence events should identify one canonical subject.

Recommended V1 subject shape:

```json
{
  "project_code": "hq",
  "app_code": "pbb-hq",
  "user_id": "1024",
  "tenant_id": null,
  "org_id": "org_001",
  "workspace_id": null,
  "session_id": "sess_001"
}
```

Required V1 subject fields:

- `project_code`
- `app_code`
- `user_id`
- `session_id`

Optional scope fields:

- `tenant_id`
- `org_id`
- `workspace_id`

## Canonical Presence Payload Shape

Recommended V1 presence payload shape:

```json
{
  "subject": {
    "project_code": "hq",
    "app_code": "pbb-hq",
    "user_id": "1024",
    "tenant_id": null,
    "org_id": "org_001",
    "workspace_id": null,
    "session_id": "sess_001"
  },
  "state": "online",
  "status_text": null,
  "updated_at": "2026-03-28T10:05:00Z",
  "expires_at": "2026-03-28T10:06:15Z"
}
```

Recommended V1 fields:

- `subject`
- `state`
- `status_text`
- `updated_at`
- `expires_at`

Optional later fields may include app-defined metadata, but the core subject and state fields should remain stable.

Recommended additive V1 metadata field:

- `meta`
  - optional app-owned UI context only
  - must be a shallow JSON-safe object
  - values are limited to `string`, `number`, `boolean`, or `null`
  - arrays and nested objects are rejected in V1
  - encoded size should remain small; current gateway cap is 1024 bytes

## Presence Publish And Subscribe

### Presence Publish

A client may publish presence or heartbeat updates only if it has:

- `presence.publish`

Recommended publish payload:

```json
{
  "state": "online",
  "status_text": null,
  "meta": {
    "incident_id": 96,
    "workbench_active": true
  },
  "updated_at": "2026-03-28T10:05:00Z"
}
```

The gateway should enrich the published state with canonical subject and expiry data derived from the authenticated session.

### Presence Subscribe

A client may subscribe to presence events only if it has:

- `presence.subscribe`

Recommended event payload:

```json
{
  "subject": {
    "project_code": "hq",
    "app_code": "pbb-hq",
    "user_id": "1024",
    "tenant_id": null,
    "org_id": "org_001",
    "workspace_id": null,
    "session_id": "sess_001"
  },
  "state": "online",
  "status_text": null,
  "meta": {
    "incident_id": 96,
    "workbench_active": true
  },
  "updated_at": "2026-03-28T10:05:00Z",
  "expires_at": "2026-03-28T10:06:15Z"
}
```

If metadata is invalid or exceeds the encoded-size cap, the gateway should reject `presence.publish` with a payload validation error.

## Heartbeat And Staleness

Presence requires explicit freshness rules.

Recommended V1 starting point:

- heartbeat interval target:
  - every 20 to 30 seconds
- presence stale threshold:
  - mark stale after 60 to 90 seconds without heartbeat
- offline threshold:
  - mark offline after disconnect or confirmed expiry beyond stale window

These values may be tuned operationally, but the existence of the thresholds should not be optional.

## Reconnect And Presence Recovery

On reconnect:

- the client re-authenticates
- the client rejoins required rooms explicitly
- presence should not be assumed continuous unless the reconnect happens within the staleness window and the gateway explicitly preserves it

Apps should not assume invisible transport recovery.

## Duplicate And Idempotent Room Semantics

Room handling should be safe under retry.

Recommended V1 behavior:

- duplicate room join requests for the same authenticated session and room should be idempotent
- duplicate room leave requests should be safe
- presence updates may overwrite the latest ephemeral state for the same subject within a room scope

## Audit And Abuse Controls

`PBB Realtime` should log or meter:

- room join attempts
- room join denials
- excessive room churn
- abnormal presence publish frequency
- scope/prefix authorization failures

Operational controls should include:

- per-app room join rate limits
- per-session room count limits if needed
- abuse throttling for high-frequency presence spam

## Ownership Boundaries

`PBB Realtime` owns:

- reserved room taxonomy
- room authorization enforcement
- room membership lifecycle
- canonical presence subject and event shape
- presence staleness/expiry behavior

Apps own:

- which business objects map to rooms
- when a user should be allowed to request room membership
- app-local meaning of optional presence metadata beyond the shared fields
- how presence is surfaced in app UX

## Acceptance Criteria

The room and presence contract V1 is acceptable when:

- reserved room prefixes are standardized
- room naming is consistent across projects
- room authorization precedence is explicit
- room access is capability- and scope-bound
- presence subject and payload shapes are canonical
- presence heartbeat and staleness rules are defined
- duplicate room joins/leaves are safe
- reconnect behavior does not rely on hidden assumptions
- room abuse controls are part of the operational contract
