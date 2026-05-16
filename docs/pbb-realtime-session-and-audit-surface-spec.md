# PBB Realtime Session And Audit Surface Spec

## Purpose

Define the V1 session-monitoring and audit surface for the private `PBB Realtime` admin area.

This spec covers:

- active websocket session visibility
- connection trends
- disconnect diagnostics
- auth failure review
- invalid token review
- policy change review
- incident traceability

## Scope

The session and audit surface should let operators answer:

- who is connected right now
- which client or project they belong to
- why a session disconnected
- whether auth failures are increasing
- whether a client is being abused or misconfigured
- what policy changes have been made recently

This spec should not define:

- websocket transport rules
- room authorization rules
- client registration rules
- raw trust material
- app-specific business logs

## Core Concept

The session surface is operational visibility.

The audit surface is traceability and review.

Together they should help operators understand what the realtime gateway is doing without requiring database inspection or ad hoc debugging.

## Recommended Session Views

### Active Sessions

Show:

- session id
- user identity
- client code
- project code
- app code
- connect time
- last activity time
- current state

### Connection Summary

Show:

- current session count
- session count by client
- session count by project
- session count by app
- disconnect rate trend

### Disconnect Diagnostics

Show:

- disconnect reason
- disconnect timestamp
- whether disconnect was client-initiated, gateway-initiated, or policy-initiated

### Stale Or Orphaned Sessions

Show:

- sessions with no recent heartbeat
- sessions that appear stuck
- sessions requiring review

## Recommended Audit Views

### Auth Failures

Show:

- invalid token attempts
- expired token attempts
- issuer/signature failures
- audience failures
- capability-denial failures

### Policy Changes

Show:

- client status changes
- trust metadata changes
- origin policy changes
- capability changes
- room policy changes
- rate-limit changes

### Incident Events

Show:

- quarantine actions
- force-disconnect actions
- emergency revoke actions
- maintenance actions when added later

## Recommended V1 Fields

### Session Record

Required:

- `session_id`
- `client_code`
- `project_code`
- `user_identity`
- `status`

Recommended:

- `app_code`
- `connected_at`
- `last_activity_at`
- `disconnect_reason`
- `room_count`

### Audit Record

Required:

- `audit_id`
- `actor_identity`
- `action_type`
- `target_type`
- `target_code`
- `timestamp`

Recommended:

- `before_state`
- `after_state`
- `reason`
- `client_code`
- `project_code`

## Status Semantics

### Session Status

Recommended V1 values:

- `connected`
- `stale`
- `disconnected`
- `forced_disconnect`

`idle` is intentionally not a session status here. It is a presence-state concept in the room and presence spec, not a session lifecycle state.

### Audit Action Types

Recommended V1 action families:

- `auth_failure`
- `policy_change`
- `status_change`
- `disconnect_action`
- `quarantine_action`
- `review_action`

## Access Rules

The session and audit surface should be private and operator-only.

Recommended access model:

- session-authenticated browser access
- role or capability checks
- audit logging for all operator actions

## UI Expectations

The V1 UI should support:

- active session list
- session detail view
- auth-failure list
- policy-change list
- audit timeline
- filter by client, project, app, user, and time

The UI should optimize for incident review and operational triage.

## Safety Expectations

The session and audit surface should:

- avoid exposing raw sensitive payloads
- redact secrets and tokens
- keep incident actions auditable
- avoid making policy or access changes without explicit operator intent

## Dependencies

This spec depends on:

- `docs/pbb-realtime-admin-surface-proposal.md`
- `docs/pbb-realtime-client-management-spec.md`
- `docs/pbb-realtime-policy-and-capability-management-spec.md`
- `docs/pbb-realtime-proposal.md`
- `docs/pbb-realtime-token-and-auth-spec.md`

## Exit Criteria

The session and audit surface is ready for implementation when:

- session fields are agreed
- audit record shape is agreed
- operator access rules are agreed
- redaction rules are agreed
- filter and review workflows are agreed

## Bottom Line

The session and audit surface is what gives `PBB Realtime` operational credibility.

Keep it read-heavy, reviewable, private, and safe.
The goal is visibility, not ad hoc debugging access.
