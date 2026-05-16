# PBB Realtime App-Owned Event Ingress Proposal

Date: 2026-04-06

## Purpose

Add a narrow Realtime-owned HTTP ingress that lets a trusted product backend publish a server-originated event into an existing Realtime room.

This is intended to close a real transport gap without turning Realtime into a business-orchestration service.

Immediate first use:
- Hotline Beta live `alert_level` propagation
- admin/backend-originated settings fanout

## Current Realtime Baseline

Realtime already has the main building blocks needed for this:

- trusted admission and token validation
- client and project-scope records
- policy records attached to project scopes
- room allowlists / allowed room prefixes
- capability-driven websocket behavior
- audit event recording
- usage telemetry recording

What is missing is a trusted backend ingress that can ask Realtime to fan out an `event` envelope to room members without going through a browser websocket client.

## Problem Statement

Downstream apps can already:
- issue trusted Realtime admissions
- connect browser peers
- join rooms
- receive fanout from peer-originated transport actions

But a product backend cannot currently do this:
- authenticate as a trusted backend
- reference a Realtime project scope
- publish a server-originated event into a valid room

That forces product teams toward polling or app-local websocket bypasses, which is the wrong direction when Realtime already exists as the shared transport layer.

## Design Goal

Keep the change narrow and reuse existing Realtime structures:

- identify scope by `client_code` and `project_code`
- authorize room targeting using existing project/policy data
- enforce one explicit publish capability
- reuse the existing websocket `event` envelope path
- reuse existing audit and telemetry infrastructure

This should be implemented as an additive ingress, not a refactor of the current websocket runtime.

## Recommended V1 Direction

Add:

- `POST /api/v1/events/publish`

The endpoint is backend-only.

The caller supplies:
- `client_code`
- `project_code`
- `room`
- `event_type`
- `payload`
- optional `meta`
- optional `event_id`

Realtime validates:
- trusted backend credential
- client exists and is active
- project exists, is active, and belongs to the client
- project policy permits event publishing
- target room is allowed for that project scope
- payload size and publish rate are within acceptable limits

Realtime then:
- fans out a normal Realtime `event` envelope to current room members
- records audit and usage telemetry for the publish attempt

## Why This Reuses Existing Realtime Well

This direction maximizes what already exists:

- `project_code` is already the scope anchor
- `policy_profile_code` already connects project scope to policy
- `capabilities`, `allowed_rooms`, and `allowed_room_prefixes` are already first-class concepts
- audit and telemetry already exist as platform concerns

This minimizes change because V1 does not require:

- a new websocket actor type
- browser-side changes to transport framing
- a new product-owned message bus inside Realtime
- per-message project/policy refactors across the websocket runtime

## Recommended V1 Authorization Model

Use one explicit backend publish capability:

- `event.publish`

This capability should be treated as a Realtime policy capability, not as a Hotline-only rule.

Project-level authorization for the ingress should be resolved server-side:

1. find the project by `project_code`
2. confirm it belongs to `client_code`
3. resolve its effective policy
4. confirm the effective policy allows `event.publish`
5. confirm the requested room is allowed by the effective room rules

This keeps the ingress aligned with the current policy model and avoids introducing a separate authorization system just for backend fanout.

## Recommended V1 Trust Model

Do not invent multiple V1 trust modes.

Use one narrow backend credential shape:

- per-client backend ingress secret

Recommended request header:

- `X-Realtime-Backend-Secret`

Realtime should validate that secret against the referenced client record.

This is intentionally separate from browser admission tokens. It avoids forcing the product backend to mint a fake websocket token just to publish one server-originated event.

## Audit And Rate Limits

These should stay Realtime-owned concerns.

Audit:
- every accepted or rejected publish attempt should create an audit record
- this is platform governance, not an optional project behavior

Rate limits:
- the effective project policy may provide the rate-limit profile input
- Realtime should enforce the publish limit at ingress time
- if a specific publish rate-limit sub-profile does not exist yet, V1 can start with a small fixed server-side limit and later move that limit under policy

This avoids a large policy refactor on day one while still keeping the longer-term control model consistent.

## Recommended V1 Envelope Shape

Fanout should reuse the existing websocket event framing:

- `phase: "event"`
- `type: <event_type>`
- `payload: <payload>`
- `meta.source: "server"`

Recommended additional meta fields:
- `meta.client_code`
- `meta.project_code`
- `meta.room`
- `meta.event_id` when present

This keeps downstream consumers on one event-consumption path.

## Scope Boundary

Realtime should own:
- ingress authentication
- scope validation
- room authorization
- publish fanout
- audit
- usage telemetry
- publish rate limiting

Realtime should not own:
- product business validation
- settings persistence
- workflow rules
- event schema meaning beyond transport validation

The product backend still decides:
- when an event should be emitted
- which room is correct
- which event type is used
- what the payload means

## Recommended Hotline First Use

First room:
- `hotline.settings.global`

First events:
- `hotline.settings.updated`
- `hotline.alert_level.changed`

This is a good first acceptance target because it is:
- low-risk
- clearly backend-originated
- room-scoped
- easy to verify end to end

## Recommended Non-Goals For V1

Do not include these in the first implementation:

- generic persistent event history
- guaranteed delivery
- arbitrary cross-project publish
- product-specific payload validation in Realtime
- browser-issued use of this ingress
- full policy-model redesign

## Implementation Posture

This should be built as a narrow additive transport contract.

If V1 is kept to:
- one endpoint
- one backend auth mode
- one publish capability
- one room-authorization path
- one audit/telemetry path

then Realtime can close the transport gap efficiently without destabilizing the existing websocket runtime.
