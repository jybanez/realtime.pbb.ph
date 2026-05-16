# PBB Realtime Proposal Feedback

## Context

This note captures initial review feedback on `docs/pbb-realtime-proposal.md` from the `PBB Realtime` team.

The proposal direction is solid: it correctly frames `PBB Realtime` as a shared platform service, keeps media transport separate from signaling, and preserves app-owned authorization and business persistence.

## Overall Assessment

The proposal is a good foundation for V1.

It clearly establishes:

- `PBB Realtime` as a standalone shared gateway
- short-lived app-issued user tokens as the preferred access model
- websocket, presence, chat, signaling, and stream/session coordination as the core scope
- a clean ownership split between gateway responsibilities and app responsibilities

That said, a few items should be tightened before implementation starts.

## Main Feedback

### 1. Normalize Terminology Across Specs

The proposal, token spec, and websocket envelope spec should use one canonical vocabulary for:

- capabilities
- message types
- auth flow naming
- room naming

Right now the docs mix terms such as:

- `presence`
- `presence.connect`
- `chat.connect`
- `chat.publish`
- `auth.connect`

That is workable for drafting, but it will create confusion during implementation unless one set of names is chosen and used consistently.

### 2. Make The Trust Model Explicit

The token spec allows either:

- app-backend signed tokens, or
- server-to-server token issuance from `PBB Realtime`

That flexibility is acceptable, but V1 should clearly define the preferred path and the fallback path.

Without that decision, key implementation details stay unresolved:

- who issues the token
- where signing keys live
- how revocation is handled
- what the gateway considers authoritative

### 3. Define Room Authorization Earlier

Room scope affects:

- presence
- chat
- signaling
- session membership

The proposal mentions typed rooms, which is good, but the room taxonomy should be standardized early.

The follow-on room spec should define:

- reserved room prefixes
- app and tenant boundaries
- prefix-based grants
- how room claims relate to capabilities

### 4. Add Reliability Semantics

The websocket envelope is clear on shape, but the behavioral contract is still thin.

V1 should define, at minimum:

- duplicate handling
- retry expectations
- idempotency expectations
- ordering guarantees
- reconnect behavior
- presence staleness / heartbeat expiry

Without those rules, app teams will implement their own assumptions and drift from one another.

### 5. Tighten Operational Scope And Security Details

The operational requirements are reasonable, but a few controls should be made more concrete:

- per-app rate limits for auth and room joins
- per-user session limits if needed
- what gets redacted from logs
- how failures are audited
- how abuse protection behaves under load

This service is a platform dependency, so its operational contract should be strict enough for safe rollout.

### 6. Separate Consumers From Coordination Partners

The proposal names several projects, but the distinction between:

- initial consumers
- integration partners
- coordination-only teams

should be explicit.

That will reduce ambiguity when scheduling adoption work.

## What I Think Is Strong

- The decision to keep media transport out of the gateway is correct.
- The split between app-owned authorization and gateway-enforced capability checks is right.
- The scope is narrow enough for a real V1 instead of a vague platform rewrite.
- The proposal sets up a clean path to a later shared frontend client wrapper.

## Suggested Next Step

Before implementation, I recommend producing the following follow-on specs in order:

1. token and auth contract
2. websocket envelope contract
3. room and presence contract
4. chat event contract
5. call signaling contract
6. project integration checklist

## Bottom Line

Proceed with the current direction, but tighten terminology, trust/issuance ownership, room authorization, and reliability semantics before code starts.

