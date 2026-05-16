# PBB Realtime Operations Console Checklist

## Purpose

Define the checklist for the V1 operations console surface for `PBB Realtime`.

This checklist covers the later admin surface area that supports incident handling and platform operations.

## Scope

The operations console should focus on:

- client enable/disable controls
- force disconnect controls
- emergency quarantine or revoke controls
- gateway health summary
- incident notes and operational status

It should not become:

- a broad analytics platform
- a chat moderation console
- a media server admin panel
- a secret manager

## Checklist

### Access And Safety

- [ ] Confirm the console is private and operator-only
- [ ] Confirm session-authenticated access
- [ ] Confirm role or capability checks
- [ ] Confirm actions are auditable
- [ ] Confirm destructive actions require explicit confirmation

### Client Controls

- [ ] Add client enable action
- [ ] Add client disable action
- [ ] Add client quarantine action
- [ ] Add client trust revoke or access revoke action if required
- [ ] Show the policy and trust context before actions are taken

### Session Controls

- [ ] Add force-disconnect by session
- [ ] Add force-disconnect by user
- [ ] Add force-disconnect by client
- [ ] Confirm disconnect reasons are recorded

### Health And Status

- [ ] Add gateway health summary
- [ ] Add current error or incident state display
- [ ] Add basic connection-volume indicators
- [ ] Add recent failure indicators

### Audit And Traceability

- [ ] Log all operator actions
- [ ] Log who performed the action
- [ ] Log when the action occurred
- [ ] Log the target client or session
- [ ] Log the reason or incident reference when provided

### Testing

- [ ] Test operator authentication
- [ ] Test client disable and enable
- [ ] Test quarantine and revoke actions
- [ ] Test trust revoke or access revoke actions
- [ ] Test force-disconnect actions
- [ ] Test audit logging

### Rollout Readiness

- [ ] Document when the console should be used
- [ ] Document who may use it
- [ ] Document the escalation path for incidents
- [ ] Confirm the console stays narrow and does not absorb unrelated admin work

## Exit Criteria

The operations console is ready when:

- the operator role model is clear
- the control actions are explicit
- the audit trail is reliable
- the console stays focused on incident and platform operations
