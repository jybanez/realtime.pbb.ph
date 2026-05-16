# PBB Realtime Client Management Implementation Checklist

## Purpose

Define the implementation checklist for the `PBB Realtime Client Management` surface.

This checklist is for the future `PBB Realtime` team when building the private admin surface described in:

- `docs/pbb-realtime-admin-surface-proposal.md`
- `docs/pbb-realtime-client-management-spec.md`

## Phase 0 - Contract Review

Goal: ensure the implementation starts from the agreed spec.

### Checklist

- [ ] Read `docs/pbb-realtime-admin-surface-proposal.md`
- [ ] Read `docs/pbb-realtime-client-management-spec.md`
- [ ] Confirm the admin surface is private and operator-facing
- [ ] Confirm raw signing keys and shared secrets are out of scope
- [ ] Confirm the first V1 client-management fields
- [ ] Confirm the allowed client statuses
- [ ] Confirm the admin access model

### Exit Criteria

- The implementer knows what is in scope
- The implementer knows what is out of scope
- The admin boundary is clear before code starts

## Phase 1 - Data Model

Goal: define the storage shape for client records.

### Checklist

- [ ] Create the client persistence model
- [ ] Define generated unique `client_code`
- [ ] Define `name` and `status`
- [ ] Define trust metadata fields
- [ ] Define origin policy fields
- [ ] Define legacy compatibility for any old project-root fields during transition
- [ ] Define ownership and notes fields
- [ ] Add timestamps and audit columns

### Exit Criteria

- Client records can be stored without ambiguity
- Policy references do not require duplicate data
- Trust metadata remains descriptive only

## Phase 2 - Validation And Safety

Goal: enforce the field rules before records are saved.

### Checklist

- [ ] Validate `client_code` uniqueness
- [ ] Validate required identity fields
- [ ] Validate allowed `status` values
- [ ] Validate allowed `token_issuance_mode` values
- [ ] Validate origin entries as proper origins
- [ ] Validate project-scope policy references point to valid records
- [ ] Reject any attempt to store raw keys or secrets
- [ ] Add clear validation error messages for operators

### Exit Criteria

- Invalid client records are rejected deterministically
- Secret material cannot be stored through the UI

## Phase 3 - Admin UI

Goal: give operators a clear client-management workflow.

### Checklist

- [ ] Add a client list view
- [ ] Add a client detail view
- [ ] Add a create client form
- [ ] Add an edit client form
- [ ] Add status-change actions
- [ ] Add disable and quarantine actions
- [ ] Add notes and ownership display
- [ ] Add trust-metadata display
- [ ] Add origin-policy display
- [ ] Add minimal onboarding forms that do not require code entry
- [ ] Add project-scope visibility without overloading the client form

### Exit Criteria

- An operator can review, create, and update client records
- The UI makes the trust boundary obvious

## Phase 4 - Authorization And Audit

Goal: make the admin surface safe to use in production.

### Checklist

- [ ] Require session-authenticated operator access
- [ ] Enforce role or capability checks
- [ ] Restrict access to authorized admins only
- [ ] Log create/update/status changes
- [ ] Log trust metadata changes
- [ ] Log origin-policy changes
- [ ] Log policy-reference changes
- [ ] Include actor identity and timestamps in audit entries

### Exit Criteria

- Unauthorized users cannot access the surface
- All important changes are auditable

## Phase 5 - Review Workflows

Goal: make the client lifecycle operationally useful.

### Checklist

- [ ] Support pending-to-active approval flow
- [ ] Support disablement for retired clients
- [ ] Support quarantine for incident response
- [ ] Support review notes for onboarding decisions
- [ ] Support last-reviewed metadata

### Exit Criteria

- Operators can review and control a client lifecycle without database edits

## Phase 6 - Testing

Goal: verify the admin surface behaves safely.

### Checklist

- [ ] Test valid client creation
- [ ] Test duplicate `client_code` rejection
- [ ] Test invalid origin rejection
- [ ] Test missing required field rejection
- [ ] Test status changes
- [ ] Test disable/quarantine actions
- [ ] Test project-scope policy-reference validation
- [ ] Test unauthorized access rejection
- [ ] Test audit logging for key actions

### Exit Criteria

- Core client-management behaviors are covered by tests
- The UI and backend rules match the spec

## Phase 7 - Rollout Readiness

Goal: make the admin surface ready for real operator use.

### Checklist

- [ ] Document the operator workflow
- [ ] Document the client onboarding workflow
- [ ] Document the client disable/quarantine workflow
- [ ] Document the audit and review workflow
- [ ] Confirm the surface is not exposing raw secrets
- [ ] Confirm the surface is private and access-controlled
- [ ] Confirm the client-management data model is stable enough for follow-on specs

### Exit Criteria

- The client-management surface is ready to support `PBB Realtime` onboarding work

## Suggested Working Order

1. define the data model
2. enforce validation and safety
3. build the admin UI
4. add authorization and audit
5. wire review workflows
6. test the implementation
7. prepare rollout documentation
