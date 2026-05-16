# PBB Realtime Policy And Capability Management Implementation Checklist

## Purpose

Define the implementation checklist for the `PBB Realtime Policy And Capability Management` surface.

## Phase 0 - Contract Review

### Checklist

- [ ] Read `docs/pbb-realtime-admin-surface-proposal.md`
- [ ] Read `docs/pbb-realtime-client-management-spec.md`
- [ ] Read `docs/pbb-realtime-policy-and-capability-management-spec.md`
- [ ] Confirm the policy surface is private and operator-facing
- [ ] Confirm policy records are reference-based, not secret-based
- [ ] Confirm the capability list for V1
- [ ] Confirm the room-policy shape for V1
- [ ] Confirm the admin access model

### Exit Criteria

- The implementation team knows the policy boundary and scope

## Phase 1 - Data Model

### Checklist

- [ ] Create policy record storage
- [ ] Add policy ownership by client
- [ ] Create capability profile storage
- [ ] Create room policy profile storage
- [ ] Create rate-limit profile storage
- [ ] Create session-limit profile storage
- [ ] Add status and lifecycle fields
- [ ] Generate unique policy codes automatically

### Exit Criteria

- Policies can be stored as separate client-owned reference objects

## Phase 2 - Validation And Safety

### Checklist

- [ ] Validate generated policy codes are unique
- [ ] Validate policy status values
- [ ] Validate capability names
- [ ] Validate room prefix definitions
- [ ] Validate rate-limit values
- [ ] Validate session-limit values
- [ ] Reject invalid or unsafe policy references
- [ ] Reject cross-client policy references from project scopes

### Exit Criteria

- Invalid policy definitions are rejected deterministically

## Phase 3 - Admin UI

### Checklist

- [ ] Add policy list view
- [ ] Add policy detail view
- [ ] Add create policy form in client context
- [ ] Add edit policy form in client context
- [ ] Add activate/deprecate actions
- [ ] Add project reference visibility
- [ ] Add client ownership visibility
- [ ] Add capability profile display
- [ ] Add room policy display
- [ ] Add rate-limit display
- [ ] Keep the default forms minimal and code-free
- [ ] Place policy management inside the client detail surface

### Exit Criteria

- Operators can create and review client-owned policy records

## Phase 4 - Authorization And Audit

### Checklist

- [ ] Restrict access to authorized operators
- [ ] Log policy create/update/activate/deprecate actions
- [ ] Log project reference changes
- [ ] Log capability changes
- [ ] Log room-policy changes
- [ ] Log rate-limit and session-limit changes

### Exit Criteria

- Policy changes are authorized and auditable

## Phase 5 - Testing

### Checklist

- [ ] Test valid policy creation
- [ ] Test invalid capability rejection
- [ ] Test invalid room prefix rejection
- [ ] Test invalid rate-limit rejection
- [ ] Test activation and deprecation flows
- [ ] Test unauthorized access rejection
- [ ] Test audit logging
- [ ] Test cross-client policy selection rejection

### Exit Criteria

- Core policy behaviors are covered by tests

## Phase 6 - Rollout Readiness

### Checklist

- [ ] Document policy authoring workflow
- [ ] Document policy review workflow
- [ ] Document policy activation workflow
- [ ] Document policy deprecation workflow
- [ ] Confirm policy references are used consistently by client-owned project scope management

### Exit Criteria

- The policy surface is ready to support onboarding and enforcement
