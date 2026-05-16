# PBB Realtime Policy Property Editor Proposal

## Goal

Replace raw textarea-based policy parameter editing with a shared Helper-driven property editor so operators can adjust policy parameters through typed controls instead of hand-editing JSON.

## Why

The current policy model is structurally valid but operator-hostile.

Current problems:
- operators have to understand the exact JSON shape
- small edits are easy to break with invalid syntax
- the UI does not make supported parameters obvious
- validation happens too late and too generically

The new Helper update makes this solvable with a shared component instead of a Realtime-only custom editor.

## Helper Dependency

Helper team update from shared chat log:
- `helpers.pbb.ph v0.21.35`
  - `ui.property.editor` now supports hosted `ui.select` rows
  - supports multiple-value property editing
- `helpers.pbb.ph v0.21.36`
  - `ui.select` multiple-select rendering improved

Relevant vendored references:
- `public/vendor/helpers.pbb.ph/js/ui/ui.property.editor.js`
- `public/vendor/helpers.pbb.ph/docs/ui-property-editor-v1-spec.md`
- `public/vendor/helpers.pbb.ph/demos/demo.property.editor.html`

## Direction

Treat policy profiles as typed configuration sections.

Do not make the operator author raw JSON by default.

Instead:
- render each profile through `ui.property.editor`
- map known policy parameters to typed rows
- keep advanced JSON as a fallback, not the primary editing path

## Policy Areas To Edit

### 1. Capability Profile

Render as grouped toggles or multi-selects for known capabilities.

Examples:
- `rooms.join`
- `rooms.leave`
- `presence.publish`
- `presence.subscribe`
- `chat.publish`
- `chat.subscribe`
- `media.request`
- `media.stream`
- `call.signal`
- `call.reconnect`

### 2. Room Policy Profile

Render as structured room authorization properties.

Examples:
- allowed exact rooms
- allowed room prefixes
- room namespace mode

### 3. Rate Limit Profile

Render as numeric properties.

Examples:
- `session_pings_per_minute`
- `call_signals_per_minute`
- `media_events_per_minute`
- `attachment_transport.chunk_events_per_minute`
- `attachment_transport.chunk_bytes_per_minute`

### 4. Session Limit Profile

Render as numeric properties.

Examples:
- concurrent sessions
- room join limits
- other bounded session parameters already supported by the gateway

### 5. Attachment Transport Policy

Render as numeric and multi-value properties.

Examples:
- `max_attachment_count`
- `max_bytes_per_attachment`
- `max_total_bytes_per_message`
- allowed mime types

## UI Model

## Policy Detail Page

Keep the current read-only structured display for now, but prepare it to mirror the same sections used by the editor:
- capability profile
- room policy profile
- rate limit profile
- session limit profile

## Policy Edit Modal

Replace the current hidden-textarea strategy with:
- core policy fields at the top
  - name
  - status
  - allow/deny
- property editor below for the structured profiles

Recommended shape:
- left rail or top tabs for profile sections
- one active property-editor surface at a time
- optional read-only JSON preview panel

## Data Contract

The UI should work with normalized structured objects, not raw textarea strings.

Client-side editor data should be derived from:
- `capability_profile`
- `room_policy_profile`
- `rate_limit_profile`
- `session_limit_profile`

Submission should serialize back into the existing backend contract.

That means:
- no schema migration is required for V1
- controllers can continue persisting the same structured arrays
- only the operator-facing editing experience changes

## Validation

Validation should happen at two layers:

### Client-side
- required numeric bounds
- enum/select constraints
- empty list handling
- duplicate list entry prevention where needed

### Server-side
- keep the existing policy normalization and persistence validation
- reject unsupported or malformed values even if the UI should prevent them

## Fallback / Advanced Mode

Do not remove raw structured editing entirely in the first version.

Recommended fallback:
- hidden by default
- available as `Advanced JSON`
- gated to power users or explicit expansion

This keeps:
- forward compatibility
- debugging escape hatch
- lower risk during rollout

## Rollout Strategy

### Phase 1
- adopt Helper `ui.property.editor`
- build capability and rate-limit editors first
- keep room and session profiles read-only or minimally editable if needed

### Phase 2
- cover room policy and session limits fully
- add attachment transport policy section
- add advanced JSON fallback

### Phase 3
- remove textarea-first assumptions from policy tests and UI copy
- align project/client references to the new editor-driven structure

## Expected Outcome

Operators should be able to:
- understand what parameters exist
- tweak them without knowing JSON
- stay within supported ranges
- avoid breaking policies through syntax errors

This is the correct long-term admin shape for Realtime policy management.
