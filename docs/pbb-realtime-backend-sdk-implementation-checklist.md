# PBB Realtime Backend SDK Implementation Checklist

## Phase 1 - Foundation

- [x] Create a plain-PHP backend SDK directory structure
- [x] Keep the first version framework-agnostic
- [x] Add a single importable entry point for consuming projects
- [x] Define minimal configuration contract:
  - issuer
  - audience
  - signing secret
  - websocket URL
  - token TTL

## Phase 2 - Claim And Token Core

- [x] Implement Realtime claim normalization helpers
- [x] Implement required-claim validation
- [x] Implement capability normalization
- [x] Implement allowed-room and allowed-prefix normalization
- [x] Implement JWT signing helper
- [x] Implement token-expiry defaults

## Phase 3 - Admission Builders

- [x] Implement chat-session admission builder
- [x] Implement presence-capable admission builder
- [x] Implement attachment-capable admission builder
- [x] Implement conference/call admission builder
- [x] Ensure all builders return a consistent frontend-facing payload:
  - token
  - websocket_url
  - app_code
  - project_code
  - room

## Phase 4 - Policy-Aware Helpers

- [x] Implement attachment policy normalization helper
- [x] Implement room normalization helper
- [x] Implement call-room derivation helper
- [x] Implement small-group mesh defaults with hard cap of 5 participants
- [ ] Ensure claim helpers only emit Realtime-supported policy structure

## Phase 5 - Product Integration Examples

- [x] Add a minimal plain-PHP sample backend endpoint
- [x] Add a Hotline-oriented backend admission example
- [x] Add commented examples for:
  - chat terminal admission
  - operator console admission
  - conference/call admission
- [x] Document which inputs must come from the product system before calling the SDK

## Phase 6 - Documentation

- [x] Write backend SDK quickstart
- [x] Write function argument reference
- [x] Write function return-value reference
- [x] Document the trust boundary between:
  - frontend SDK
  - backend SDK
  - Realtime server
- [x] Add migration guidance for projects currently hand-building tokens

## Phase 7 - Verification

- [x] Add local test coverage for claim normalization
- [x] Add local test coverage for JWT signing output
- [x] Add local test coverage for admission payload builders
- [x] Verify compatibility with current Realtime token validation rules
- [x] Verify frontend SDK can consume backend SDK admission payloads unchanged

## Phase 8 - Optional Follow-Up

- [ ] Package as Composer library only after API shape is stable
- [ ] Add optional Laravel wrapper only after framework-agnostic core is stable
- [ ] Add optional server-to-server admission validation helper only if the architecture requires it
