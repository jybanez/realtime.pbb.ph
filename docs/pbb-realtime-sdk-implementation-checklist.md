# PBB Realtime SDK Implementation Checklist

## Phase 1 - SDK Foundation

- [x] Create SDK package directory and baseline structure
- [x] Define transport-facing TypeScript types for envelopes, sessions, rooms, presence, chat, attachments, and calls
- [x] Extract websocket session lifecycle into a reusable core client
- [x] Expose a minimal event emitter / subscription contract
- [x] Keep the sandbox compiling against the new core client

## Phase 2 - Presence And Chat

- [x] Extract room join/leave helpers
- [x] Extract presence publish/subscribe behavior
- [x] Extract presence roster state management
- [x] Extract chat publish/receive behavior
- [x] Preserve current message normalization semantics from the sandbox

## Phase 3 - Attachment Transport

- [x] Extract sender-side attachment validation
- [x] Extract chunk transport behavior
- [x] Extract receiver-side attachment reassembly
- [x] Expose transfer progress events
- [x] Preserve current policy-aware limits

## Phase 4 - Call And Conference

- [x] Extract call signaling helpers
- [x] Extract local media helpers
- [x] Extract per-remote peer connection management
- [x] Extract remote stream map handling
- [x] Preserve current small-group mesh behavior
- [x] Keep the hard limit of 5 participants as the default conference guardrail

## Phase 5 - Sandbox Migration

- [x] Refactor the sandbox page to consume the SDK instead of inline transport logic
- [x] Keep the sandbox as the reference terminal
- [x] Keep the event log and debug surface page-local
- [x] Verify chat, attachments, and conference behavior still work through the SDK-backed sandbox

## Phase 6 - Documentation And Adoption

- [x] Write SDK integration guide for PBB product teams
- [x] Document ownership boundary: transport vs business orchestration
- [x] Publish one reference integration flow for Hotline-like terminals
- [x] Add a minimal compatibility/versioning strategy for the SDK

## Phase 7 - Optional Follow-Up

- [ ] Add framework adapters only after headless SDK stability
- [ ] Add helper-library UI bindings only after API shape is stable
- [ ] Revisit SFU abstraction only if media architecture changes beyond mesh
